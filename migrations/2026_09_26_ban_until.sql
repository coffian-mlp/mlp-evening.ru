-- MLP-352: временный бан — срок в users.ban_until (NULL = бессрочно, UTC).
-- Отдельной миграцией ДО кода, который читает колонку (expand → use): иначе в окне между
-- git pull и migrate запросы к users падали бы с «Unknown column».
-- Идемпотентно для MySQL 5.7/8.0 (нет ADD COLUMN IF NOT EXISTS): добавляем, только если колонки нет.
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'ban_until'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `users` ADD COLUMN `ban_until` DATETIME NULL DEFAULT NULL AFTER `ban_reason`',
    'SELECT "ban_until already exists" AS notice');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
