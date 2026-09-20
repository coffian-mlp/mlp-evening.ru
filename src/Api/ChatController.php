<?php

namespace Api;

use Domain\Auth;
use Domain\BotCommandManager;
use Domain\ChatManager;
use Domain\UserManager;
use Infra\ConfigManager;
use Infra\UploadManager;
use LLM\BotDispatch;
use LLM\StreamCommand;

/**
 * Обработчики чата (MLP-265, финал среза AR5-6) — перенос из switch api.php
 * вербатим. Ответы — Api\Response; роли: getInput/getMessages — public
 * (числятся в guest-whitelist api.php), остальные — user (гейтит роутер).
 */
class ChatController {

    /** HTML поля ввода + данные пользователя для мягкой авторизации (public). */
    public static function getInput(): void {
        // Право на кнопку «Создать опрос» при мягком входе (MLP-239).
        $arResult = ['can_create_poll' => \Domain\PollManager::canCreate()];
        ob_start();
        include dirname(__DIR__, 2) . '/src/Components/Chat/templates/embedded/input_area.php';
        $html = ob_get_clean();

        $userData = [];
        if (Auth::check()) {
            $um = new UserManager();
            $currentUser = $um->getUserById(Auth::userId());
            $userOptions = $um->getUserOptions(Auth::userId());

            $userData = [
                'user_id' => Auth::userId(),
                'role' => Auth::role(),
                'username' => Auth::username(),
                'nickname' => $currentUser['nickname'] ?? Auth::username(),
                'chat_color' => $currentUser['chat_color'] ?? '#6d2f8e',
                'avatar_url' => $currentUser['avatar_url'] ?? '',
                'csrf_token' => Auth::generateCsrfToken(),
                'is_moderator' => Auth::isModerator(),
                'user_options' => $userOptions
            ];
        }

        Response::json(true, "Loaded", 'success', ['html' => $html, 'user_data' => $userData]);
    }

    /** История сообщений (public — чат виден гостям). */
    public static function getMessages(): void {
        $limit = (int)($_POST['limit'] ?? 50);
        $beforeId = isset($_POST['before_id']) ? (int)$_POST['before_id'] : null;

        if ($limit > 100) $limit = 100;
        if ($limit < 1) $limit = 1;

        $messages = (new ChatManager())->getMessages($limit, $beforeId);
        Response::json(true, "Сообщения получены", 'success', ['messages' => $messages]);
    }

    /** Поиск по истории (user). */
    public static function search(): void {
        $query = trim($_POST['query'] ?? '');
        $limit = (int)($_POST['limit'] ?? 50);
        $offset = (int)($_POST['offset'] ?? 0);

        if (empty($query)) {
            Response::json(false, "Пустой запрос", 'error');
        }
        if ($limit > 100) $limit = 100;
        if ($limit < 1) $limit = 1;

        $messages = (new ChatManager())->searchMessages($query, $limit, $offset);
        Response::json(true, "Результаты поиска", 'success', ['messages' => $messages]);
    }

    /** Контекст вокруг сообщения — «прыжок во времени» (user). */
    public static function getContext(): void {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            Response::json(false, "ID сообщения не указан", 'error');
        }
        $messages = (new ChatManager())->getMessagesContext($id, 20); // 20 before + 20 after
        Response::json(true, "Контекст загружен", 'success', ['messages' => $messages]);
    }

    /** Поставить/снять реакцию (user). */
    public static function toggleReaction(): void {
        $messageId = (int)($_POST['message_id'] ?? 0);
        $reaction = trim($_POST['reaction'] ?? '');

        if (!$messageId || empty($reaction)) {
            Response::json(false, "Некорректные данные", 'error');
        }

        $result = (new ChatManager())->toggleReaction($messageId, Auth::userId(), $reaction);
        if ($result['success']) {
            Response::json(true, "Реакция обновлена", 'success', $result);
        }
        Response::json(false, $result['message'], 'error');
    }

    /** Отправка сообщения: rate-limit, быстрый ответ клиенту, диспатч бота (user). */
    public static function send(): void {
        $message = $_POST['message'] ?? '';
        if (empty($message)) {
            Response::json(false, "Эй, сообщение не может быть пустым!", 'error');
        }

        $userId = Auth::userId();
        $username = Auth::username();

        $quotedMsgIds = [];
        if (!empty($_POST['quoted_msg_ids'])) {
            $quotedMsgIds = explode(',', $_POST['quoted_msg_ids']);
        }

        $chat = new ChatManager();
        $rateLimit = (int)ConfigManager::getInstance()->getOption('chat_rate_limit', 0);
        if (!$chat->checkRateLimit($userId, $rateLimit)) {
            Response::json(false, "Не так быстро, сахарок! Подожди $rateLimit сек.", 'error');
        }

        $newMsgId = $chat->addMessage($userId, $username, $message, $quotedMsgIds);
        if (!$newMsgId) {
            Response::json(false, "Ой, что-то пошло не так при отправке...", 'error');
        }

        // Отдаём ответ клиенту сразу — бот думает уже без него.
        Response::finish(json_encode([
            'success' => true,
            'message' => "Сообщение отправлено",
            'type' => 'success',
            'data' => []
        ]));

        $mid = ($newMsgId === true) ? null : $newMsgId;

        // Быстрое (без LLM) определение: команда или обычное упоминание.
        $matchedCommand = null;

        // A3 (MLP-228): активные команды читаем через владельца таблицы.
        $botCommands = new BotCommandManager();
        if ($botCommands->isAvailable()) {
            $matchedCommand = BotCommandManager::matchCommand($botCommands->getActive(), $message);
        } else {
            // Fallback, если таблицы ещё нет (миграция не прогнана)
            if (preg_match('/^\/(schedule|расписание)/ui', $message)) {
                $matchedCommand = ['handler_type' => 'schedule'];
            }
        }

        // AR4-1: команда-опрос уважает polls_create_role — как и прямое создание.
        // Нет прав → сбрасываем в обычное упоминание (Лира поболтает, опрос не создаст).
        if ($matchedCommand && ($matchedCommand['handler_type'] ?? '') === 'poll'
            && !\Domain\PollManager::canCreate()) {
            $matchedCommand = null;
        }

        // MLP-314: гейты команд памяти — здесь, а не в воркере (там роль недоступна).
        $memoryAllowed = false;
        $memoryTypes = ['memory_add', 'memory_show', 'memory_forget'];
        if ($matchedCommand && in_array($matchedCommand['handler_type'] ?? '', $memoryTypes, true)) {
            $mType = $matchedCommand['handler_type'];
            if (!(int)\Infra\ConfigManager::getInstance()->getOption('ai_memory_enabled', 1)) {
                // AC-8: подсистема выключена → все три команды не существуют (тишина,
                // до фичи таких сообщений не было — произносимый отказ недопустим).
                $matchedCommand = null;
            } elseif (mb_substr(trim($message), 0, 1) !== '/') {
                // Слэш обязателен (та же trim-строка, что видит matchCommand):
                // «Запомни, я потом отвечу» — обычная речь, не запись в память.
                $matchedCommand = null;
            } elseif ($mType === 'memory_show') {
                // Право «о себе» — fail-closed флагом в payload: отсутствие = отказ
                // (обработчик ответит вежливой репликой без LLM — AC-4).
                $memoryAllowed = \Domain\BotMemoryManager::canViewOwn();
            } elseif (!\Domain\BotMemoryManager::canTeach()) {
                // /запомни, /забудь без права → обычный mention-путь (образец poll).
                $matchedCommand = null;
            }
        }

        // MLP-345 (беклог №19): «/штош @ник» — итог вечера за другого, только модераторам; право — флагом в payload
        // (fail-closed: в воркере роли нет). Обычный /штош без адресата проходит как раньше.
        $recapForOthers = $matchedCommand && ($matchedCommand['handler_type'] ?? '') === 'recap' && Auth::isModerator();

        // MLP-335: /яос — свой облик; без прав, но только при включённой памяти (запись живёт в bot_memory).
        if ($matchedCommand && ($matchedCommand['handler_type'] ?? '') === 'oc_set'
            && !(int)\Infra\ConfigManager::getInstance()->getOption('ai_memory_enabled', 1)) {
            $matchedCommand = null;
        }

        // MLP-318: свободная форма напоминаний («Лира, напомни через час…») — ПЕРЕД
        // веткой стрима: «напомни включить перерыв» — это намерение напомнить, не команда.
        // Явные слэш-команды по-прежнему приоритетнее (matchCommand выше).
        if (!$matchedCommand && $userId > 0 && \LLM\ReminderCommand::wants($message)) {
            $matchedCommand = ['handler_type' => 'reminder', 'command_prefix' => '/напомни', 'system_prompt' => ''];
        }

        // MLP-307: обращение к боту с командой стрима («Лира, включи перерыв») —
        // отвечает сама Лира отдельной веткой (контекст чата + приоритетная инструкция).
        // Проверяется ПОСЛЕ команд бота: явная команда (/нарисуй кинотеатр) приоритетнее,
        // иначе её ключевое слово увело бы запрос в ветку стрима. Обращение БЕЗ известной
        // команды сюда не попадает — это обычный mention.
        if (!$matchedCommand && StreamCommand::wants($message)) {
            BotDispatch::dispatch('stream_command', [
                'message'    => $message,
                'message_id' => $mid,
                'user_id'    => $userId,
                'username'   => $username,
            ]);
            exit();
        }

        // Диспетчеризация: очередь (воркер ответит) или inline-фоллбек (с lifelike-задержкой).
        if ($matchedCommand) {
            $payload = [
                'message'    => $message,
                'message_id' => $mid,
                'command'    => $matchedCommand,
                'user_id'    => $userId,
                'username'   => $username,
            ];
            if (($matchedCommand['handler_type'] ?? '') === 'memory_show' && $memoryAllowed) {
                $payload['allowed'] = true; // fail-closed: ставится ТОЛЬКО здесь (MLP-314)
            }
            if ($recapForOthers) {
                $payload['recap_for_others'] = true; // MLP-345: модератор может /штош @ник
            }
            BotDispatch::dispatch('dynamic_command', $payload);
        } else {
            BotDispatch::dispatch('mention', [
                'message'        => $message,
                'message_id'     => $mid,
                'quoted_msg_ids' => $quotedMsgIds,
                'user_id'        => $userId,
                'username'       => $username,
            ]);
        }

        exit();
    }

    /** Редактирование своего сообщения (user; окно 10 минут — в ChatManager). */
    public static function edit(): void {
        $messageId = (int)($_POST['message_id'] ?? 0);
        $newMessage = trim($_POST['message'] ?? '');

        if (!$messageId || empty($newMessage)) {
            Response::json(false, "Некорректные данные для редактирования.", 'error');
        }

        if ((new ChatManager())->editMessage($messageId, Auth::userId(), $newMessage)) {
            Response::json(true, "Сообщение обновлено!");
        }
        Response::json(false, "Не удалось отредактировать сообщение (возможно, прошло больше 10 минут или это не твое сообщение).", 'error');
    }

    /** Удаление: своё — всегда, чужое — по иерархии ролей внутри ChatManager (user). */
    public static function delete(): void {
        $messageId = (int)($_POST['message_id'] ?? 0);
        if (!$messageId) {
            Response::json(false, "Некорректный ID сообщения.", 'error');
        }

        $actorRole = Auth::isModerator() ? Auth::role() : null;
        if ((new ChatManager())->deleteMessage($messageId, Auth::userId(), $actorRole)) {
            Response::json(true, "Сообщение удалено.");
        }
        Response::json(false, "Не удалось удалить сообщение.", 'error');
    }

    /** Восстановление удалённого (user). */
    public static function restore(): void {
        $messageId = (int)($_POST['message_id'] ?? 0);
        if (!$messageId) {
            Response::json(false, "Некорректный ID сообщения.", 'error');
        }

        $actorRole = Auth::isModerator() ? Auth::role() : null;
        if ((new ChatManager())->restoreMessage($messageId, Auth::userId(), $actorRole)) {
            Response::json(true, "Сообщение восстановлено! ✨");
        }
        Response::json(false, "Не удалось восстановить (время вышло или нет прав).", 'error');
    }

    /** Загрузка файла в чат (user). */
    public static function uploadFile(): void {
        if (!isset($_FILES['file'])) {
            Response::json(false, "Файл не найден.", 'error');
        }

        try {
            $url = (new UploadManager('chat'))->uploadFromPost($_FILES['file']);
            $isImage = preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $url);

            Response::json(true, "Файл загружен!", 'success', [
                'url' => $url,
                'name' => $_FILES['file']['name'], // Original name
                'is_image' => (bool)$isImage
            ]);
        } catch (\Throwable $e) {
            Response::caught($e);
        }
    }
}
