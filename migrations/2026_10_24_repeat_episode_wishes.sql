-- Preserve every existing wish; new accepted weekly votes get independent rows.
SET @has_wish_id=(SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='episode_wishes' AND column_name='id');
SET @ddl=IF(@has_wish_id=0,'ALTER TABLE episode_wishes DROP PRIMARY KEY, ADD COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST, ADD INDEX idx_episode_wish_owner(user_id,episode_id,status)','SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
