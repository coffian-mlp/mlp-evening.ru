<?php
// Migration to add login_attempts table
require_once __DIR__ . '/src/Database.php';

$db = Database::getInstance()->getConnection();

$sql = "CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    attempts_count INT DEFAULT 0,
    last_attempt_at DATETIME DEFAULT NULL,
    blocked_until DATETIME DEFAULT NULL,
    UNIQUE KEY (ip_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

if ($db->query($sql)) {
    echo "Table 'login_attempts' created successfully.\n";
} else {
    echo "Error creating table: " . $db->error . "\n";
}
