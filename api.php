<?php

// Отключаем вывод ошибок в поток вывода по умолчанию, но позволяем включить через конфиг
require_once __DIR__ . '/src/ConfigManager.php';
$debugMode = ConfigManager::getInstance()->getOption('debug_mode', 0);
if ($debugMode) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(E_ALL); // Логируем все, но не выводим
}

require_once __DIR__ . '/src/EpisodeManager.php';
require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/ChatManager.php';
require_once __DIR__ . '/src/UserManager.php';
require_once __DIR__ . '/src/StickerManager.php';
require_once __DIR__ . '/src/UploadManager.php';
require_once __DIR__ . '/src/Mailer.php'; // Подключаем Mailer

header('Content-Type: application/json');

// Перехват фатальных ошибок
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE)) {
        echo json_encode(['success' => false, 'message' => 'Fatal Error: ' . $error['message'], 'type' => 'error']);
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
        
        // Skip CSRF check for public event fetching
        if ($action === 'get_public_events') {
            // let it pass
        } elseif ($isLoggedIn && !Auth::checkCsrfToken($csrfToken)) {
            // Разрешаем сохранение и удаление события администратору
            if (($action === 'save_event' || $action === 'delete_event') && Auth::isAdmin()) {
                // let it pass
            } else {
                 echo json_encode([
                    'success' => false, 
                    'message' => 'Ошибка безопасности. Обнови страничку!', 
                    'type' => 'error'
                ]);
                exit();
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => 'Only POST requests allowed', 'type' => 'error']);
        exit();
    }

    $action = $_POST['action'] ?? '';
    $manager = new EpisodeManager();
    // Lazy load ChatManager only when needed
    
    function sendResponse($success, $message, $type = 'success', $data = []) {
        echo json_encode([
            'success' => $success,
            'message' => $message,
            'type' => $type,
            'data' => $data
        ]);
        exit();
    }

    // --- Moderation Hierarchy Helper ---
    function checkHierarchy($targetUserId) {
        // Self-check
        if ($targetUserId == $_SESSION['user_id']) {
            return "Нельзя применять санкции к самому себе!";
        }

        $um = new UserManager();
        $target = $um->getUserById($targetUserId);
        if (!$target) return "Пользователь не найден.";

        $actorRole = $_SESSION['role'] ?? 'user';
        $targetRole = $target['role'];

        if ($actorRole === 'admin') {
            if ($targetRole === 'admin') return "Администратор неприкосновенен!";
            return true; // Admin can moderate everyone else
        }

        if ($actorRole === 'moderator') {
            if ($targetRole === 'admin') return "Это Администратор. Не шали!";
            if ($targetRole === 'moderator') return "Модераторы не могут трогать своих коллег.";
            return true; // Can moderate users
        }

        return "У вас нет прав модератора.";
    }

    // Public Actions
    if ($action === 'get_chat_input') {
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

    if ($action === 'captcha_start') {
        require_once __DIR__ . '/src/CaptchaManager.php';
        $captcha = new CaptchaManager();
        $data = $captcha->start();
        sendResponse(true, "Капча начата", 'success', $data);
    }

    if ($action === 'captcha_check') {
        require_once __DIR__ . '/src/CaptchaManager.php';
        $captcha = new CaptchaManager();
        $answer = $_POST['answer'] ?? '';
        $result = $captcha->checkAnswer($answer);
        
        if ($result['success']) {
            sendResponse(true, "Верно!", 'success', $result);
        } else {
            sendResponse(false, $result['message'], 'error');
        }
    }

    if ($action === 'heartbeat') {
        $sessionId = session_id(); // Ensure session is started (usually is in global init)
        $userId = $_SESSION['user_id'] ?? null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        require_once __DIR__ . '/src/OnlineManager.php';
        $online = new OnlineManager();
        $online->beat($sessionId, $userId, $ip, $ua);
        
        // Return detailed stats (default window 3 mins)
        $stats = $online->getOnlineStats(3);
        
        // 1% chance to cleanup old sessions (> 1 hour)
        if (rand(1, 100) === 1) {
            $online->cleanup(60);
        }
        
        sendResponse(true, "Beat", 'success', ['online_stats' => $stats]);
    }

    if ($action === 'leave') {
        $sessionId = session_id();
        require_once __DIR__ . '/src/OnlineManager.php';
        $online = new OnlineManager();
        $online->removeSession($sessionId);
        // No response needed usually for beacon, but we output valid JSON just in case
        echo json_encode(['success' => true]);
        exit();
    }

    if ($action === 'social_login') {
        require_once __DIR__ . '/src/Social/SocialAuthService.php';
        require_once __DIR__ . '/src/Social/TelegramProvider.php';

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
            
            // Рандомная задержка
            sleep(rand(4, 42));

            require_once __DIR__ . '/src/LLM/LLMManager.php';
            $llm = new LLMManager();
            $llm->processTrigger('greeting', ['username' => $_SESSION['username'] ?? 'Гость']);

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

        require_once __DIR__ . '/src/Social/TelegramProvider.php';
        
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

            } catch (Exception $e) {
                sendResponse(false, "Ошибка провайдера: " . $e->getMessage(), 'error');
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
             require_once __DIR__ . '/src/CaptchaManager.php';
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
             
             // Даем ИИ время "напечатать" приветствие
             sleep(rand(4, 42));

             // Приветствие от ИИ
             require_once __DIR__ . '/src/LLM/LLMManager.php';
             $llm = new LLMManager();
             $llm->processTrigger('greeting', ['username' => $username]);

             exit();
         } else {
             $newCount = Auth::recordFailedLogin($ip);
             
             // Check if we hit a threshold where captcha needs to be reset to force re-verification
             if ($newCount === 3 || $newCount === 6) {
                 require_once __DIR__ . '/src/CaptchaManager.php';
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
        } catch (Exception $e) {
            sendResponse(false, "Ошибка: " . $e->getMessage(), 'error');
        }
    }

    if ($action === 'reset_password_submit') {
        $token = $_POST['token'] ?? '';
        $password = $_POST['password'] ?? '';
        
        if (empty($token) || empty($password)) {
            sendResponse(false, "Неверные данные", 'error');
        }
        if (mb_strlen($password) < 6) {
            sendResponse(false, "Пароль слишком короткий", 'error');
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
        } catch (Exception $e) {
            sendResponse(false, "Ошибка смены пароля: " . $e->getMessage(), 'error');
        }
    }

    if ($action === 'register') {
        $login = trim($_POST['login'] ?? '');
        $nickname = trim($_POST['nickname'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        
        // 1. Проверка Капчи
        require_once __DIR__ . '/src/CaptchaManager.php';
        $captcha = new CaptchaManager();
        
        if (!$captcha->isCompleted()) {
             sendResponse(false, "Сначала нужно пройти испытание Гармонии!", 'error');
        }

        // 2. Валидация данных
        if (mb_strlen($login) < 3) sendResponse(false, "Логин слишком короткий (нужно хотя бы 3 символа)", 'error');
        if (mb_strlen($password) < 6) sendResponse(false, "Пароль слишком короткий (нужно хотя бы 6 символов)", 'error');
        
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
                
                sleep(rand(4, 42));

                require_once __DIR__ . '/src/LLM/LLMManager.php';
                $llm = new LLMManager();
                $llm->processTrigger('greeting', ['username' => $login]);

                exit();
            } else {
                sendResponse(true, "Ура! Ты с нами! Теперь можно войти.", 'success');
            }
            
        } catch (Exception $e) {
            sendResponse(false, $e->getMessage(), 'error');
        }
    }

    // Protected Actions
    if (!$isLoggedIn && !in_array($action, ['login', 'register', 'forgot_password', 'reset_password_submit', 'social_login', 'get_messages', 'get_stickers', 'get_packs', 'get_public_events'])) { 
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
            } catch (Exception $e) {
                sendResponse(false, "Аватар: " . $e->getMessage(), 'error');
            }
        }
        
        if (!empty($_POST['password'])) {
            if (mb_strlen($_POST['password']) < 6) sendResponse(false, "Пароль слишком короткий", 'error');
            $data['password'] = $_POST['password'];
        }

        $userManager = new UserManager();
        try {
            $userManager->updateProfile($userId, $data);
            if (isset($data['nickname'])) $_SESSION['username'] = $data['nickname'];
            sendResponse(true, "Профиль обновлен!", 'success', ['reload' => true]);
        } catch (Exception $e) {
            sendResponse(false, $e->getMessage(), 'error');
        }
    }


    switch ($action) {
        case 'update_settings':
            $config = ConfigManager::getInstance();
            
            // --- System Settings ---
            if (isset($_POST['debug_mode'])) {
                $config->setOption('debug_mode', (int)$_POST['debug_mode']);
            }

            if (isset($_POST['stream_url'])) {
                $url = trim($_POST['stream_url']);
                // Простейшая валидация
                if (filter_var($url, FILTER_VALIDATE_URL)) {
                    $config->setOption('stream_url', $url);
                    // Не возвращаем сразу, вдруг еще настройки есть
                } else {
                    sendResponse(false, "❌ Некорректный формат ссылки.", 'error');
                }
            }
            
            if (isset($_POST['chat_mode'])) {
                $mode = $_POST['chat_mode'];
                $validModes = ['local', 'none'];
                if (in_array($mode, $validModes)) {
                    $config->setOption('chat_mode', $mode);
                }
            }
            
            if (isset($_POST['chat_rate_limit'])) {
                $limit = (int)$_POST['chat_rate_limit'];
                if ($limit < 0) $limit = 0;
                $config->setOption('chat_rate_limit', $limit);
            }
            
            // Telegram Settings
            // В форме есть hidden input, так что ключ всегда придет, если это форма Telegram.
            // Если сохраняем другую форму, ключа не будет, и настройку не трогаем.
            if (isset($_POST['telegram_auth_enabled'])) {
                $config->setOption('telegram_auth_enabled', (int)$_POST['telegram_auth_enabled']);
            }
            
            if (isset($_POST['telegram_bot_token'])) {
                $config->setOption('telegram_bot_token', trim($_POST['telegram_bot_token']));
            }
            if (isset($_POST['telegram_bot_username'])) {
                $config->setOption('telegram_bot_username', trim($_POST['telegram_bot_username']));
            }

            // AI Settings
            if (isset($_POST['ai_bot_user_id'])) {
                $config->setOption('ai_bot_user_id', (int)$_POST['ai_bot_user_id']);
            }
            if (isset($_POST['ai_enabled'])) {
                $config->setOption('ai_enabled', (int)$_POST['ai_enabled']);
            }
            if (isset($_POST['ai_system_prompt'])) {
                $config->setOption('ai_system_prompt', trim($_POST['ai_system_prompt']));
            }
            if (isset($_POST['ai_aliases'])) {
                $config->setOption('ai_aliases', trim($_POST['ai_aliases']));
            }
            if (isset($_POST['ai_proxy_url'])) {
                $config->setOption('ai_proxy_url', trim($_POST['ai_proxy_url']));
            }
            if (isset($_POST['ai_primary_provider'])) {
                $config->setOption('ai_primary_provider', trim($_POST['ai_primary_provider']));
            }
            
            // AI Providers
            if (isset($_POST['ai_openai_key'])) {
                $config->setOption('ai_openai_key', trim($_POST['ai_openai_key']));
            }
            if (isset($_POST['ai_openai_base_url'])) {
                $config->setOption('ai_openai_base_url', trim($_POST['ai_openai_base_url']));
            }
            if (isset($_POST['ai_openai_model'])) {
                $config->setOption('ai_openai_model', trim($_POST['ai_openai_model']));
            }
            
            if (isset($_POST['ai_openrouter_key'])) {
                $config->setOption('ai_openrouter_key', trim($_POST['ai_openrouter_key']));
            }
            if (isset($_POST['ai_openrouter_model'])) {
                $config->setOption('ai_openrouter_model', trim($_POST['ai_openrouter_model']));
            }

            if (isset($_POST['ai_yandex_key'])) {
                $config->setOption('ai_yandex_key', trim($_POST['ai_yandex_key']));
            }
            if (isset($_POST['ai_yandex_folder_id'])) {
                $config->setOption('ai_yandex_folder_id', trim($_POST['ai_yandex_folder_id']));
            }

            if (isset($_POST['ai_gigachat_key'])) {
                $config->setOption('ai_gigachat_key', trim($_POST['ai_gigachat_key']));
            }

            // SMTP Settings
            if (isset($_POST['smtp_enabled'])) {
                $config->setOption('smtp_enabled', (int)$_POST['smtp_enabled']);
            }
            if (isset($_POST['smtp_host'])) {
                $config->setOption('smtp_host', trim($_POST['smtp_host']));
            }
            if (isset($_POST['smtp_port'])) {
                $config->setOption('smtp_port', (int)$_POST['smtp_port']);
            }
            if (isset($_POST['smtp_user'])) {
                $config->setOption('smtp_user', trim($_POST['smtp_user']));
            }
            if (isset($_POST['smtp_pass'])) {
                $config->setOption('smtp_pass', trim($_POST['smtp_pass']));
            }
            if (isset($_POST['smtp_from_name'])) {
                $config->setOption('smtp_from_name', trim($_POST['smtp_from_name']));
            }
            
            sendResponse(true, "✅ Настройки обновлены!");
            break;

        case 'regenerate_playlist':
            $playlist = $manager->regeneratePlaylist();
            sendResponse(true, "🎲 Новый плейлист успешно сгенерирован и сохранен!", 'success', ['reload' => true]);
            break;

        case 'vote':
            if (!empty($_POST['episode_id'])) {
                $manager->voteForEpisode($_POST['episode_id']);
                sendResponse(true, "✅ Голос за эпизод #{$_POST['episode_id']} принят!");
            } else {
                sendResponse(false, "❌ Не указан ID эпизода.", 'error');
            }
            break;

        case 'mark_watched':
            if (!empty($_POST['ids'])) {
                $ids = explode(',', $_POST['ids']);
                $ids = array_filter($ids, 'is_numeric');
                if (!empty($ids)) {
                    $manager->markAsWatched($ids);
                    
                    // Сразу генерируем новый плейлист на следующий раз
                    $manager->regeneratePlaylist();
                    
                    sendResponse(true, "✅ Плейлист отмечен и сгенерирован новый!", 'success', ['reload' => true]);
                } else {
                    sendResponse(false, "❌ Некорректный список ID.", 'error');
                }
            }
            break;

        case 'clear_votes':
            $manager->clearWannaWatch();
            sendResponse(true, "🗑️ Все голоса (Wanna Watch) сброшены.");
            break;

        case 'reset_times_watched':
            $manager->resetTimesWatched();
            sendResponse(true, "🔄 Счетчики просмотров (TIMES_WATCHED) сброшены!");
            break;

        case 'clear_watching_log':
            $manager->clearWatchingNowLog();
            sendResponse(true, "🗑️ Лог истории просмотров очищен.");
            break;

        case 'logout':
            Auth::logout();
            sendResponse(true, "До скорой встречи!", 'success', ['reload' => true]); 
            break;

        // --- User Management (Admin Only) ---
        case 'get_users':
            if (!Auth::isAdmin()) sendResponse(false, "Access Denied", 'error');
            
            $userManager = new UserManager();
            $users = $userManager->getAllUsers(); // Now returns users with chat_color and avatar_url joined
            sendResponse(true, "Список получен", 'success', ['users' => $users]);
            break;

        case 'get_user_options':
            if (!Auth::isAdmin()) sendResponse(false, "Access Denied", 'error');
            
            $targetUserId = (int)($_POST['user_id'] ?? 0);
            if (!$targetUserId) sendResponse(false, "ID не указан", 'error');
            
            $userManager = new UserManager();
            $options = $userManager->getUserOptions($targetUserId);
            
            sendResponse(true, "Опции получены", 'success', ['options' => $options]);
            break;

        case 'get_audit_logs':
            if (!Auth::isAdmin()) sendResponse(false, "Access Denied", 'error');
            
            $userManager = new UserManager();
            $logs = $userManager->getAuditLogs();
            
            // Format dates for JS
            foreach ($logs as &$log) {
                 if ($log['created_at']) {
                    $log['created_at'] = date('Y-m-d H:i:s', strtotime($log['created_at']));
                }
            }
            
            sendResponse(true, "Логи получены", 'success', ['logs' => $logs]);
            break;

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

        case 'save_user':
            if (!Auth::isAdmin()) sendResponse(false, "Access Denied", 'error');
            
            $userManager = new UserManager();
            $id = $_POST['user_id'] ?? ''; 
            $login = trim($_POST['login'] ?? '');
            $nickname = trim($_POST['nickname'] ?? '');
            $role = $_POST['role'] ?? 'user';
            $password = $_POST['password'] ?? '';
            
            // New fields & Uploads
            $chat_color = trim($_POST['chat_color'] ?? '');
            $font_preference = trim($_POST['font_preference'] ?? 'open_sans');
            $font_scale = (int)($_POST['font_scale'] ?? 100);
            $raw_avatar_url = trim($_POST['avatar_url'] ?? '');
            $avatar_url = $raw_avatar_url; // Default
            
            try {
                $uploadManager = new UploadManager();
                // 1. File
                if (isset($_FILES['avatar_file']) && $_FILES['avatar_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $avatar_url = $uploadManager->uploadFromPost($_FILES['avatar_file']);
                }
                // 2. URL Download
                elseif (!empty($raw_avatar_url) && strpos($raw_avatar_url, '/upload/avatars/') !== 0 && filter_var($raw_avatar_url, FILTER_VALIDATE_URL)) {
                    $avatar_url = $uploadManager->uploadFromUrl($raw_avatar_url);
                }
            } catch (Exception $e) {
                sendResponse(false, "Аватар: " . $e->getMessage(), 'error');
            }
            
            if (empty($login)) sendResponse(false, "Логин обязателен", 'error');
            if (empty($nickname)) $nickname = $login; 
            
            try {
                $data = [
                    'login' => $login,
                    'nickname' => $nickname,
                    'role' => $role,
                    'avatar_url' => $avatar_url,
                    'chat_color' => $chat_color,
                    'font_preference' => $font_preference,
                    'font_scale' => $font_scale
                ];

                if (!empty($id)) {
                    // Update
                    if (!empty($password)) {
                        if (mb_strlen($password) < 6) sendResponse(false, "Пароль слишком короткий", 'error');
                        $data['password'] = $password;
                    }
                    
                    // UserManager::updateUser will handle option splitting internally!
                    $userManager->updateUser($id, $data);
                    sendResponse(true, "Пользователь обновлен");
                } else {
                    // Create
                    if (empty($password)) sendResponse(false, "Для нового пользователя нужен пароль", 'error');
                    if (mb_strlen($password) < 6) sendResponse(false, "Пароль слишком короткий", 'error');
                    
                    $newId = $userManager->createUser($login, $password, $role, $nickname);
                    
                    // Update extra fields (options)
                    // We can reuse updateUser logic or just call it directly for options
                    $userManager->updateUser($newId, [
                        'avatar_url' => $avatar_url,
                        'chat_color' => $chat_color,
                        'font_preference' => $font_preference,
                        'font_scale' => $font_scale
                    ]);
                    
                    sendResponse(true, "Пользователь создан");
                }
            } catch (Exception $e) {
                sendResponse(false, $e->getMessage(), 'error');
            }
            break;

        case 'delete_user':
            if (!Auth::isAdmin()) sendResponse(false, "Access Denied", 'error');
            
            $id = $_POST['user_id'] ?? '';
            if (empty($id)) sendResponse(false, "ID не указан", 'error');
            
            // Не даем удалить самого себя
            if ($id == $_SESSION['user_id']) {
                sendResponse(false, "Нельзя удалить самого себя!", 'error');
            }
            
            $userManager = new UserManager();
            if ($userManager->deleteUser($id)) {
                sendResponse(true, "Пользователь удален");
            } else {
                sendResponse(false, "Ошибка удаления", 'error');
            }
            break;

        // --- Moderation Actions ---
        
        case 'ban_user':
            if (!Auth::isModerator()) sendResponse(false, "Недостаточно прав!", 'error');
            
            $targetId = (int)($_POST['user_id'] ?? 0);
            $reason = trim($_POST['reason'] ?? 'Нарушение правил');
            
            if (!$targetId) sendResponse(false, "Не указан ID пользователя", 'error');
            
            // Hierarchy Check
            $check = checkHierarchy($targetId);
            if ($check !== true) sendResponse(false, $check, 'error');
            
            $userManager = new UserManager();
            if ($userManager->banUser($targetId, $reason, $_SESSION['user_id'])) {
                sendResponse(true, "Пользователь забанен! 🔨");
            } else {
                sendResponse(false, "Ошибка при бане пользователя.", 'error');
            }
            break;

        case 'unban_user':
            if (!Auth::isModerator()) sendResponse(false, "Недостаточно прав!", 'error');
            
            $targetId = (int)($_POST['user_id'] ?? 0);
            if (!$targetId) sendResponse(false, "Не указан ID пользователя", 'error');

            // Hierarchy Check
            $check = checkHierarchy($targetId);
            if ($check !== true) sendResponse(false, $check, 'error');

            $userManager = new UserManager();
            if ($userManager->unbanUser($targetId, $_SESSION['user_id'])) {
                sendResponse(true, "Пользователь разбанен! 🕊️");
            } else {
                sendResponse(false, "Ошибка при разбане.", 'error');
            }
            break;

        case 'mute_user':
            if (!Auth::isModerator()) sendResponse(false, "Недостаточно прав!", 'error');
            
            $targetId = (int)($_POST['user_id'] ?? 0);
            $minutes = (int)($_POST['minutes'] ?? 15);
            $reason = trim($_POST['reason'] ?? 'Нарушение правил');
            
            if (!$targetId) sendResponse(false, "Не указан ID пользователя", 'error');
            
            // Hierarchy Check
            $check = checkHierarchy($targetId);
            if ($check !== true) sendResponse(false, $check, 'error');
            
            if ($minutes < 1) $minutes = 15;
            
            $userManager = new UserManager();
            if ($userManager->muteUser($targetId, $minutes, $_SESSION['user_id'], $reason)) {
                sendResponse(true, "Пользователь заглушен на $minutes мин. 🤐");
            } else {
                sendResponse(false, "Ошибка при муте.", 'error');
            }
            break;
            
        case 'unmute_user':
             if (!Auth::isModerator()) sendResponse(false, "Недостаточно прав!", 'error');
            
            $targetId = (int)($_POST['user_id'] ?? 0);
            if (!$targetId) sendResponse(false, "Не указан ID пользователя", 'error');

            // Hierarchy Check
            $check = checkHierarchy($targetId);
            if ($check !== true) sendResponse(false, $check, 'error');

            $userManager = new UserManager();
            if ($userManager->unmuteUser($targetId, $_SESSION['user_id'])) {
                sendResponse(true, "Голос возвращен! 🗣️");
            } else {
                sendResponse(false, "Ошибка при снятии мута.", 'error');
            }
            break;

        case 'purge_messages':
            if (!Auth::isModerator()) sendResponse(false, "Недостаточно прав!", 'error');
            
            $targetId = (int)($_POST['user_id'] ?? 0);
            $count = (int)($_POST['count'] ?? 50);
            if (!$targetId) sendResponse(false, "Не указан ID пользователя", 'error');
            
            // Hierarchy Check
            $check = checkHierarchy($targetId);
            if ($check !== true) sendResponse(false, $check, 'error');

            if ($count > 100) $count = 100;
            if ($count < 1) $count = 1;
            
            $chat = new ChatManager();
            $deletedCount = $chat->purgeMessages($targetId, $count);
            
            $userManager = new UserManager();
            $userManager->logAction($_SESSION['user_id'], 'purge', $targetId, "Deleted $deletedCount messages");
            
            sendResponse(true, "Удалено $deletedCount сообщений! 🧹");
            break;

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
                
                // Снимаем лимит времени выполнения
                set_time_limit(0);
                ignore_user_abort(true);
                
                // Даем ИИ случайное время "на подумать" от 4 до 42 секунд
                sleep(rand(4, 42));

                // Вызываем магию ИИ
                require_once __DIR__ . '/src/LLM/LLMManager.php';
                $llm = new LLMManager();
                
                if (preg_match('/^\/?(schedule|расписание)/ui', $message)) {
                    $llm->processTrigger('schedule_command', [
                        'message' => $message,
                        'message_id' => $newMsgId === true ? null : $newMsgId
                    ]);
                } else {
                    $llm->processTrigger('mention', [
                        'message' => $message, 
                        'message_id' => $newMsgId === true ? null : $newMsgId,
                        'quoted_msg_ids' => $quotedMsgIds
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
            } catch (Exception $e) {
                sendResponse(false, $e->getMessage(), 'error');
            }
            break;

        // --- Stickers ---

        case 'get_packs':
            $sm = new StickerManager();
            $packs = $sm->getAllPacks();
            sendResponse(true, "Паки получены", 'success', ['packs' => $packs]);
            break;

        case 'create_pack':
            if (!Auth::isAdmin()) sendResponse(false, "Access Denied", 'error');
            $code = trim($_POST['code'] ?? '');
            $name = trim($_POST['name'] ?? '');
            $iconUrl = null;
            
            if (empty($code) || empty($name)) sendResponse(false, "Код и имя обязательны", 'error');
            
            try {
                // Upload Icon if provided
                if (isset($_FILES['icon_file']) && $_FILES['icon_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $uploadManager = new UploadManager('icon');
                    $iconUrl = $uploadManager->uploadFromPost($_FILES['icon_file']);
                }

                $sm = new StickerManager();
                if ($sm->createPack($code, $name, $iconUrl)) {
                    sendResponse(true, "Пак создан! 🎉");
                } else {
                    sendResponse(false, "Ошибка (возможно, такой код уже есть)", 'error');
                }
            } catch (Exception $e) {
                sendResponse(false, $e->getMessage(), 'error');
            }
            break;

        case 'update_pack':
            if (!Auth::isAdmin()) sendResponse(false, "Access Denied", 'error');
            $id = (int)($_POST['id'] ?? 0);
            $code = trim($_POST['code'] ?? '');
            $name = trim($_POST['name'] ?? '');
            $iconUrl = null;
            
            if (!$id || empty($code) || empty($name)) sendResponse(false, "Данные неполные", 'error');
            
            try {
                // Upload Icon if provided
                if (isset($_FILES['icon_file']) && $_FILES['icon_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $uploadManager = new UploadManager('icon');
                    $iconUrl = $uploadManager->uploadFromPost($_FILES['icon_file']);
                }

                $sm = new StickerManager();
                if ($sm->updatePack($id, $code, $name, $iconUrl)) {
                    sendResponse(true, "Пак обновлен!");
                } else {
                    sendResponse(false, "Ошибка обновления", 'error');
                }
            } catch (Exception $e) {
                sendResponse(false, $e->getMessage(), 'error');
            }
            break;

        case 'delete_pack':
            if (!Auth::isAdmin()) sendResponse(false, "Access Denied", 'error');
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) sendResponse(false, "ID не указан", 'error');
            
            $sm = new StickerManager();
            if ($sm->deletePack($id)) {
                sendResponse(true, "Пак и все его стикеры удалены 🗑️");
            } else {
                sendResponse(false, "Ошибка удаления", 'error');
            }
            break;

        case 'get_stickers':
            $sm = new StickerManager();
            $stickers = $sm->getAllStickers(true);
            sendResponse(true, "Стикеры получены", 'success', ['stickers' => $stickers]);
            break;

        case 'add_sticker':
            if (!Auth::isAdmin()) sendResponse(false, "Access Denied", 'error');
            
            $code = trim($_POST['code'] ?? '');
            $packId = (int)($_POST['pack_id'] ?? 0);
            $url = trim($_POST['image_url'] ?? '');
            
            if (empty($code)) sendResponse(false, "Код обязателен", 'error');
            if (!$packId) sendResponse(false, "Выберите пак!", 'error');

            try {
                $uploadManager = new UploadManager('sticker');
                
                // 1. File Upload
                if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $url = $uploadManager->uploadFromPost($_FILES['image_file']);
                }
                // 2. URL Download
                elseif (!empty($url) && strpos($url, '/upload/stickers/') !== 0 && filter_var($url, FILTER_VALIDATE_URL)) {
                     $url = $uploadManager->uploadFromUrl($url);
                }

                if (empty($url)) sendResponse(false, "Нужно загрузить файл или указать ссылку", 'error');

                $sm = new StickerManager();
                $id = $sm->addSticker($code, $url, $packId);
                sendResponse(true, "Стикер :$code: добавлен!", 'success', ['id' => $id, 'url' => $url]);
                
            } catch (Exception $e) {
                sendResponse(false, $e->getMessage(), 'error');
            }
            break;

        case 'import_zip_stickers':
            if (!Auth::isAdmin()) sendResponse(false, "Access Denied", 'error');
            
            $packId = (int)($_POST['pack_id'] ?? 0);
            if (!$packId) sendResponse(false, "Пак не выбран", 'error');
            if (!isset($_FILES['zip_file'])) sendResponse(false, "Архив не загружен", 'error');

            try {
                $file = $_FILES['zip_file'];
                if ($file['error'] !== UPLOAD_ERR_OK) throw new Exception("Ошибка загрузки файла");
                if (pathinfo($file['name'], PATHINFO_EXTENSION) !== 'zip') throw new Exception("Только ZIP архивы!");

                $sm = new StickerManager();
                $count = $sm->importFromZip($packId, $file['tmp_name']);
                
                sendResponse(true, "Успешно импортировано $count стикеров! 📦✨");
            } catch (Exception $e) {
                sendResponse(false, "ZIP Import Error: " . $e->getMessage(), 'error');
            }
            break;

        case 'delete_sticker':
            if (!Auth::isAdmin()) sendResponse(false, "Access Denied", 'error');
            
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) sendResponse(false, "ID не указан", 'error');
            
            $sm = new StickerManager();
            if ($sm->deleteSticker($id)) {
                sendResponse(true, "Стикер удален 🗑️");
            } else {
                sendResponse(false, "Ошибка удаления", 'error');
            }
            break;
            
        case 'save_event':
            if (!Auth::isAdmin()) sendResponse(false, "Access Denied", 'error');
            
            $id = (int)($_POST['id'] ?? 0);
            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $start_time_utc = trim($_POST['start_time_utc'] ?? '');
            $duration_minutes = (int)($_POST['duration_minutes'] ?? 60);
            $is_recurring = (int)($_POST['is_recurring'] ?? 0);
            $recurrence_rule = trim($_POST['recurrence_rule'] ?? '');
            $use_playlist = (int)($_POST['use_playlist'] ?? 0);
            $generate_new_playlist = (int)($_POST['generate_new_playlist'] ?? 0);
            $color = trim($_POST['color'] ?? '#6d2f8e');

            if (empty($title) || empty($start_time_utc)) {
                sendResponse(false, "Заголовок и время начала обязательны", 'error');
            }

            if ($duration_minutes < 1) $duration_minutes = 60;

            $db = Database::getInstance()->getConnection();

            // Валидация: Только одно регулярное событие может генерировать/использовать плейлист
            if ($is_recurring && ($use_playlist || $generate_new_playlist)) {
                $checkQuery = "SELECT id FROM events WHERE is_recurring = 1 AND (use_playlist = 1 OR generate_new_playlist = 1)";
                if ($id > 0) {
                    $checkQuery .= " AND id != " . $id;
                }
                $res = $db->query($checkQuery);
                if ($res && $res->num_rows > 0) {
                    sendResponse(false, "Уже есть другое регулярное событие, работающее с плейлистами!", 'error');
                }
            }

            if ($id > 0) {
                // Update
                $stmt = $db->prepare("UPDATE events SET title=?, description=?, start_time=?, duration_minutes=?, is_recurring=?, recurrence_rule=?, use_playlist=?, generate_new_playlist=?, color=? WHERE id=?");
                $stmt->bind_param("sssiisissi", $title, $description, $start_time_utc, $duration_minutes, $is_recurring, $recurrence_rule, $use_playlist, $generate_new_playlist, $color, $id);
                if ($stmt->execute()) {
                    sendResponse(true, "Событие обновлено!");
                } else {
                    sendResponse(false, "Ошибка обновления события", 'error');
                }
            } else {
                // Insert
                $stmt = $db->prepare("INSERT INTO events (title, description, start_time, duration_minutes, is_recurring, recurrence_rule, use_playlist, generate_new_playlist, color) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sssiisiss", $title, $description, $start_time_utc, $duration_minutes, $is_recurring, $recurrence_rule, $use_playlist, $generate_new_playlist, $color);
                if ($stmt->execute()) {
                    sendResponse(true, "Событие добавлено!");
                } else {
                    sendResponse(false, "Ошибка создания события", 'error');
                }
            }
            break;

        case 'delete_event':
            if (!Auth::isAdmin()) sendResponse(false, "Access Denied", 'error');
            
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) sendResponse(false, "ID не указан", 'error');
            
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("DELETE FROM events WHERE id=?");
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                sendResponse(true, "Событие удалено!");
            } else {
                sendResponse(false, "Ошибка удаления события", 'error');
            }
            break;

        case 'get_public_events':
            // Public endpoint
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT id, title, description, start_time, duration_minutes, is_recurring, recurrence_rule, use_playlist, color FROM events ORDER BY start_time ASC");
            $stmt->execute();
            $res = $stmt->get_result();
            
            $events = [];
            while ($row = $res->fetch_assoc()) {
                $events[] = $row;
            }

            // Send current playlist if required
            $manager = new EpisodeManager();
            $playlistHtml = '';
            
            // To make it simple, we just send the raw array of playlist for the frontend to render, or pre-rendered HTML
            $playlist = $manager->getSavedPlaylist(); 
            // wait, getSavedPlaylist returns episodes. The frontend can render them.
            
            sendResponse(true, "События загружены", 'success', ['events' => $events, 'playlist' => $playlist]);
            break;
            
        default:
            sendResponse(false, "❌ Неизвестное действие: $action", 'error');
    }

} catch (Exception $e) {
    sendResponse(false, "💥 Ошибка сервера: " . $e->getMessage(), 'error');
}
