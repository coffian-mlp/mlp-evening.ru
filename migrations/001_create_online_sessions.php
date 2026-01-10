<?php
// migrations/001_create_online_sessions.php
require_once __DIR__ . '/../src/Database.php';

$db = Database::getInstance()->getConnection();

$sql = "CREATE TABLE IF NOT EXISTS online_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(128) NOT NULL,
    user_id INT DEFAULT NULL,
    ip_address VARCHAR(45),
    user_agent VARCHAR(255),
    last_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_session (session_id),
    KEY idx_last_seen (last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

if ($db->query($sql)) {
    echo "Таблица online_sessions успешно создана! ✨\n";
} else {
    echo "Ошибка создания таблицы: " . $db->error . "\n";
}
