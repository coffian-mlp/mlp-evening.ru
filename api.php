<?php

// Отключаем вывод ошибок в поток вывода, чтобы не ломать JSON
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/src/EpisodeManager.php';
require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/ChatManager.php';
require_once __DIR__ . '/src/UserManager.php';

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
        $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        // If user is logged in, we MUST verify token.
        if ($isLoggedIn && !Auth::checkCsrfToken($csrfToken)) {
             echo json_encode([
                'success' => false, 
                'message' => 'CSRF Token Mismatch: Обновите страницу.', 
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

    // Protected Actions
    if (!$isLoggedIn && $action !== 'login') { // Allow 'login' or other public actions later
         // For now, most actions require login
         Auth::requireApiLogin(); 
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
            $id = $_POST['user_id'] ?? ''; // Если пусто - создание, если есть - редактирование
            $login = trim($_POST['login'] ?? '');
            $nickname = trim($_POST['nickname'] ?? '');
            $role = $_POST['role'] ?? 'user';
            $password = $_POST['password'] ?? '';
            
            if (empty($login)) sendResponse(false, "Логин обязателен", 'error');
            if (empty($nickname)) $nickname = $login; // Fallback
            
            try {
                if (!empty($id)) {
                    // Update
                    $userManager->updateUser($id, $login, $nickname, $role, $password); // Пароль может быть пустым
                    sendResponse(true, "Пользователь обновлен");
                } else {
                    // Create
                    if (empty($password)) sendResponse(false, "Для нового пользователя нужен пароль", 'error');
                    $userManager->createUser($login, $password, $role, $nickname);
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
            
        default:
            sendResponse(false, "❌ Неизвестное действие: $action", 'error');
    }

} catch (Exception $e) {
    sendResponse(false, "💥 Ошибка сервера: " . $e->getMessage(), 'error');
}