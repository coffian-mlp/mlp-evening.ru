<?php
use Domain\Auth;
use Domain\UserManager;
use Domain\ChatManager;
use Domain\EpisodeManager;
use Domain\StickerManager;
use Domain\BotCommandManager;
use Domain\CaptchaManager;
use Domain\OnlineManager;
use Infra\Database;
use Infra\ConfigManager;
use Infra\Mailer;
use Infra\UploadManager;

require_once __DIR__ . '/autoload.php'; // MLP-248: классы — только автозагрузкой

use LLM\BotDispatch;
use Social\SocialAuthService;
use Social\TelegramProvider;

// Отключаем вывод ошибок в поток вывода по умолчанию, но позволяем включить через конфиг
$debugMode = ConfigManager::getInstance()->getOption('debug_mode', 0);
if ($debugMode) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(E_ALL); // Логируем все, но не выводим
}


header('Content-Type: application/json');

// Перехват фатальных ошибок
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE)) {
        // L1: детали — в лог, наружу — общий текст (не раскрываем внутренности)
        error_log('api.php fatal: ' . $error['message'] . ' @ ' . $error['file'] . ':' . $error['line']);
        echo json_encode(['success' => false, 'message' => 'Внутренняя ошибка сервера.', 'type' => 'error']);
    }
});

try {
    // 🛡️ CSRF Protection for POST requests
    // We check token only if user IS logged in, OR if we want to protect public forms too.
    // For now, let's keep strict check if token is present, but allow public access if logic permits.
    // But wait, the original logic required login. Let's make it flexible.
    
    $isLoggedIn = Auth::check();
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Check header OR post field
        $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
        
        $action = $_POST['action'] ?? '';
        
        // CSRF: публичное чтение событий — без токена; всё остальное для залогиненных
        // проверяется строго (L4/MLP-229: убран прежний bypass для save/delete_event —
        // дашборд теперь шлёт window.csrfToken, проставленный в header.php).
        if (in_array($action, ['get_public_events', 'get_poll', 'get_pinned'], true)) {
            // публичное чтение — без CSRF
        } elseif ($isLoggedIn && !Auth::checkCsrfToken($csrfToken)) {
            echo json_encode([
                'success' => false,
                'message' => 'Ошибка безопасности. Обнови страничку!',
                'type' => 'error'
            ]);
            exit();
        }
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => 'Only POST requests allowed', 'type' => 'error']);
        exit();
    }

    $action = $_POST['action'] ?? '';

    // MLP-262 (AR6-4): формат и политика ошибок живут в Api\Response.
    // Глобальные обёртки — для легаси-вызовов switch (уходят со срезами AR5-6).
    function sendResponse($success, $message, $type = 'success', $data = []) {
        \Api\Response::json($success, $message, $type, $data);
    }

    function respondCaught(Throwable $e, string $prefix = '') {
        \Api\Response::caught($e, $prefix);
    }

    // checkHierarchy переехала в Api\ModerationController (MLP-255).

    // Public Actions
    if ($action === 'get_chat_input') {
        // Право на кнопку «Создать опрос» при мягком входе (MLP-239).
        $arResult = ['can_create_poll' => false];
        if (Auth::check()) {
            $pr = ConfigManager::getInstance()->getOption('polls_create_role', 'moderator');
            $arResult['can_create_poll'] = ($pr === 'all') ? true : (($pr === 'admin') ? Auth::isAdmin() : Auth::isModerator());
        }
        ob_start();
        include __DIR__ . '/src/Components/Chat/templates/embedded/input_area.php';
        $html = ob_get_clean();
        
        $userData = [];
        if (Auth::check()) {
            $um = new UserManager();
            $currentUser = $um->getUserById($_SESSION['user_id']);
            $userOptions = $um->getUserOptions($_SESSION['user_id']);
            
            $userData = [
                'user_id' => $_SESSION['user_id'],
                'role' => $_SESSION['role'],
                'username' => $_SESSION['username'],
                'nickname' => $currentUser['nickname'] ?? $_SESSION['username'],
                'chat_color' => $currentUser['chat_color'] ?? '#6d2f8e',
                'avatar_url' => $currentUser['avatar_url'] ?? '',
                'csrf_token' => Auth::generateCsrfToken(),
                'is_moderator' => Auth::isModerator(),
                'user_options' => $userOptions
            ];
        }
        
        sendResponse(true, "Loaded", 'success', ['html' => $html, 'user_data' => $userData]);
    }

    // captcha_start/captcha_check, heartbeat/leave — в тонком роутере (MLP-245).

    if ($action === 'social_login') {

        $providerName = $_POST['provider'] ?? '';
        $data = $_POST['data'] ?? [];

        if ($providerName === 'telegram') {
            $provider = new TelegramProvider();
        } else {
            sendResponse(false, "Неизвестный провайдер авторизации", 'error');
        }

        $service = new SocialAuthService();
        $result = $service->handleLogin($provider, $data);

        if ($result['success']) {
            $responseJson = json_encode([
                'success' => true,
                'message' => $result['message'],
                'type' => 'success',
                'data' => ['redirect' => $result['redirect']]
            ]);
            
            if (function_exists('fastcgi_finish_request')) {
                echo $responseJson;
                fastcgi_finish_request();
            } else {
                @ob_end_clean();
                header("Connection: close");
                ignore_user_abort(true);
                ob_start();
                echo $responseJson;
                $size = ob_get_length();
                header("Content-Length: $size");
                ob_end_flush();
                flush();
            }

            // Закрываем сессию, чтобы не блокировать другие запросы пользователя
            session_write_close();
            
            // Снимаем лимит времени выполнения скрипта, чтобы он не умер во время долгой задержки
            set_time_limit(0);
            ignore_user_abort(true);
            
            BotDispatch::dispatch('greeting', ['username' => $_SESSION['username'] ?? 'Гость']);

            exit();
        } else {
            sendResponse(false, $result['message'], 'error');
        }
    }
    
    // --- BIND SOCIAL ACTION ---
    if ($action === 'bind_social') {
        if (!Auth::check()) {
            sendResponse(false, "Сначала нужно войти на сайт!", 'error');
        }

        
        $providerName = $_POST['provider'] ?? '';
        $data = $_POST['data'] ?? [];
        $userId = $_SESSION['user_id'];

        if ($providerName === 'telegram') {
            $provider = new TelegramProvider();
            
            // 1. Проверяем валидность данных от Telegram (Hash check)
            // Используем публичный метод validateCallback из TelegramProvider
            
            try {
                $tgUser = $provider->validateCallback($data); 
                
                if (!$tgUser) {
                    sendResponse(false, "Ошибка проверки подписи Telegram. Данные подделаны или устарели.", 'error');
                }

                // 2. Сохраняем в БД
                $db = Database::getInstance()->getConnection();
                
                // Проверяем, не занят ли этот Telegram ID другим пони
                $stmt = $db->prepare("SELECT user_id FROM user_socials WHERE provider = 'telegram' AND provider_uid = ?");
                $stmt->bind_param("s", $tgUser['id']);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                     sendResponse(false, "Этот аккаунт Telegram уже привязан к кому-то другому!", 'error');
                }
                
                // Привязываем!
                $userManager = new UserManager();
                
                if ($userManager->linkSocial($userId, 'telegram', $tgUser)) {
                    sendResponse(true, "Связь установлена!");
                } else {
                    sendResponse(false, "Ошибка базы данных.", 'error');
                }

            } catch (Throwable $e) {
                respondCaught($e, "Ошибка провайдера: ");
            }
        } else {
            sendResponse(false, "Неизвестный провайдер", 'error');
        }
    }

    if ($action === 'login') {
         $username = $_POST['username'] ?? '';
         $password = $_POST['password'] ?? '';
         
         // --- Brute Force Protection ---
         $ip = Auth::getIp();
         $status = Auth::checkLoginAttempts($ip);
         
         if ($status === 'blocked') {
             sendResponse(false, "Слишком много неудачных попыток. Доступ закрыт на 24 часа. Отдохни и попей какао.", 'error');
         }
         
         if ($status === 'captcha_needed') {
             $captcha = new CaptchaManager();
             
             if (!$captcha->isCompleted()) {
                 // Return special error code for JS to handle
                 echo json_encode([
                     'success' => false, 
                     'message' => 'Требуется проверка на робота (или чейнджлинга).', 
                     'type' => 'error',
                     'error_code' => 'captcha_required'
                 ]);
                 exit();
             }
         }
         // ------------------------------

         if (Auth::login($username, $password)) {
             Auth::resetLoginAttempts($ip); // Reset on success

             // remember-me: выдаём долгоживущий токен, если отмечена галка (MLP-223)
             if (!empty($_POST['remember'])) {
                 Auth::issueRememberToken($_SESSION['user_id']);
             }

             // Отправляем ответ клиенту
             $responseJson = json_encode([
                 'success' => true,
                 'message' => "Добро пожаловать, $username! Рады тебя видеть!",
                 'type' => 'success',
                 'data' => ['reload' => true]
             ]);
             
             if (function_exists('fastcgi_finish_request')) {
                 echo $responseJson;
                 fastcgi_finish_request();
             } else {
                 @ob_end_clean();
                 header("Connection: close");
                 ignore_user_abort(true);
                 ob_start();
                 echo $responseJson;
                 $size = ob_get_length();
                 header("Content-Length: $size");
                 ob_end_flush();
                 flush();
             }

             // Закрываем сессию
             session_write_close();
             
             // Снимаем лимит времени выполнения
             set_time_limit(0);
             ignore_user_abort(true);
             
             BotDispatch::dispatch('greeting', ['username' => $username]);

             exit();
         } else {
             $newCount = Auth::recordFailedLogin($ip);
             
             // Check if we hit a threshold where captcha needs to be reset to force re-verification
             if ($newCount === 3 || $newCount === 6) {
                 $captcha = new CaptchaManager();
                 $captcha->reset();
             }
             
             sendResponse(false, "Упс! Неверное имя или пароль.", 'error');
         }
    }

    if ($action === 'forgot_password') {
        $email = trim($_POST['email'] ?? '');
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            sendResponse(false, "Введите корректный Email", 'error');
        }

        $userManager = new UserManager();
        $user = $userManager->getUserByEmail($email);

        // DEBUG: Логируем попытку сброса
        $logDir = __DIR__ . '/logs'; // api.php is in root, logs is in root
        if (is_dir($logDir) && is_writable($logDir)) {
             file_put_contents($logDir . '/debug.log', date('Y-m-d H:i:s') . " - Action: forgot_password. Email: '$email'. User Found: " . ($user ? 'YES (ID: '.$user['id'].')' : 'NO') . "\n", FILE_APPEND);
        }

        if (!$user) {
            // Security: Don't reveal if user exists.
            // But for UX friendly ponies, maybe we can say? 
            // Standard practice: "If this email exists, we sent a link".
            sendResponse(true, "Если этот Email есть в базе, мы отправили письмо!");
        }

        try {
            // 1. Generate Token
            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $expires = gmdate('Y-m-d H:i:s', time() + 3600); // 1 hour

            // 2. Save to DB
            if ($userManager->savePasswordResetToken($user['id'], $tokenHash, $expires)) {
                // 3. Send Email
                $mailer = new Mailer();
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
                $domain = $_SERVER['HTTP_HOST'];
                $link = $protocol . $domain . "/reset_password.php?token=" . $token;
                
                if ($mailer->sendPasswordReset($email, $link)) {
                    sendResponse(true, "Письмо отправлено на $email! Проверь папку Спам, если не придет.");
                } else {
                    sendResponse(false, "Ошибка отправки письма. Попробуйте позже.", 'error');
                }
            } else {
                sendResponse(false, "Ошибка БД", 'error');
            }
        } catch (Throwable $e) {
            respondCaught($e);
        }
    }

    if ($action === 'reset_password_submit') {
        $token = $_POST['token'] ?? '';
        $password = $_POST['password'] ?? '';
        
        if (empty($token) || empty($password)) {
            sendResponse(false, "Неверные данные", 'error');
        }
        if ($pwErr = Auth::validatePasswordPolicy($password)) {
            sendResponse(false, $pwErr, 'error');
        }

        $userManager = new UserManager();
        $tokenHash = hash('sha256', $token);
        $user = $userManager->getUserByResetToken($tokenHash);

        if (!$user) {
            sendResponse(false, "Ссылка устарела или недействительна.", 'error');
        }

        try {
            // Update password
            $userManager->updateUser($user['id'], ['password' => $password]);
            // Clear token
            $userManager->clearResetToken($user['id']);
            
            sendResponse(true, "Пароль успешно изменен! Теперь можно войти.", 'success', ['redirect' => '/']);
        } catch (Throwable $e) {
            respondCaught($e, "Смена пароля: ");
        }
    }

    if ($action === 'register') {
        $login = trim($_POST['login'] ?? '');
        $nickname = trim($_POST['nickname'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        
        // 1. Проверка Капчи
        $captcha = new CaptchaManager();
        
        if (!$captcha->isCompleted()) {
             sendResponse(false, "Сначала нужно пройти испытание Гармонии!", 'error');
        }

        // 2. Валидация данных
        if (mb_strlen($login) < 3) sendResponse(false, "Логин слишком короткий (нужно хотя бы 3 символа)", 'error');
        if ($pwErr = Auth::validatePasswordPolicy($password)) sendResponse(false, $pwErr, 'error');
        
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            sendResponse(false, "Некорректный формат Email", 'error');
        }

        // 3. Создание пользователя
        $userManager = new UserManager();
        try {
            // Создаем обычного пользователя (role='user')
            $userManager->createUser($login, $password, 'user', $nickname, $email);
            
            // 4. Автоматический вход
            if (Auth::login($login, $password)) {
                $responseJson = json_encode([
                    'success' => true,
                    'message' => "Ура! Ты с нами! Добро пожаловать!",
                    'type' => 'success',
                    'data' => ['reload' => true]
                ]);
                
                if (function_exists('fastcgi_finish_request')) {
                    echo $responseJson;
                    fastcgi_finish_request();
                } else {
                    @ob_end_clean();
                    header("Connection: close");
                    ignore_user_abort(true);
                    ob_start();
                    echo $responseJson;
                    $size = ob_get_length();
                    header("Content-Length: $size");
                    ob_end_flush();
                    flush();
                }

                // Закрываем сессию
                session_write_close();
                
                // Снимаем лимит времени
                set_time_limit(0);
                ignore_user_abort(true);
                
                BotDispatch::dispatch('greeting', ['username' => $login]);

                exit();
            } else {
                sendResponse(true, "Ура! Ты с нами! Теперь можно войти.", 'success');
            }
            
        } catch (Throwable $e) {
            respondCaught($e);
        }
    }

    // Protected Actions
    // captcha_*/heartbeat/leave: публичные, обрабатывались до этого гейта — после
    // переезда в роутер (MLP-245) числятся в whitelist явно.
    if (!$isLoggedIn && !in_array($action, ['login', 'register', 'forgot_password', 'reset_password_submit', 'social_login', 'get_messages', 'get_stickers', 'get_packs', 'get_public_events', 'get_poll', 'get_pinned', 'captcha_start', 'captcha_check', 'heartbeat', 'leave'])) {
         Auth::requireApiLogin(); 
    }

    if ($action === 'update_profile') {
        $userId = $_SESSION['user_id'];
        $data = [];
        
        if (isset($_POST['nickname'])) {
            $nick = trim($_POST['nickname']);
            if (empty($nick)) sendResponse(false, "Никнейм не может быть пустым", 'error');
            $data['nickname'] = $nick;
        }

        if (isset($_POST['email'])) {
            $email = trim($_POST['email']);
            if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                sendResponse(false, "Некорректный Email", 'error');
            }
            $data['email'] = $email; // Empty string is fine if allowed to remove email, but uniqueness check in updateUser handles it.
        }
        
        if (isset($_POST['login'])) {
            $login = trim($_POST['login']);
            if (mb_strlen($login) < 3) sendResponse(false, "Логин слишком короткий", 'error');
            $data['login'] = $login;
        }
        
        if (isset($_POST['chat_color'])) {
            $color = trim($_POST['chat_color']);
            if (!preg_match('/^#[a-fA-F0-9]{6}$/', $color)) $color = '#6d2f8e';
            $data['chat_color'] = $color;
        }
        
        if (isset($_POST['font_preference'])) {
            $font = trim($_POST['font_preference']);
            // Whitelist fonts
            $allowedFonts = ['open_sans', 'fira', 'pt', 'rubik', 'inter'];
            if (in_array($font, $allowedFonts)) {
                $data['font_preference'] = $font;
            }
        }
        
        if (isset($_POST['font_scale'])) {
            $scale = (int)$_POST['font_scale'];
            if ($scale < 50) $scale = 50;
            if ($scale > 150) $scale = 150;
            $data['font_scale'] = $scale;
        }
        
        // Avatar Logic
        if (isset($_POST['avatar_url']) || isset($_FILES['avatar_file'])) {
            $url = trim($_POST['avatar_url'] ?? '');
            
            try {
                $uploadManager = new UploadManager();
                // 1. File Upload
                if (isset($_FILES['avatar_file']) && $_FILES['avatar_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $url = $uploadManager->uploadFromPost($_FILES['avatar_file']);
                }
                // 2. URL Download (only if external URL)
                elseif (!empty($url) && strpos($url, '/upload/avatars/') !== 0 && filter_var($url, FILTER_VALIDATE_URL)) {
                    $url = $uploadManager->uploadFromUrl($url);
                }
                
                $data['avatar_url'] = $url;
            } catch (Throwable $e) {
                respondCaught($e, "Аватар: ");
            }
        }
        
        if (!empty($_POST['password'])) {
            if ($pwErr = Auth::validatePasswordPolicy($_POST['password'])) sendResponse(false, $pwErr, 'error');
            $data['password'] = $_POST['password'];
        }

        $userManager = new UserManager();
        try {
            $userManager->updateProfile($userId, $data);
            if (isset($data['nickname'])) $_SESSION['username'] = $data['nickname'];
            sendResponse(true, "Профиль обновлен!", 'success', ['reload' => true]);
        } catch (Throwable $e) {
            respondCaught($e);
        }
    }


    // --- Тонкий роутер (MLP-229/255): action → роль → контроллер.
    // Карта — в src/Api/routes.php (отдельный файл, чтобы её видели тесты).
    $apiRoutes = require __DIR__ . '/src/Api/routes.php';
    if (isset($apiRoutes[$action])) {
        $route = $apiRoutes[$action];
        if ($route['role'] === 'admin') {
            Auth::requireApiAdmin();
        } elseif ($route['role'] === 'moderator') {
            // MLP-255: третий уровень иерархии user < moderator < admin.
            Auth::requireApiLogin();
            if (!Auth::isModerator()) sendResponse(false, "Недостаточно прав!", 'error');
        } elseif ($route['role'] === 'user') {
            Auth::requireApiLogin();
        }
        call_user_func($route['handler']); // хендлер отвечает через sendResponse и завершает
        exit();
    }

    // ГРАНИЦА (MLP-255): в switch остались только чат (сообщения/реакции/поиск/
    // upload) и auth/профиль (logout, user_options, соцсети). Админский пласт
    // целиком в тонком роутере выше. Новые actions — ТОЛЬКО в src/Api/routes.php.
    switch ($action) {
        // update_settings и плейлист-actions — в тонком роутере (MLP-255).
        case 'logout':
            Auth::logout();
            sendResponse(true, "До скорой встречи!", 'success', ['reload' => true]); 
            break;

        // get_users / get_user_options / get_audit_logs — в тонком роутере (MLP-255).

        case 'get_user_socials':
            $userId = $_SESSION['user_id'];
            $userManager = new UserManager();
            $socials = $userManager->getUserSocials($userId);
            
            sendResponse(true, "Список соцсетей получен", 'success', ['socials' => $socials]);
            break;

        case 'unlink_social':
            // Опционально: отвязка аккаунта
            $provider = $_POST['provider'] ?? '';
            $userId = $_SESSION['user_id'];
            
            if (empty($provider)) sendResponse(false, "Провайдер не указан", 'error');
            
            // Защита: Нельзя отвязать единственную соцсеть, если нет пароля? 
            // Пока оставим простую логику.
            
            $userManager = new UserManager();
            if ($userManager->unlinkSocial($userId, $provider)) {
                sendResponse(true, "Аккаунт отвязан!");
            } else {
                sendResponse(false, "Привязка не найдена.", 'error');
            }
            break;

        case 'save_user_option':
            $key = $_POST['key'] ?? '';
            $value = $_POST['value'] ?? '';
            
            // Whitelist keys to prevent garbage
            $allowedKeys = ['chat_title_enabled'];
            if (!in_array($key, $allowedKeys)) {
                sendResponse(false, "Некорректная настройка", 'error');
            }
            
            $userManager = new UserManager();
            if ($userManager->setUserOption($_SESSION['user_id'], $key, $value)) {
                 sendResponse(true, "Saved");
            } else {
                 sendResponse(false, "DB Error", 'error');
            }
            break;

        // save_user / delete_user — в тонком роутере (MLP-255).

        // --- Moderation Actions ---
        
        // Модерация (ban/unban/mute/unmute/purge_messages) — в тонком роутере (MLP-255),
        // включая переезд checkHierarchy в Api\ModerationController.

                case 'get_messages':
            $limit = (int)($_POST['limit'] ?? 50);
            $beforeId = isset($_POST['before_id']) ? (int)$_POST['before_id'] : null;
            
            if ($limit > 100) $limit = 100;
            if ($limit < 1) $limit = 1;
            
            $chat = new ChatManager();
            $messages = $chat->getMessages($limit, $beforeId);
            
            sendResponse(true, "Сообщения получены", 'success', ['messages' => $messages]);
            break;

        case 'search_messages':
            $query = trim($_POST['query'] ?? '');
            $limit = (int)($_POST['limit'] ?? 50);
            $offset = (int)($_POST['offset'] ?? 0);
            
            if (empty($query)) {
                sendResponse(false, "Пустой запрос", 'error');
            }
            
            if ($limit > 100) $limit = 100;
            if ($limit < 1) $limit = 1;
            
            $chat = new ChatManager();
            $messages = $chat->searchMessages($query, $limit, $offset);
            
            sendResponse(true, "Результаты поиска", 'success', ['messages' => $messages]);
            break;

        case 'get_message_context':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) {
                sendResponse(false, "ID сообщения не указан", 'error');
            }
            
            $chat = new ChatManager();
            $messages = $chat->getMessagesContext($id, 20); // 20 before + 20 after
            
            sendResponse(true, "Контекст загружен", 'success', ['messages' => $messages]);
            break;

        case 'toggle_reaction':
            $messageId = (int)($_POST['message_id'] ?? 0);
            $reaction = trim($_POST['reaction'] ?? '');
            
            if (!$messageId || empty($reaction)) {
                sendResponse(false, "Некорректные данные", 'error');
            }
            
            $chat = new ChatManager();
            $result = $chat->toggleReaction($messageId, $_SESSION['user_id'], $reaction);
            
            if ($result['success']) {
                sendResponse(true, "Реакция обновлена", 'success', $result);
            } else {
                sendResponse(false, $result['message'], 'error');
            }
            break;

        case 'send_message':
            $message = $_POST['message'] ?? '';
            if (empty($message)) {
                sendResponse(false, "Эй, сообщение не может быть пустым!", 'error');
            }
            
            // Assuming user is logged in because of the check above
            $userId = $_SESSION['user_id'];
            $username = $_SESSION['username'];
            
            // Handle Quoted Messages
            $quotedMsgIds = [];
            if (!empty($_POST['quoted_msg_ids'])) {
                $quotedMsgIds = explode(',', $_POST['quoted_msg_ids']);
            }
            
            $chat = new ChatManager();
            $rateLimit = (int)ConfigManager::getInstance()->getOption('chat_rate_limit', 0);
            
            if (!$chat->checkRateLimit($userId, $rateLimit)) {
                sendResponse(false, "Не так быстро, сахарок! Подожди $rateLimit сек.", 'error');
            }

            $newMsgId = $chat->addMessage($userId, $username, $message, $quotedMsgIds);
            if ($newMsgId) {
                // Отправляем ответ клиенту, чтобы не задерживать UI
                $responseJson = json_encode([
                    'success' => true,
                    'message' => "Сообщение отправлено",
                    'type' => 'success',
                    'data' => []
                ]);
                
                // Закрываем соединение с клиентом, чтобы PHP мог думать дальше (для PHP-FPM)
                if (function_exists('fastcgi_finish_request')) {
                    echo $responseJson;
                    fastcgi_finish_request();
                } else {
                    // Fallback для других SAPI
                    @ob_end_clean();
                    header("Connection: close");
                    ignore_user_abort(true);
                    ob_start();
                    echo $responseJson;
                    $size = ob_get_length();
                    header("Content-Length: $size");
                    ob_end_flush();
                    flush();
                }

                // Закрываем сессию пользователя, чтобы он мог продолжить сидеть на сайте, пока мы думаем
                session_write_close();
                
                // Быстрое (без LLM) определение: команда или обычное упоминание.
                $db = Database::getInstance()->getConnection();
                $matchedCommand = null;

                // A3 (MLP-228): активные команды читаем через владельца таблицы.
                $botCommands = new BotCommandManager();
                if ($botCommands->isAvailable()) {
                    $matchedCommand = BotCommandManager::matchCommand($botCommands->getActive(), $message);
                } else {
                    // Fallback, если таблицы ещё нет (миграция не прогнана)
                    if (preg_match('/^\/?(schedule|расписание)/ui', $message)) {
                        $matchedCommand = ['handler_type' => 'schedule'];
                    }
                }

                // AR4-1: команда-опрос уважает polls_create_role — как и прямое создание.
                // Нет прав → сбрасываем в обычное упоминание (Лира поболтает, опрос не создаст).
                if ($matchedCommand && ($matchedCommand['handler_type'] ?? '') === 'poll') {
                    $pr = ConfigManager::getInstance()->getOption('polls_create_role', 'moderator');
                    $canPoll = ($pr === 'all') ? Auth::check() : (($pr === 'admin') ? Auth::isAdmin() : Auth::isModerator());
                    if (!$canPoll) $matchedCommand = null;
                }

                // Диспетчеризация: очередь (воркер ответит) или inline-фоллбек (с lifelike-задержкой).
                $mid = ($newMsgId === true) ? null : $newMsgId;
                if ($matchedCommand) {
                    BotDispatch::dispatch('dynamic_command', [
                        'message'    => $message,
                        'message_id' => $mid,
                        'command'    => $matchedCommand,
                        'user_id'    => $userId,
                        'username'   => $username,
                    ]);
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
            } else {
                sendResponse(false, "Ой, что-то пошло не так при отправке...", 'error');
            }
            break;

        case 'edit_message':
            $messageId = (int)($_POST['message_id'] ?? 0);
            $newMessage = trim($_POST['message'] ?? '');
            
            if (!$messageId || empty($newMessage)) {
                sendResponse(false, "Некорректные данные для редактирования.", 'error');
            }
            
            $chat = new ChatManager();
            if ($chat->editMessage($messageId, $_SESSION['user_id'], $newMessage)) {
                sendResponse(true, "Сообщение обновлено!");
            } else {
                sendResponse(false, "Не удалось отредактировать сообщение (возможно, прошло больше 10 минут или это не твое сообщение).", 'error');
            }
            break;

        case 'delete_message':
            $messageId = (int)($_POST['message_id'] ?? 0);
            if (!$messageId) {
                sendResponse(false, "Некорректный ID сообщения.", 'error');
            }

            $chat = new ChatManager();
            // Check if admin or moderator
            // Now we pass the role to allow hierarchy check inside ChatManager
            $actorRole = Auth::isModerator() ? $_SESSION['role'] : null;
            
            if ($chat->deleteMessage($messageId, $_SESSION['user_id'], $actorRole)) {
                sendResponse(true, "Сообщение удалено.");
            } else {
                sendResponse(false, "Не удалось удалить сообщение.", 'error');
            }
            break;

        case 'restore_message':
            $messageId = (int)($_POST['message_id'] ?? 0);
            if (!$messageId) {
                sendResponse(false, "Некорректный ID сообщения.", 'error');
            }

            $chat = new ChatManager();
            $actorRole = Auth::isModerator() ? $_SESSION['role'] : null;
            
            if ($chat->restoreMessage($messageId, $_SESSION['user_id'], $actorRole)) {
                sendResponse(true, "Сообщение восстановлено! ✨");
            } else {
                sendResponse(false, "Не удалось восстановить (время вышло или нет прав).", 'error');
            }
            break;

        case 'upload_file':
            if (!isset($_FILES['file'])) {
                sendResponse(false, "Файл не найден.", 'error');
            }
            
            try {
                $uploadManager = new UploadManager('chat');
                $url = $uploadManager->uploadFromPost($_FILES['file']);
                
                // Determine if image for frontend convenience
                $isImage = preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $url);
                
                sendResponse(true, "Файл загружен!", 'success', [
                    'url' => $url,
                    'name' => $_FILES['file']['name'], // Original name
                    'is_image' => (bool)$isImage
                ]);
            } catch (Throwable $e) {
                respondCaught($e);
            }
            break;

        // Стикеры и паки — в тонком роутере (MLP-255).

        // События (save_event / delete_event / get_public_events) обрабатываются
        // тонким роутером выше (MLP-229) — здесь их больше нет.

        default:
            sendResponse(false, "❌ Неизвестное действие: $action", 'error');
    }

} catch (Throwable $e) {
    // L1: детали — в лог, наружу — общий текст (Throwable: ловим и TypeError/mysqli, MLP-261)
    error_log('api.php exception: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    sendResponse(false, "💥 Ошибка сервера. Попробуйте позже.", 'error');
}
