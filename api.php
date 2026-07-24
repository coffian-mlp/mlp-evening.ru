<?php
use Domain\Auth;
use Infra\ConfigManager;

require_once __DIR__ . '/autoload.php'; // MLP-248: классы — только автозагрузкой

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

    // --- Тонкий роутер (MLP-229/255): action → роль → контроллер.
    // Карта — в src/Api/routes.php (отдельный файл, чтобы её видели тесты).
    $apiRoutes = require __DIR__ . '/src/Api/routes.php';

    // Гостевой гейт (MLP-282, AR7-2): доступ без логина ТОЛЬКО к public-маршрутам
    // карты — единственный источник истины, ручной whitelist-список упразднён.
    if (!$isLoggedIn && ($apiRoutes[$action]['role'] ?? '') !== 'public') {
         Auth::requireApiLogin();
    }
    if (isset($apiRoutes[$action])) {
        $route = $apiRoutes[$action];
        if ($route['role'] === 'admin') {
            Auth::requireApiAdmin();
        } elseif ($route['role'] === 'moderator') {
            // MLP-255: третий уровень иерархии user < moderator < admin.
            Auth::requireApiLogin();
            if (!Auth::isModerator()) \Api\Response::json(false, "Недостаточно прав!", 'error');
        } elseif ($route['role'] === 'user') {
            Auth::requireApiLogin();
        }
        call_user_func($route['handler']); // хендлер отвечает через Api\Response и завершает
        exit();
    }

    // MLP-265: switch пуст — ВСЕ actions живут в тонком роутере (src/Api/routes.php).
    \Api\Response::json(false, "❌ Неизвестное действие: $action", 'error');

} catch (Throwable $e) {
    // L1: детали — в лог, наружу — общий текст (Throwable: ловим и TypeError/mysqli, MLP-261)
    error_log('api.php exception: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    \Api\Response::json(false, "💥 Ошибка сервера. Попробуйте позже.", 'error');
}
