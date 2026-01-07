<?php

// Отключаем вывод ошибок в поток вывода, чтобы не ломать JSON
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/src/EpisodeManager.php';
require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/ChatManager.php';
require_once __DIR__ . '/src/UserManager.php';
require_once __DIR__ . '/src/UploadManager.php';

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
    if ($action === 'login') {
         $username = $_POST['username'] ?? '';
         $password = $_POST['password'] ?? '';
         
         if (Auth::login($username, $password)) {
             sendResponse(true, "Добро пожаловать, $username! Рады тебя видеть!", 'success', ['reload' => true]);
         } else {
             sendResponse(false, "Упс! Неверное имя или пароль.", 'error');
         }
    }

    if ($action === 'register') {
        $login = trim($_POST['login'] ?? '');
        $nickname = trim($_POST['nickname'] ?? '');
        $password = $_POST['password'] ?? '';
        $captcha = mb_strtolower(trim($_POST['captcha'] ?? ''), 'UTF-8');
        
        // 1. Валидация Капчи
        $validAnswers = ['спайк', 'spike', 'дракончик спайк', 'спайк дракончик'];
        if (!in_array($captcha, $validAnswers)) {
            sendResponse(false, "Неверный ответ на вопрос про дракончика! Попробуй еще раз.", 'error');
        }

        // 2. Валидация данных
        if (mb_strlen($login) < 3) sendResponse(false, "Логин слишком короткий (нужно хотя бы 3 символа)", 'error');
        if (mb_strlen($password) < 6) sendResponse(false, "Пароль слишком короткий (нужно хотя бы 6 символов)", 'error');
        
        // 3. Создание пользователя
        $userManager = new UserManager();
        try {
            // Создаем обычного пользователя (role='user')
            $userManager->createUser($login, $password, 'user', $nickname);
            
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
    if (!$isLoggedIn && $action !== 'login' && $action !== 'register') { 
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
            if (isset($_POST['stream_url'])) {
                $url = trim($_POST['stream_url']);
                // Простейшая валидация
                if (filter_var($url, FILTER_VALIDATE_URL)) {
                    $manager->setOption('stream_url', $url);
                    // Не возвращаем сразу, вдруг еще настройки есть
                } else {
                    sendResponse(false, "❌ Некорректный формат ссылки.", 'error');
                }
            }
            
            if (isset($_POST['chat_mode'])) {
                $mode = $_POST['chat_mode'];
                $validModes = ['local', 'chatbro', 'none'];
                if (in_array($mode, $validModes)) {
                    $manager->setOption('chat_mode', $mode);
                }
            }
            
            if (isset($_POST['chat_rate_limit'])) {
                $limit = (int)$_POST['chat_rate_limit'];
                if ($limit < 0) $limit = 0;
                $manager->setOption('chat_rate_limit', $limit);
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
            $users = $userManager->getAllUsers();
            sendResponse(true, "Список получен", 'success', ['users' => $users]);
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
                if (!empty($id)) {
                    // Update
                    $data = [
                        'login' => $login,
                        'nickname' => $nickname,
                        'role' => $role,
                        'avatar_url' => $avatar_url,
                        'chat_color' => $chat_color
                    ];
                    if (!empty($password)) {
                        if (mb_strlen($password) < 6) sendResponse(false, "Пароль слишком короткий", 'error');
                        $data['password'] = $password;
                    }
                    
                    $userManager->updateUser($id, $data);
                    sendResponse(true, "Пользователь обновлен");
                } else {
                    // Create
                    if (empty($password)) sendResponse(false, "Для нового пользователя нужен пароль", 'error');
                    if (mb_strlen($password) < 6) sendResponse(false, "Пароль слишком короткий", 'error');
                    
                    $newId = $userManager->createUser($login, $password, $role, $nickname);
                    
                    // Update extra fields
                    if (!empty($avatar_url) || !empty($chat_color)) {
                        $userManager->updateUser($newId, [
                            'avatar_url' => $avatar_url,
                            'chat_color' => $chat_color
                        ]);
                    }
                    
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

        case 'send_message':
            $message = $_POST['message'] ?? '';
            if (empty($message)) {
                sendResponse(false, "Эй, сообщение не может быть пустым!", 'error');
            }
            
            // Assuming user is logged in because of the check above
            $userId = $_SESSION['user_id'];
            $username = $_SESSION['username'];
            
            $chat = new ChatManager();
            $manager = new EpisodeManager(); // Need to get option
            $rateLimit = (int)$manager->getOption('chat_rate_limit', 0);
            
            if (!$chat->checkRateLimit($userId, $rateLimit)) {
                sendResponse(false, "Не так быстро, сахарок! Подожди $rateLimit сек.", 'error');
            }

            if ($chat->addMessage($userId, $username, $message)) {
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
            
        default:
            sendResponse(false, "❌ Неизвестное действие: $action", 'error');
    }

} catch (Exception $e) {
    sendResponse(false, "💥 Ошибка сервера: " . $e->getMessage(), 'error');
}