<?php

namespace LLM;

use Domain\EpisodeManager;
use Domain\PollManager;

use Domain\ChatManager;
use Infra\ConfigManager;
use Infra\Database;
use Domain\EventManager;
use Exception;
use Domain\UserManager;


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

        $routerAiKey = $config->getOption('ai_routerai_key', '');
        $routerAiModel = $config->getOption('ai_routerai_model', 'openai/gpt-4o-mini');
        $routerAiProvider = $routerAiKey ? new RouterAIProvider($routerAiKey, $routerAiModel, $this->proxyUrl) : null;

        $yandexKey = $config->getOption('ai_yandex_key', '');
        $yandexFolderId = $config->getOption('ai_yandex_folder_id', '');
        $yandexProvider = ($yandexKey && $yandexFolderId) ? new YandexGPTProvider($yandexKey, $yandexFolderId) : null;

        $gigachatKey = $config->getOption('ai_gigachat_key', '');
        $gigachatProvider = $gigachatKey ? new GigaChatProvider($gigachatKey) : null;

        $allProviders = [
            'openai' => $openaiProvider,
            'openrouter' => $openRouterProvider,
            'routerai' => $routerAiProvider,
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

        // A3 (MLP-228): схема/seed bot_commands больше НЕ создаются здесь на каждый
        // вызов — они в migrations/2026_07_bot_commands.sql, владелец — BotCommandManager.
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

                $context = $this->buildContext($this->contextLimit());
                $response = $this->askWithFallback($context, $this->systemPrompt);
                
                $isSilence = preg_match('/^[^a-zа-яё0-9]*silence[^a-zа-яё0-9]*$/iu', trim($response ?? ''));
                
                if ($response && !$isSilence) {
                    $quotedIds = isset($contextData['message_id']) && $contextData['message_id'] ? [$contextData['message_id']] : [];
                    $this->chatManager->addMessage($this->botUserId, $botNickname, $response, $quotedIds);
                    return true;
                }
            }
        } elseif ($triggerType === 'cron_spontaneous') {
            // MLP-260: гейт «не говорить с самим собой» — по БД, а не по end($context):
            // проверку по контексту ломал закреп (он подмешивался и в тишине).
            if ($this->chatManager->getLastMessageAuthorId() === $this->botUserId) {
                return false;
            }

            // Контекст БЕЗ закрепа: «мёртвый чат» оцениваем по реальным сообщениям —
            // одинокий закреп раньше пробивал обе защиты и бот болтал о нём в пустоту.
            $context = $this->buildContext($this->contextLimit(), 3, null, false);

            // If the chat has been dead for 3 hours (empty context), don't even ask the LLM
            if (empty($context)) {
                return false;
            }

            // Закреп — только после гейтов, как фон
            $context = $this->prependPinnedContext($context);

            $instruction = "Проанализируй последние сообщения. Если нужно что-то сказать (разрядить обстановку, ответить на вопрос, поддержать беседу) - напиши ответ. Если встревать не стоит - ответь ровно одним словом: SILENCE, но не нужно вообще молчать постоянно. Старайся поддерживать беседу в чате, даже если к тебе явно никто не обращается - это нормально.";
            // Инструкция как обычная реплика без префикса "[Система]" — префикс модель иногда выдавала эхом.
            $context[] = [
                'role' => 'user',
                'content' => $instruction
            ];

            $response = $this->askWithFallback($context, $this->systemPrompt);
            
            $isSilence = preg_match('/^[^a-zа-яё0-9]*silence[^a-zа-яё0-9]*$/iu', trim($response ?? ''));
            
            if ($response && !$isSilence) {
                $this->chatManager->addMessage($this->botUserId, $this->getBotUsername(), $response);
                return true;
            }
        } elseif ($triggerType === 'greeting') {
            $userLogin = $contextData['username'] ?? 'Гость';
            $context = $this->buildContext($this->contextLimit());
            
            $instruction = "Пользователь $userLogin только что зашел на сайт. Поздоровайся с ним, обязательно упомянув его по имени (например, '@$userLogin'). Будь краткой и приветливой.";
            // Инструкция как обычная реплика без префикса "[Система]" — префикс модель иногда выдавала эхом.
            $context[] = [
                'role' => 'user',
                'content' => $instruction
            ];

            $response = $this->askWithFallback($context, $this->systemPrompt);
            
            $isSilence = preg_match('/^[^a-zа-яё0-9]*silence[^a-zа-яё0-9]*$/iu', trim($response ?? ''));
            
            if ($response && !$isSilence) {
                $this->chatManager->addMessage($this->botUserId, $this->getBotUsername(), $response);
                return true;
            }
        } elseif ($triggerType === 'dynamic_command') {
            $command = $contextData['command'] ?? ['handler_type' => 'text', 'system_prompt' => ''];

            // Опрос: бот генерирует вопрос+варианты и создаёт опрос (MLP-240). Отдельный путь —
            // вывод структурированный (не обычная реплика), поэтому не идём в общий постинг ниже.
            if ($command['handler_type'] === 'poll') {
                return $this->createPollFromCommand($command, $contextData);
            }

            $context = $this->buildContext($this->contextLimit());

            if ($command['handler_type'] === 'schedule') {
                $now = time();
                // AR3-1: recurrence-раскрытие — единая точка в EventManager.
                $em = new EventManager();
                $expandedEvents = EventManager::expandOccurrences($em->getAllRaw(), 7, $now);

                $event = null;
                foreach ($expandedEvents as $evt) {
                    $endTime = $evt['real_start_time'] + ($evt['duration_minutes'] * 60);
                    if ($endTime > $now) {
                        $event = $evt;
                        break;
                    }
                }
                
                $additionalPrompt = "\n\n" . ($command['system_prompt'] ?: "Системное сообщение (расписание): ответь про ближайшее событие.");
                
                if ($event) {
                    $dt = new \DateTime();
                    $dt->setTimestamp($event['real_start_time']);
                    $dt->setTimezone(new \DateTimeZone('Europe/Moscow'));
                    $mskTime = $dt->format('Y-m-d H:i');
                    
                    $additionalPrompt .= "\n\nДАННЫЕ ДЛЯ ОТВЕТА (ты ДОЛЖНА обязательно упомянуть это событие, игнорируй свои прошлые ответы, если они противоречат этим данным):\n";
                    $additionalPrompt .= "- Ближайшее событие: '{$event['title']}'\n";
                    $additionalPrompt .= "- Время: {$mskTime} (по московскому времени)\n";
                    $additionalPrompt .= "- Описание: {$event['description']}\n";
                    
                    if ($event['use_playlist'] || $event['generate_new_playlist']) {
                        $epManager = new EpisodeManager();
                        $playlist = $epManager->getSavedPlaylist();
                        if (!empty($playlist)) {
                            $additionalPrompt .= "- Плейлист серий: ";
                            foreach ($playlist as $key => $story) {
                                if ($key === '_meta') continue;
                                if (is_array($story) && isset($story['titles'])) {
                                    foreach ($story['titles'] as $title) {
                                        $additionalPrompt .= "{$title}, ";
                                    }
                                }
                            }
                            $additionalPrompt .= "\n";
                        }
                    }
                    $additionalPrompt .= "\nТВОЯ ЗАДАЧА: Напиши красивый ответ на основе этих данных (и системного промпта). Обязательно упомяни событие, время (в МСК) и кратко перескажи описание.";
                } else {
                    $additionalPrompt .= "\n\nДАННЫЕ ДЛЯ ОТВЕТА: В данный момент расписание абсолютно пусто. Запланированных событий нет.\n"
                        . "ТВОЯ ЗАДАЧА: Напиши ответ об отсутствии ближайших событий.";
                }
            } else {
                // Обычный текстовый обработчик
                $additionalPrompt = "\n\n" . ($command['system_prompt'] ?: "Тебя вызвали с помощью специальной команды. Ответь коротко и в тему.");
            }
            
            $systemInstruction = !empty($contextData['message']) ? $contextData['message'] : "запрос команды";
            $context[] = [
                'role' => 'user',
                'content' => "[Система] Пользователь запрашивает: " . $systemInstruction . "\n" . $additionalPrompt
            ];
            
            $prompt = $this->systemPrompt;
            $response = $this->askWithFallback($context, $prompt);
            
            $isSilence = preg_match('/^[^a-zа-яё0-9]*silence[^a-zа-яё0-9]*$/iu', trim($response ?? ''));
            
            if ($response && !$isSilence) {
                $this->chatManager->addMessage($this->botUserId, $this->getBotUsername(), $response);
                return true;
            }
        }

        return false;
    }

    /**
     * Бот генерирует и создаёт опрос по команде (MLP-240). Возвращает true при успехе.
     * Просит модель выдать вопрос + варианты построчно, парсит и создаёт через PollManager,
     * затем постит карточку [[poll:id]] в чат от имени бота (появляется через realtime чата).
     */
    private function createPollFromCommand($command, $contextData): bool {
        $message = (string)($contextData['message'] ?? '');

        // ОСНОВНОЙ режим: пользователь задал опрос явно —
        // «/опрос Кто лучшая пони? Варианты: я, Лира, Твайлайт, ...».
        $spec = self::parseUserPollSpec($message);
        if ($spec) {
            $question = $spec['question'];
            $options  = $spec['options'];
        } else {
            // Фолбэк: варианты не заданы — Лира придумывает опрос сама.
            $gen = $this->generatePoll($command, $message);
            if (!$gen) {
                error_log('createPollFromCommand: варианты не заданы и модель не сгенерировала опрос');
                return false;
            }
            $question = $gen['question'];
            $options  = $gen['options'];
        }

        $pm = new PollManager();
        $pollId = $pm->create($this->botUserId, $question, $options, false, false);
        if (!$pollId) return false;

        $msgId = $this->chatManager->addMessage($this->botUserId, $this->getBotUsername(), '[[poll:' . $pollId . ']]');
        if ($msgId) $pm->attachMessage($pollId, (int)$msgId);
        return true;
    }

    /**
     * Pure: разобрать явную заявку на опрос из сообщения пользователя.
     * Формат: «[/опрос] Вопрос? Варианты: a, b, c» (разделитель «варианты[:/-]»,
     * варианты — через запятую/перенос строки/;). Возвращает null, если явной заявки нет.
     */
    public static function parseUserPollSpec(string $message): ?array {
        // Срезаем ведущую команду (/опрос, /poll — со слэшем или без).
        $s = preg_replace('/^\s*\/?(?:опрос|poll)\b\s*/iu', '', trim($message));
        // Разделитель «Варианты:» / «Варианты -» и т.п.
        if (!preg_match('/^(.*?)\s*вариант[а-яё]*\s*[:\-—]\s*(.+)$/isu', $s, $m)) {
            return null;
        }
        $question = trim($m[1]);
        $options = [];
        foreach (preg_split('/[,\n;]+/u', $m[2]) as $p) {
            $p = trim($p);
            if ($p !== '') $options[] = $p;
        }
        if ($question === '' || count($options) < 2) return null;
        return ['question' => $question, 'options' => array_slice($options, 0, 10)];
    }

    /** Фолбэк: Лира сама генерирует опрос (когда варианты не заданы). */
    private function generatePoll($command, string $topic): ?array {
        $topic = trim($topic);
        $context = $this->buildContext(16);
        $instruction = "\n\n" . ($command['system_prompt'] ?: "Придумай уместный опрос для чата.")
            . "\n\nТЫ СОЗДАЁШЬ ОПРОС."
            . ($topic !== '' ? "\nЗапрос пользователя: " . $topic : "\nТема не задана — придумай уместную по последним сообщениям чата.")
            . "\n\nФОРМАТ ОТВЕТА СТРОГО (без markdown, без нумерации, без пояснений):\n"
            . "Первая строка — вопрос опроса.\n"
            . "Каждая следующая строка — один вариант ответа. Ровно 2–5 вариантов, по одному на строку.";
        $context[] = [
            'role' => 'user',
            'content' => "[Система] Сгенерируй опрос строго в заданном формате." . $instruction,
        ];
        return self::parsePoll($this->askWithFallback($context, $this->systemPrompt));
    }

    /**
     * Бот голосует в опросе и пишет реплику (MLP-241). Выбор и текст — на модели.
     * Анонимные опросы: модели явно велено НЕ раскрывать выбор. Возвращает true, если проголосовал.
     */
    public function voteOnPoll(array $poll): bool {
        if (empty($poll['options'])) return false;
        $pm = new PollManager();

        $numbered = [];
        foreach ($poll['options'] as $i => $opt) {
            // Варианты бывают только с картинкой (без текста) — не показываем модели пустоту.
            $label = trim((string)($opt['text'] ?? ''));
            if ($label === '') $label = !empty($opt['image_url']) ? '(вариант с картинкой)' : '(без названия)';
            $numbered[] = ($i + 1) . '. ' . $label;
        }
        $anon = !empty($poll['is_anonymous']);

        $context = $this->buildContext(12);
        $context[] = ['role' => 'user', 'content' =>
            "[Система] В чате идёт опрос — проголосуй и отреагируй в своём стиле.\n"
            . "Вопрос: " . $poll['question'] . "\n"
            . "Варианты:\n" . implode("\n", $numbered) . "\n\n"
            . "ФОРМАТ ОТВЕТА СТРОГО:\n"
            . "1) Первая строка — ТОЛЬКО номер выбранного варианта (одна цифра).\n"
            . "2) Со второй строки — короткая живая реплика в чат про опрос и свои размышления.\n"
            . ($anon
                ? "ВАЖНО: опрос АНОНИМНЫЙ. В реплике НЕ раскрывай, какой вариант выбрала — пиши уклончиво (например, «свой голос уже тихонько оставила»).\n"
                : "В реплике можешь упомянуть, за что голосуешь.\n")
            . "Примеры тона (не копируй дословно): «О, голосование! Дайте-ка подумать...», "
            . "«Так, мнение мятной пони тоже важно!», «Ого, тут выбирают — не могу пройти мимо!», "
            . "«Голосовалочка! Обожаю такие штуки.»"
        ];

        $raw = $this->askWithFallback($context, $this->systemPrompt);
        $parsed = self::parseBotVote($raw, count($poll['options']));

        $idx = $parsed['index'];
        if ($idx === null) $idx = rand(0, count($poll['options']) - 1); // модель не дала чёткий номер — выбираем сами, но участвуем

        $optionId = (int)$poll['options'][$idx]['id'];
        $pm->vote((int)$poll['id'], $this->botUserId, [$optionId]);

        if ($parsed['comment'] !== '') {
            $this->chatManager->addMessage($this->botUserId, $this->getBotUsername(), $parsed['comment']);
        }
        return true;
    }

    /**
     * Pure: разобрать ответ бота о голосовании. Строка-«только число» (1..N) — выбор варианта
     * (индекс 0-based), остальные строки — реплика. index=null, если чёткого числа нет.
     */
    public static function parseBotVote(?string $raw, int $optionCount): array {
        $index = null;
        $comment = [];
        foreach (explode("\n", (string)$raw) as $line) {
            $t = trim($line);
            if ($t === '') continue;
            if ($index === null && preg_match('/^(\d+)[\.\)]?$/u', $t, $m)) {
                $n = (int)$m[1];
                if ($n >= 1 && $n <= $optionCount) { $index = $n - 1; continue; }
            }
            $comment[] = $t;
        }
        return ['index' => $index, 'comment' => trim(implode("\n", $comment))];
    }

    /**
     * Pure: разобрать ответ модели в опрос. Первая непустая строка — вопрос,
     * остальные — варианты (чистим возможную нумерацию/маркеры), 2–10 штук.
     * null, если вопроса + минимум 2 вариантов не набралось.
     */
    public static function parsePoll(?string $raw): ?array {
        if (!$raw) return null;
        $lines = [];
        foreach (explode("\n", $raw) as $l) {
            $l = trim($l);
            if ($l !== '') $lines[] = $l;
        }
        if (count($lines) < 3) return null; // вопрос + минимум 2 варианта
        $question = array_shift($lines);
        $options = array_map(function ($l) {
            return trim(preg_replace('/^\s*(?:\d+[\.\)]|[-*•])\s*/u', '', $l));
        }, $lines);
        $options = array_values(array_filter($options, fn($o) => $o !== ''));
        $options = array_slice($options, 0, 10);
        if (count($options) < 2) return null;
        return ['question' => $question, 'options' => $options];
    }

    private function askWithFallback($context, $prompt) {
        $userManager = new UserManager();
        $botUser = $userManager->getUserById($this->botUserId);
        $botLogin = $botUser['login'] ?? 'Lyra';
        $botNickname = $botUser['nickname'] ?? 'Лира Хартстрингс';

        // Жёсткое указание — ТОЛЬКО в системную роль, а НЕ в реплику диалога.
        // Раньше оно дописывалось к последнему сообщению контекста, из-за чего модель
        // периодически выдавала саму инструкцию эхом прямо в чат («прорыв системщины»).
        $prompt .= "\n\n[Системное правило]: Пиши ТОЛЬКО текст своего ответа. НИКОГДА не добавляй своё имя, никнейм, время или служебные пометки в начале сообщения (например, не пиши '[12:00] {$botNickname}:').";

        foreach ($this->providers as $provider) {
            try {
                $response = $provider->askChat($context, $prompt);

                // Единая очистка + выходной guard против прорыва системных инструкций/контекста.
                $clean = ResponseSanitizer::clean($response, $botNickname, $botLogin);

                if ($clean !== null && $clean !== '') {
                    return $clean;
                }
                // Пусто после очистки: либо провайдер промолчал, либо это был чистый
                // прорыв системного текста, вырезанный целиком. В чат не постим — лучше
                // тишина, чем «системщина». Пробуем следующего провайдера.
            } catch (Exception $e) {
                error_log("LLM Provider Error (" . get_class($provider) . "): " . $e->getMessage());
                continue; // Try next provider
            }
        }
        return null;
    }

    // --- Публичный API для BotWorker (очередь). Аддитивно, не меняет существующие пути. ---

    public function getBotUserId(): int {
        return $this->botUserId;
    }

    public function getBotNickname(): string {
        $user = (new UserManager())->getUserById($this->botUserId);
        return $user['nickname'] ?? $user['login'] ?? 'Lyra';
    }

    public function getChatManager(): ChatManager {
        return $this->chatManager;
    }

    /**
     * Обратились ли к боту в сообщении: явное @упоминание, алиас (лира/lyra/…) или цитата его сообщения.
     * Та же логика, что была внутри processTrigger('mention') — теперь применяется на этапе постановки
     * в очередь, чтобы бот НЕ отвечал на сообщения, где его не звали.
     */
    public function messageAddressesBot(string $message, array $quotedMsgIds = []): bool {
        $userManager = new UserManager();
        $botUser = $userManager->getUserById($this->botUserId);
        $botLogin = $botUser['login'] ?? 'Lyra';
        $botNickname = $botUser['nickname'] ?? 'Лира Хартстрингс';

        // Явное упоминание по логину/никнейму
        if (mb_stripos($message, '@' . $botLogin, 0, 'UTF-8') !== false
            || mb_stripos($message, '@' . $botNickname, 0, 'UTF-8') !== false) {
            return true;
        }
        // Алиасы (без @)
        $aliasesStr = ConfigManager::getInstance()->getOption('ai_aliases', 'лира, lyra, хартстрингс, lyra heartstrings, лирочка');
        foreach (array_map('trim', explode(',', $aliasesStr)) as $alias) {
            if ($alias === '') continue;
            if (preg_match('/(^|[^\p{L}])' . preg_quote($alias, '/') . '([^\p{L}]|$)/iu', $message)) {
                return true;
            }
        }
        // Цитата сообщения бота
        if (!empty($quotedMsgIds) && is_array($quotedMsgIds)) {
            foreach ($quotedMsgIds as $qId) {
                $qMsg = $this->chatManager->getMessageById($qId);
                if ($qMsg && $qMsg['user_id'] == $this->botUserId) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Контекст беседы для воркера. $beforeId ограничивает контекст сообщениями с id < $beforeId
     * (для single-ответа — контекст ДО триггера включительно: передавать message_id + 1).
     */
    public function buildReplyContext(int $limit = 24, $maxAgeHours = null, ?int $beforeId = null): array {
        return $this->buildContext($limit, $maxAgeHours, $beforeId);
    }

    /**
     * Сгенерировать текст ответа БЕЗ постинга (постит воркер — он решает цитату по политике).
     * $extraInstruction — доп. указание режима (адресация/сводность) из ReplyPolicy::instruction().
     */
    public function generateReply(array $context, string $extraInstruction = ''): ?string {
        $prompt = $this->systemPrompt;
        if ($extraInstruction !== '') {
            $prompt .= "\n\n" . $extraInstruction;
        }
        if (ConfigManager::getInstance()->getOption('ai_reactions', 1)) {
            $prompt .= "\n\n[Реакции]: можешь поставить реакцию на сообщение — добавь в начале ответа маркер"
                . " [РЕАКЦИЯ: X], где X одно из: like, heart, laugh, wow, fire, party, cool, think, neutral, cry, dislike."
                . " Ставь по настроению, не каждый раз. Если хочешь только отреагировать без слов — верни ТОЛЬКО маркер.";
        }
        return $this->askWithFallback($context, $prompt);
    }

    /** MLP-260: длина контекста — настройка админки (мощным моделям можно больше). */
    private function contextLimit(): int {
        $limit = (int)ConfigManager::getInstance()->getOption('ai_context_messages', 24);
        return max(4, min(100, $limit));
    }

    /** Закреп — фоновый контекст низкого приоритета (MLP-242; вынесено из buildContext в MLP-260). */
    private function prependPinnedContext(array $context): array {
        $pinned = $this->chatManager->getPinnedMessage();
        if ($pinned && !empty($pinned['raw_message'])) {
            $raw = $pinned['raw_message'];
            if (preg_match('/^\s*\[\[poll:\d+\]\]\s*$/', $raw)) {
                $raw = '(в чате закреплён опрос)';
            }
            array_unshift($context, [
                'role' => 'user',
                'content' => "[Закреплено в чате, фоновый контекст низкого приоритета — учитывай только если по-настоящему уместно]: " . $raw,
            ]);
        }
        return $context;
    }

    private function buildContext($limit = 24, $maxAgeHours = null, $beforeId = null, $includePinned = true) {
        // Fetch last N messages (при $beforeId — только сообщения старше этого id)
        $messages = $this->chatManager->getMessages($limit, $beforeId);
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

        // Закреплённое сообщение — фоновый контекст (MLP-242); в проактиве
        // подмешивается ПОСЛЕ гейтов (MLP-260), поэтому отключаемо.
        if ($includePinned) {
            $context = $this->prependPinnedContext($context);
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
