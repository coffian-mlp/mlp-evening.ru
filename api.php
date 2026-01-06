<?php

// Отключаем вывод ошибок в поток вывода, чтобы не ломать JSON
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/src/EpisodeManager.php';
require_once __DIR__ . '/src/Auth.php';

header('Content-Type: application/json');

// Перехват фатальных ошибок
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE)) {
        echo json_encode(['success' => false, 'message' => 'Fatal Error: ' . $error['message'], 'type' => 'error']);
    }
});

try {
    // 🔒 ЗАЩИТА: API доступен только авторизованным
    Auth::requireApiLogin();

    // 🛡️ ЗАЩИТА: Проверка CSRF токена
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!Auth::checkCsrfToken($csrfToken)) {
        echo json_encode([
            'success' => false, 
            'message' => 'CSRF Token Mismatch: Обновите страницу и попробуйте снова.', 
            'type' => 'error'
        ]);
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => 'Only POST requests allowed', 'type' => 'error']);
        exit();
    }

    $action = $_POST['action'] ?? '';
    $manager = new EpisodeManager();

    function sendResponse($success, $message, $type = 'success', $data = []) {
        echo json_encode([
            'success' => $success,
            'message' => $message,
            'type' => $type,
            'data' => $data
        ]);
        exit();
    }

    switch ($action) {
        case 'update_settings':
            if (isset($_POST['stream_url'])) {
                $url = trim($_POST['stream_url']);
                // Простейшая валидация
                if (filter_var($url, FILTER_VALIDATE_URL)) {
                    $manager->setOption('stream_url', $url);
                    sendResponse(true, "✅ Ссылка на стрим обновлена!");
                } else {
                    sendResponse(false, "❌ Некорректный формат ссылки.", 'error');
                }
            }
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
            sendResponse(true, "До встречи!", 'success', ['reload' => true]); 
            break;
            
        default:
            sendResponse(false, "❌ Неизвестное действие: $action", 'error');
    }

} catch (Exception $e) {
    sendResponse(false, "💥 Ошибка сервера: " . $e->getMessage(), 'error');
}