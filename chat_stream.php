<?php
// chat_stream.php - SSE endpoint for chat messages

require_once __DIR__ . '/src/ChatManager.php';
require_once __DIR__ . '/src/Auth.php';

// Disable time limit for long-running script (or set to reasonable value like 60s for shared hosting)
set_time_limit(0); 

// Headers for SSE
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Nginx specific: disable buffering

// 🔒 Optional: Restrict access to logged in users
if (!Auth::check()) {
    // Если мы хотим разрешить чтение всем, то просто убираем этот блок или комментируем.
    // Но если логика требует авторизации:
    /*
    echo "event: error\n";
    echo "data: Unauthorized\n\n";
    flush();
    exit();
    */
}

// ⚡ ВАЖНО: Закрываем сессию, чтобы не блокировать другие запросы (AJAX отправку сообщений)!
session_write_close();

$chat = new ChatManager();
$lastId = isset($_SERVER["HTTP_LAST_EVENT_ID"]) ? (int)$_SERVER["HTTP_LAST_EVENT_ID"] : 0;

// If client connects without Last-Event-ID, maybe send recent history?
// Or just wait for new. Let's send recent 50 if lastId is 0.
if ($lastId === 0) {
    $history = $chat->getMessages(20);
    // History comes newest first from getMessages, but we want to send them chronologically
    $history = array_reverse($history); 
    
    foreach ($history as $msg) {
        sendEvent($msg);
        $lastId = $msg['id'];
    }
}

// Main loop
$start = time();
$maxExecTime = 50; // Restart every 50 seconds to avoid timeouts on shared hosting

while (true) {
    if (time() - $start > $maxExecTime) {
        // Graceful exit to let client reconnect
        break;
    }

    $newMessages = $chat->getMessagesAfter($lastId);
    
    if (!empty($newMessages)) {
        foreach ($newMessages as $msg) {
            sendEvent($msg);
            $lastId = $msg['id'];
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

