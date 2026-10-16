-- Optional public attribution; existing packs retain NULL metadata.
SET @author_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sticker_packs' AND COLUMN_NAME='author_name');
SET @author_sql = IF(@author_exists=0, 'ALTER TABLE sticker_packs ADD COLUMN author_name VARCHAR(120) NULL DEFAULT NULL AFTER icon_url', 'SELECT 1');
PREPARE author_stmt FROM @author_sql;
EXECUTE author_stmt;
DEALLOCATE PREPARE author_stmt;
SET @author_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sticker_packs' AND COLUMN_NAME='author_url');
SET @author_sql = IF(@author_exists=0, 'ALTER TABLE sticker_packs ADD COLUMN author_url VARCHAR(2048) NULL DEFAULT NULL AFTER author_name', 'SELECT 1');
PREPARE author_stmt FROM @author_sql;
EXECUTE author_stmt;
DEALLOCATE PREPARE author_stmt;
