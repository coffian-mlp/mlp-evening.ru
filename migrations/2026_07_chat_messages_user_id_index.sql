-- R10 (MLP-234): индекс chat_messages(user_id).
-- Ускоряет выборки по пользователю: lastBotReplyTs (ORDER BY id DESC WHERE user_id),
-- purge_messages, подсчёт сообщений пользователя.
-- Идемпотентно для MySQL 5.7 (нет ADD INDEX IF NOT EXISTS): добавляем только если индекса нет.
SET @exist := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'chat_messages'
      AND index_name = 'idx_user_id'
);
SET @sql := IF(@exist = 0,
    'ALTER TABLE `chat_messages` ADD INDEX `idx_user_id` (`user_id`)',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
