-- Additive archive metadata. Existing fields and source timestamps are unchanged.
SET @archive_column=(SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='bot_memory' AND column_name='is_archived');
SET @archive_ddl=IF(@archive_column=0,'ALTER TABLE bot_memory ADD COLUMN is_archived TINYINT NOT NULL DEFAULT 0','SELECT 1');
PREPARE archive_stmt FROM @archive_ddl;
EXECUTE archive_stmt;
DEALLOCATE PREPARE archive_stmt;

SET @archive_index=(SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='bot_memory' AND index_name='idx_memory_active');
SET @archive_ddl=IF(@archive_index=0,'ALTER TABLE bot_memory ADD INDEX idx_memory_active(kind,is_archived,source)','SELECT 1');
PREPARE archive_stmt FROM @archive_ddl;
EXECUTE archive_stmt;
DEALLOCATE PREPARE archive_stmt;
