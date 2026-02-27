<?php

require_once __DIR__ . '/../ConfigManager.php';
require_once __DIR__ . '/../ChatManager.php';
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
        $this->systemPrompt = $config->getOption('ai_system_prompt', 'Ты — Твайлайт Спаркл, Принцесса Дружбы из My Little Pony. Ты просто участница чата брони-сайта. Общайся непринужденно, используй поняшный сленг. НИКОГДА не предлагай помощь. ОТВЕЧАЙ ОЧЕНЬ КРАТКО: 5-10 слов максимум, строго в одну строку. Не спамь смайлами (максимум один).');
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
            $botLogin = $botUser['login'] ?? 'Twilight';
            $botNickname = $botUser['nickname'] ?? 'Твайлайт Спаркл';
            
            // Check if bot is mentioned by login or nickname
            $isMentioned = (stripos($message, '@' . $botLogin) !== false || stripos($message, '@' . $botNickname) !== false);
            
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

            if ($isMentioned || $isQuoted) {
                $context = $this->buildContext(20);
                $response = $this->askWithFallback($context, $this->systemPrompt);
                
                if ($response && $response !== 'SILENCE') {
                    $quotedIds = isset($contextData['message_id']) && $contextData['message_id'] ? [$contextData['message_id']] : [];
                    $this->chatManager->addMessage($this->botUserId, $botNickname, $response, $quotedIds);
                    return true;
                }
            }
        } elseif ($triggerType === 'cron_spontaneous') {
            $context = $this->buildContext(30);
            $prompt = $this->systemPrompt . "\n\nПроанализируй последние сообщения. Если нужно что-то сказать (разрядить обстановку, ответить на вопрос, поддержать беседу) - напиши ответ. Если встревать не стоит - ответь ровно одним словом: SILENCE.";
            
            $response = $this->askWithFallback($context, $prompt);
            
            if ($response && trim($response) !== 'SILENCE') {
                $this->chatManager->addMessage($this->botUserId, $this->getBotUsername(), $response);
                return true;
            }
        } elseif ($triggerType === 'greeting') {
            $userLogin = $contextData['username'] ?? 'Гость';
            $context = $this->buildContext(10);
            $prompt = $this->systemPrompt . "\n\nПользователь $userLogin только что зашел на сайт. Поздоровайся с ним, обязательно упомянув его по имени (например, '@$userLogin'). Будь краткой и приветливой.";
            
            $response = $this->askWithFallback($context, $prompt);
            
            if ($response && trim($response) !== 'SILENCE') {
                $this->chatManager->addMessage($this->botUserId, $this->getBotUsername(), $response);
                return true;
            }
        }

        return false;
    }

    private function askWithFallback($context, $prompt) {
        // Добавим в промпт жесткое указание не писать метки времени и свое имя
        $prompt .= "\n\nВАЖНО: Пиши ТОЛЬКО текст своего ответа. НИКОГДА не добавляй свое имя, никнейм или время в начале сообщения (например, не пиши '[12:00] Твайлайт:').";

        $userManager = new UserManager();
        $botUser = $userManager->getUserById($this->botUserId);
        $botLogin = $botUser['login'] ?? 'Twilight';
        $botNickname = $botUser['nickname'] ?? 'Твайлайт Спаркл';

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
                    // На всякий случай удаляем еще раз, если было вложенное
                    $response = preg_replace('/^\[\d{2}:\d{2}\]\s*[^:]+:\s*/iu', '', $response);

                    return trim($response);
                }
            } catch (Exception $e) {
                error_log("LLM Provider Error (" . get_class($provider) . "): " . $e->getMessage());
                continue; // Try next provider
            }
        }
        return null;
    }

    private function buildContext($limit = 20) {
        // Fetch last N messages
        $messages = $this->chatManager->getMessages($limit);
        $context = [];

        foreach ($messages as $msg) {
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
        return $user['nickname'] ?? $user['login'] ?? 'Twilight';
    }

    private function ensureBotUserExists() {
        $userManager = new UserManager();
        $user = $userManager->getUserById($this->botUserId);
        
        if (!$user) {
            // Create Twilight if she doesn't exist
            $randomPass = bin2hex(random_bytes(16));
            $newId = $userManager->createUser('Twilight', $randomPass, 'user', 'Твайлайт Спаркл');
            
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
