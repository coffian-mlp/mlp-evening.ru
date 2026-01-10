<?php
// chat_stream.php - SSE endpoint for chat messages

require_once __DIR__ . '/src/ChatManager.php';
require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/UserManager.php';

// Disable time limit for long-running script (or set to reasonable value like 60s for shared hosting)
set_time_limit(0); 

// 🛑 Disable Compression / Buffering for SSE
@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1);
ob_implicit_flush(1);

// Headers for SSE
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Nginx specific: disable buffering

// 🚀 Padding to flush initial buffer (helps with some browsers/proxies)
echo ":" . str_repeat(" ", 2048) . "\n\n";
flush();

// 🔒 Optional: Restrict access to logged in users
$isLoggedIn = Auth::check();
$currentUserId = null;

if ($isLoggedIn) {
    $currentUserId = $_SESSION['user_id'];
} else {
    // Если мы хотим разрешить чтение всем, то просто убираем этот блок или комментируем.
    // Если доступ только для авторизованных:
    // echo ": access denied\n\n";
    // exit(); 
}

// ⚡ ВАЖНО: Закрываем сессию, чтобы не блокировать другие запросы (AJAX отправку сообщений)!
session_write_close();

$chat = new ChatManager();
$userManager = new UserManager();
$lastId = isset($_SERVER["HTTP_LAST_EVENT_ID"]) ? (int)$_SERVER["HTTP_LAST_EVENT_ID"] : 0;
// Мы можем использовать отдельный заголовок или параметр для отслеживания времени последнего обновления,
// но стандарт SSE использует только Last-Event-ID.
// Чтобы не усложнять, будем просто запоминать время старта скрипта и искать изменения с этого момента.
// Но это работает только в рамках одного соединения.
// Лучший способ: использовать timestamp как часть ID или просто проверять изменения за последние N секунд.
// Давай попробуем гибридный подход: при каждом такте цикла мы знаем "текущее" время проверки.

// Для простоты: храним время последнего чека в этой сессии.
$lastCheckTime = gmdate('Y-m-d H:i:s', time() - 2); // -2 секунды на всякий случай

// If client connects without Last-Event-ID, maybe send recent history?
// Or just wait for new. Let's send recent 50 if lastId is 0.
if ($lastId === 0) {
    $history = $chat->getMessages(20);
    // getMessages returns messages in chronological order (oldest first),
    // so we can send them directly.
    
    foreach ($history as $msg) {
        sendEvent($msg);
        if ($msg['id'] > $lastId) {
            $lastId = $msg['id'];
        }
    }
}

// Main loop
$start = time();
$maxExecTime = 50; // Restart every 50 seconds to avoid timeouts on shared hosting
$lastOnlineUpdate = 0; // Throttling

// Identify current user for last_seen
// Auth check was done above, but session closed.
// We use cached $currentUserId from before session close.
if ($currentUserId) {
    try {
        $userManager->updateLastSeen($currentUserId);
    } catch (Exception $e) { /* Ignore DB error if col missing */ }
}

while (true) {
    if (time() - $start > $maxExecTime) {
        // Graceful exit to let client reconnect
        break;
    }

    // --- Online Status Update (Every 10s) ---
    if (time() - $lastOnlineUpdate > 10) {
        try {
            if ($currentUserId) {
                $userManager->updateLastSeen($currentUserId);
            }
            
            $onlineUsers = $userManager->getOnlineUsers(2); // 2 minutes window
            echo "event: online_count\n";
            echo "data: " . json_encode(['count' => count($onlineUsers), 'users' => $onlineUsers]) . "\n\n";
            flush();
            
            $lastOnlineUpdate = time();
        } catch (Exception $e) {
            // Ignore if DB fails (e.g. column missing)
        }
    }

    // Ищем новые сообщения (ID > lastId) ИЛИ измененные (edited_at > lastCheckTime)
    // Используем нахлёст (overlap) в 5 секунд, чтобы не пропустить события из-за рассинхрона часов
    // Клиент должен уметь обрабатывать дубликаты (он это уже делает)
    $searchTime = date('Y-m-d H:i:s', strtotime($lastCheckTime) - 5);
    $newMessages = $chat->getMessagesAfter($lastId, $searchTime);
    
    // Обновляем время проверки ТЕКУЩИМ моментом (в UTC для базы)
    $lastCheckTime = gmdate('Y-m-d H:i:s');
    
    if (!empty($newMessages)) {
        foreach ($newMessages as $msg) {
            sendEvent($msg);
            // Обновляем lastId только если это реально новое сообщение, а не старое отредактированное
            if ($msg['id'] > $lastId) {
                $lastId = $msg['id'];
            }
        }
    } else {
        // Keep-alive heartbeat
        echo ": keepalive\n\n";
        flush();
    }

    // Sleep to prevent CPU hogging
    sleep(2);
    
    // Check if client disconnected
    if (connection_aborted()) {
        break;
    }
}

function sendEvent($data) {
    echo "id: " . $data['id'] . "\n";
    echo "data: " . json_encode($data) . "\n\n";
    flush();
}

