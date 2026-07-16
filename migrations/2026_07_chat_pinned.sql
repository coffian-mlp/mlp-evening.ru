-- MLP-242: закреплённые сообщения. Флаг is_pinned на chat_messages (одно активное закрепление).
-- Идемпотентно для MySQL 5.7 (нет ADD COLUMN IF NOT EXISTS): добавляем только если колонки нет.
SET @exist := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'chat_messages'
      AND column_name = 'is_pinned'
);
SET @sql := IF(@exist = 0,
    'ALTER TABLE `chat_messages` ADD COLUMN `is_pinned` TINYINT(1) NOT NULL DEFAULT 0, ADD INDEX `idx_pinned` (`is_pinned`)',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
