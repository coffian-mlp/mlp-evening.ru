<?php

// Отключаем вывод ошибок в поток вывода, чтобы не ломать JSON
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/src/EpisodeManager.php';
require_once __DIR__ . '/src/ConfigManager.php';
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
        
        // If user is logged in, we MUST verify token.
        // Exception: 'login' action might be called while session exists (e.g. re-login?) - though usually we logout first.
        // Let's rely on Auth::checkCsrfToken returning false if token is empty.
        
        if ($isLoggedIn && !Auth::checkCsrfToken($csrfToken)) {
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

    // Public Actions
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
            sendResponse(true, $result['message'], 'success', ['redirect' => $result['redirect']]);
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
                $stmt = $db->prepare("INSERT INTO user_socials (user_id, provider, provider_uid, username, first_name, last_name, avatar_url) VALUES (?, 'telegram', ?, ?, ?, ?, ?)");
                // 6 вопросительных знаков = 6 переменных = 'isssss'
                $stmt->bind_param("isssss", 
                    $userId, 
                    $tgUser['id'], 
                    $tgUser['username'], 
                    $tgUser['first_name'], 
                    $tgUser['last_name'], 
                    $tgUser['photo_url']
                );
                
                if ($stmt->execute()) {
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
         
         if (Auth::login($username, $password)) {
             sendResponse(true, "Добро пожаловать, $username! Рады тебя видеть!", 'success', ['reload' => true]);
         } else {
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
                sendResponse(true, "Ура! Ты с нами! Добро пожаловать!", 'success', ['reload' => true]);
            } else {
                sendResponse(true, "Ура! Ты с нами! Теперь можно войти.", 'success');
            }
            
        } catch (Exception $e) {
            sendResponse(false, $e->getMessage(), 'error');
        }
    }

    // Protected Actions
    if (!$isLoggedIn && !in_array($action, ['login', 'register', 'forgot_password', 'reset_password_submit', 'social_login', 'get_messages', 'get_stickers', 'get_packs'])) { 
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
                    'chat_color' => $chat_color
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
                        'chat_color' => $chat_color
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
            if ($targetId == $_SESSION['user_id']) sendResponse(false, "Себя банить нельзя!", 'error');
            
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

            if ($chat->addMessage($userId, $username, $message, $quotedMsgIds)) {
                sendResponse(true, "Сообщение отправлено");
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
            $canModerate = Auth::isModerator();
            
            if ($chat->deleteMessage($messageId, $_SESSION['user_id'], $canModerate)) {
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
            $canModerate = Auth::isModerator();
            
            if ($chat->restoreMessage($messageId, $_SESSION['user_id'], $canModerate)) {
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
            
        default:
            sendResponse(false, "❌ Неизвестное действие: $action", 'error');
    }

} catch (Exception $e) {
    sendResponse(false, "💥 Ошибка сервера: " . $e->getMessage(), 'error');
}
