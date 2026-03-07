<?php
require_once __DIR__ . '/src/Database.php';
$db = Database::getInstance()->getConnection();
$res = $db->query("SELECT message FROM chat_messages WHERE message LIKE '%П' OR message LIKE '%шаттлы%' ORDER BY id DESC LIMIT 5");
$messages = [];
while($row = $res->fetch_assoc()) {
    $messages[] = $row['message'];
}
echo json_encode($messages, JSON_UNESCAPED_UNICODE);
