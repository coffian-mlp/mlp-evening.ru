-- MLP-258: превью стикеров — колонка thumb_url (NULL = превью нет, фронт показывает оригинал).
-- Идемпотентность: колонка добавляется только при отсутствии.

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_stickers' AND COLUMN_NAME = 'thumb_url'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE chat_stickers ADD COLUMN thumb_url VARCHAR(255) NULL DEFAULT NULL AFTER image_url',
    'SELECT "thumb_url already exists" AS notice');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
