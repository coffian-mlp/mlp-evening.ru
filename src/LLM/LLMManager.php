<?php

require_once __DIR__ . '/../ConfigManager.php';
require_once __DIR__ . '/../ChatManager.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../UserManager.php';
require_once __DIR__ . '/OpenRouterProvider.php';
require_once __DIR__ . '/OpenAIProvider.php';
require_once __DIR__ . '/YandexGPTProvider.php';
require_once __DIR__ . '/GigaChatProvider.php';
require_once __DIR__ . '/XrayManager.php';

class LLMManager {
    private $providers = [];
    private $botUserId;
    private $systemPrompt;
    private $chatManager;
    private $proxyUrl;
    private $vlessLink;

    public function __construct() {
        $config = ConfigManager::getInstance();
        $this->botUserId = (int)$config->getOption('ai_bot_user_id', 0);
        $this->systemPrompt = $config->getOption('ai_system_prompt', ''); // Промпт теперь берем только из админки (БД), без длинного дефолта в коде
        $this->chatManager = new ChatManager();
        
        $this->proxyUrl = $config->getOption('ai_proxy_url', null); // Может быть как socks5://..., так и vless://...
        if ($this->proxyUrl && strpos($this->proxyUrl, 'socks5://') === 0) {
            $this->proxyUrl = str_replace('socks5://', 'socks5h://', $this->proxyUrl);
        }
        $this->vlessLink = null;
        
        // Если прокси - это vless ссылка, мы подготовим XrayManager, а провайдерам дадим локальный порт
        if ($this->proxyUrl && strpos($this->proxyUrl, 'vless://') === 0) {
            $this->vlessLink = $this->proxyUrl;
            $this->proxyUrl = 'socks5h://127.0.0.1:10808'; // Локальный адрес Xray (socks5h для удаленного DNS)
        }

        // В зависимости от выбранного провайдера собираем список
        $primary = $config->getOption('ai_primary_provider', 'openai');
        
        $openAiKey = $config->getOption('ai_openai_key', '');
        $openAiBaseUrl = $config->getOption('ai_openai_base_url', 'https://api.openai.com/v1/chat/completions');
        $openAiModel = $config->getOption('ai_openai_model', 'gpt-4o-mini');
        $openaiProvider = $openAiKey ? new OpenAIProvider($openAiKey, $openAiModel, $openAiBaseUrl, $this->proxyUrl) : null;

        $openRouterKey = $config->getOption('ai_openrouter_key', '');
        $openRouterModel = $config->getOption('ai_openrouter_model', 'qwen/qwen3-coder:free');
        $openRouterProvider = $openRouterKey ? new OpenRouterProvider($openRouterKey, $openRouterModel, $this->proxyUrl) : null;

        $yandexKey = $config->getOption('ai_yandex_key', '');
        $yandexFolderId = $config->getOption('ai_yandex_folder_id', '');
        $yandexProvider = ($yandexKey && $yandexFolderId) ? new YandexGPTProvider($yandexKey, $yandexFolderId) : null;

        $gigachatKey = $config->getOption('ai_gigachat_key', '');
        $gigachatProvider = $gigachatKey ? new GigaChatProvider($gigachatKey) : null;

        $allProviders = [
            'openai' => $openaiProvider,
            'openrouter' => $openRouterProvider,
            'yandex' => $yandexProvider,
            'gigachat' => $gigachatProvider
        ];

        // Сначала добавляем основного, если он настроен
        if (isset($allProviders[$primary]) && $allProviders[$primary] !== null) {
            $this->providers[] = $allProviders[$primary];
        }

        // Затем добавляем остальных как фоллбек (за исключением уже добавленного)
        foreach ($allProviders as $key => $provider) {
            if ($provider !== null && $key !== $primary) {
                $this->providers[] = $provider;
            }
        }
    }

    public function isEnabled() {
        $config = ConfigManager::getInstance();
        return (bool)$config->getOption('ai_enabled', 0) && $this->botUserId > 0 && !empty($this->providers);
    }

    public function processTrigger($triggerType, $contextData = []) {
        if (!$this->isEnabled()) return false;

        $this->ensureBotUserExists();

        // Проверяем/Поднимаем Xray перед запросами, если нужна vless-магия
        if ($this->vlessLink) {
            $xray = new XrayManager();
            if (!$xray->ensureRunning($this->vlessLink)) {
                error_log("LLMManager Warning: VLESS proxy failed to start or port is closed. The providers might fail and fallback to Yandex.");
                // Мы не прерываем работу, пусть фоллбек (Яндекс/Сбер) отработает, если OpenAI отвалится по тайм-ауту.
            }
        }

        if ($triggerType === 'mention') {
            $message = $contextData['message'] ?? '';
            $userManager = new UserManager();
            $botUser = $userManager->getUserById($this->botUserId);
            $botLogin = $botUser['login'] ?? 'Lyra';
            $botNickname = $botUser['nickname'] ?? 'Лира Хартстрингс';
            
            // Check if bot is mentioned by login or nickname
            $isExplicitMention = false;
            $isMentionedByAlias = false;
            
            if (mb_stripos($message, '@' . $botLogin, 0, 'UTF-8') !== false || mb_stripos($message, '@' . $botNickname, 0, 'UTF-8') !== false) {
                $isExplicitMention = true;
            } else {
                // Дополнительные алиасы (без @), на которые реагирует бот
                $config = ConfigManager::getInstance();
                $aliasesStr = $config->getOption('ai_aliases', 'лира, lyra, хартстрингс, lyra heartstrings, лирочка');
                $aliases = array_map('trim', explode(',', $aliasesStr));
                
                foreach ($aliases as $alias) {
                    if (empty($alias)) continue;
                    // Используем \p{L} для корректной работы с кириллицей, так как \b может сбоить
                    if (preg_match('/(^|[^\p{L}])' . preg_quote($alias, '/') . '([^\p{L}]|$)/iu', $message)) {
                        $isMentionedByAlias = true;
                        break;
                    }
                }
            }
            
            // Check if any of the quoted messages belong to the bot
            $isQuoted = false;
            $quotedMsgIds = $contextData['quoted_msg_ids'] ?? [];
            if (!empty($quotedMsgIds) && is_array($quotedMsgIds)) {
                foreach ($quotedMsgIds as $qId) {
                    $qMsg = $this->chatManager->getMessageById($qId);
                    if ($qMsg && $qMsg['user_id'] == $this->botUserId) {
                        $isQuoted = true;
                        break;
                    }
                }
            }

            if ($isExplicitMention || $isMentionedByAlias || $isQuoted) {
                // Если это только неявный алиас (не прямое упоминание и не ответ боту), применяем жесткую защиту от спама
                if ($isMentionedByAlias && !$isExplicitMention && !$isQuoted) {
                    $db = Database::getInstance()->getConnection();
                    
                    // 1. Проверяем, не было ли самое последнее сообщение в чате от самого бота
                    $stmtLast = $db->prepare("SELECT user_id FROM chat_messages ORDER BY id DESC LIMIT 1");
                    $stmtLast->execute();
                    $resLast = $stmtLast->get_result();
                    if ($rowLast = $resLast->fetch_assoc()) {
                        if ($rowLast['user_id'] == $this->botUserId) {
                            return false; // Бот только что писал (или это его последнее сообщение), не реагируем на алиасы
                        }
                    }
                    
                    // 2. Проверяем время последнего сообщения от бота для rate-лимитов
                    $stmtBot = $db->prepare("SELECT created_at FROM chat_messages WHERE user_id = ? ORDER BY id DESC LIMIT 1");
                    $stmtBot->bind_param("i", $this->botUserId);
                    $stmtBot->execute();
                    $resBot = $stmtBot->get_result();
                    
                    if ($rowBot = $resBot->fetch_assoc()) {
                        $lastBotTime = strtotime($rowBot['created_at'] . ' UTC');
                        $timeDiff = time() - $lastBotTime;
                        
                        if ($timeDiff <= 30) {
                            // Меньше 30 секунд -> не отвечаем вообще (защита от спама)
                            return false;
                        } elseif ($timeDiff <= 120) {
                            // От 30 секунд до 2 минут -> 50% шанс ответить
                            if (rand(1, 2) !== 2) {
                                return false; 
                            }
                        }
                        // Больше 2 минут -> 100% ответ
                    }
                }

                $context = $this->buildContext(20);
                $response = $this->askWithFallback($context, $this->systemPrompt);
                
                $isSilence = preg_match('/^[^a-zа-яё0-9]*silence[^a-zа-яё0-9]*$/iu', trim($response ?? ''));
                
                if ($response && !$isSilence) {
                    $quotedIds = isset($contextData['message_id']) && $contextData['message_id'] ? [$contextData['message_id']] : [];
                    $this->chatManager->addMessage($this->botUserId, $botNickname, $response, $quotedIds);
                    return true;
                }
            }
        } elseif ($triggerType === 'cron_spontaneous') {
            // Get last 30 messages, but only if they were posted within the last 3 hours
            $context = $this->buildContext(30, 3);
            
            // If the chat has been dead for 3 hours (empty context), don't even ask the LLM
            if (empty($context)) {
                return false;
            }
            
            // If the last message in the chat is from the bot itself, don't talk to yourself
            $lastMsg = end($context);
            if ($lastMsg && $lastMsg['role'] === 'assistant') {
                return false;
            }

            $prompt = $this->systemPrompt . "\n\nПроанализируй последние сообщения. Если нужно что-то сказать (разрядить обстановку, ответить на вопрос, поддержать беседу) - напиши ответ. Если встревать не стоит - ответь ровно одним словом: SILENCE, но не нужно вообще молчать постоянно. Старайся поддерживать беседу в чате, даже если к тебе явно никто не обращается - это нормально.";
            
            $response = $this->askWithFallback($context, $prompt);
            
            $isSilence = preg_match('/^[^a-zа-яё0-9]*silence[^a-zа-яё0-9]*$/iu', trim($response ?? ''));
            
            if ($response && !$isSilence) {
                $this->chatManager->addMessage($this->botUserId, $this->getBotUsername(), $response);
                return true;
            }
        } elseif ($triggerType === 'greeting') {
            $userLogin = $contextData['username'] ?? 'Гость';
            $context = $this->buildContext(10);
            $prompt = $this->systemPrompt . "\n\nПользователь $userLogin только что зашел на сайт. Поздоровайся с ним, обязательно упомянув его по имени (например, '@$userLogin'). Будь краткой и приветливой.";
            
            $response = $this->askWithFallback($context, $prompt);
            
            $isSilence = preg_match('/^[^a-zа-яё0-9]*silence[^a-zа-яё0-9]*$/iu', trim($response ?? ''));
            
            if ($response && !$isSilence) {
                $this->chatManager->addMessage($this->botUserId, $this->getBotUsername(), $response);
                return true;
            }
        }

        return false;
    }

    private function askWithFallback($context, $prompt) {
        $userManager = new UserManager();
        $botUser = $userManager->getUserById($this->botUserId);
        $botLogin = $botUser['login'] ?? 'Lyra';
        $botNickname = $botUser['nickname'] ?? 'Лира Хартстрингс';

        // Добавим в промпт жесткое указание не писать метки времени и свое имя
        $prompt .= "\n\nВАЖНО: Пиши ТОЛЬКО текст своего ответа. НИКОГДА не добавляй свое имя, никнейм или время в начале сообщения (например, не пиши '[12:00] {$botNickname}:').";

        foreach ($this->providers as $provider) {
            try {
                $response = $provider->askChat($context, $prompt);
                if ($response) {
                    // Очистка ответа от случайно сгенерированных временных меток и имен
                    $response = trim($response);
                    
                    // Удаляем `[12:34] Имя:` 
                    $response = preg_replace('/^\[\d{2}:\d{2}\]\s*[^:]+:\s*/iu', '', $response);
                    // Удаляем `Имя:`
                    $response = preg_replace('/^' . preg_quote($botNickname, '/') . ':\s*/iu', '', $response);
                    $response = preg_replace('/^' . preg_quote($botLogin, '/') . ':\s*/iu', '', $response);
                    
                    // Удаляем по первому слову никнейма (например "Лира: " если ник "Лира Хартстрингс")
                    $nicknameParts = explode(' ', $botNickname);
                    if (!empty($nicknameParts[0])) {
                        $response = preg_replace('/^' . preg_quote($nicknameParts[0], '/') . ':\s*/iu', '', $response);
                    }
                    
                    // На всякий случай удаляем еще раз, если было вложенное
                    $response = preg_replace('/^\[\d{2}:\d{2}\]\s*[^:]+:\s*/iu', '', $response);

                    // Если вдруг LLM добавила кавычки в начале и конце
                    $response = preg_replace('/^"(.*)"$/us', '$1', trim($response));

                    // Нормализация: LLM иногда возвращает HTML-сущности (&quot;, &#34; и т.д.).
                    // Декодируем их в обычные символы; при сохранении ChatManager снова сделает
                    // htmlspecialchars — тогда в чате отобразятся нормальные кавычки, а не буквально &quot;
                    $response = html_entity_decode($response, ENT_QUOTES | ENT_HTML5, 'UTF-8');

                    return trim($response);
                }
            } catch (Exception $e) {
                error_log("LLM Provider Error (" . get_class($provider) . "): " . $e->getMessage());
                continue; // Try next provider
            }
        }
        return null;
    }

    private function buildContext($limit = 20, $maxAgeHours = null) {
        // Fetch last N messages
        $messages = $this->chatManager->getMessages($limit);
        $context = [];

        $currentTime = time();

        foreach ($messages as $msg) {
            // Check message age if required
            if ($maxAgeHours !== null) {
                $msgTime = strtotime($msg['created_at'] . ' UTC');
                $hoursDiff = ($currentTime - $msgTime) / 3600;
                if ($hoursDiff > $maxAgeHours) {
                    continue; // Skip messages older than maxAgeHours
                }
            }

            $role = ($msg['user_id'] == $this->botUserId) ? 'assistant' : 'user';
            $time = date('H:i', strtotime($msg['created_at']));
            $username = $msg['username'];
            
            // We format the content to include the username and time so the model knows who is speaking
            $content = "[$time] $username: " . $msg['raw_message'];
            
            $context[] = [
                'role' => $role,
                'content' => $content
            ];
        }

        return $context;
    }

    private function getBotUsername() {
        $userManager = new UserManager();
        $user = $userManager->getUserById($this->botUserId);
        return $user['nickname'] ?? $user['login'] ?? 'Lyra';
    }

    private function ensureBotUserExists() {
        $userManager = new UserManager();
        $user = $userManager->getUserById($this->botUserId);
        
        if (!$user) {
            // Create Lyra if she doesn't exist
            $randomPass = bin2hex(random_bytes(16));
            $newId = $userManager->createUser('Lyra', $randomPass, 'user', 'Лира Хартстрингс');
            
            if ($newId) {
                $userManager->updateUser($newId, [
                    'chat_color' => '#9b59b6',
                    'avatar_url' => 'https://i.imgur.com/K12X8rO.png'
                ]);
                $this->botUserId = $newId;
                ConfigManager::getInstance()->setOption('ai_bot_user_id', $newId);
            }
        }
    }
}
