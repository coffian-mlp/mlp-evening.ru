<?php
require_once __DIR__ . '/src/Database.php';

$db = Database::getInstance()->getConnection();

$sql = "
CREATE TABLE IF NOT EXISTS `chat_reactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `message_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `reaction` varchar(32) NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_reaction` (`message_id`,`user_id`,`reaction`),
  KEY `message_id` (`message_id`),
  FOREIGN KEY (`message_id`) REFERENCES `chat_messages`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";

if ($db->query($sql)) {
    echo "Table 'chat_reactions' created successfully.\n";
} else {
    echo "Error creating table: " . $db->error . "\n";
}
