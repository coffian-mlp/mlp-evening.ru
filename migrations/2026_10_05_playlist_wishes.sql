CREATE TABLE IF NOT EXISTS episode_wish_locks (user_id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS episode_wishes (user_id INT NOT NULL,episode_id INT NOT NULL,status VARCHAR(20) NOT NULL,accepted_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,fulfilled_snapshot_id INT NULL,PRIMARY KEY(user_id,episode_id),INDEX(status,episode_id)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS episode_wish_events (id BIGINT AUTO_INCREMENT PRIMARY KEY,operation_key VARCHAR(190) NOT NULL UNIQUE,user_id INT NULL,episode_id INT NOT NULL,kind VARCHAR(20) NOT NULL,status VARCHAR(20) NOT NULL,created_at DATETIME NOT NULL,outcome_json JSON NOT NULL,snapshot_id INT NULL,INDEX(user_id,created_at),INDEX(episode_id,created_at)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS playlist_snapshots (id INT AUTO_INCREMENT PRIMARY KEY,created_at DATETIME NOT NULL,payload_json JSON NOT NULL,completed_at DATETIME NULL,completion_json JSON NULL,origin VARCHAR(30) NOT NULL) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS playlist_occurrences (run_id VARCHAR(100) PRIMARY KEY,event_id INT NOT NULL,start_at DATETIME NOT NULL,end_at DATETIME NOT NULL,snapshot_id INT NOT NULL,state VARCHAR(30) NOT NULL DEFAULT 'pending',metadata_json JSON NOT NULL,INDEX(state,end_at)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS playlist_corrections (id BIGINT AUTO_INCREMENT PRIMARY KEY,snapshot_id INT NOT NULL,actor_id INT NOT NULL,operation_key VARCHAR(190) NOT NULL UNIQUE,created_at DATETIME NOT NULL,before_json JSON NOT NULL,after_json JSON NOT NULL) ENGINE=InnoDB;
SET @has_column=(SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='episode_list' AND column_name='legacy_wanna_watch');
SET @ddl=IF(@has_column=0,'ALTER TABLE episode_list ADD COLUMN legacy_wanna_watch INT NOT NULL DEFAULT 0','SELECT 1');
PREPARE playlist_stmt FROM @ddl; EXECUTE playlist_stmt; DEALLOCATE PREPARE playlist_stmt;
START TRANSACTION;
INSERT IGNORE INTO site_options (key_name,`value`) VALUES ('playlist_rollout_watermark',UTC_TIMESTAMP());
UPDATE episode_list SET legacy_wanna_watch=WANNA_WATCH WHERE NOT EXISTS (SELECT 1 FROM site_options WHERE key_name='playlist_legacy_imported');
INSERT IGNORE INTO episode_wish_events(operation_key,user_id,episode_id,kind,status,created_at,outcome_json) SELECT CONCAT('legacy:',ID),NULL,ID,'legacy','active',UTC_TIMESTAMP(),JSON_OBJECT('count',legacy_wanna_watch) FROM episode_list WHERE legacy_wanna_watch>0;
INSERT IGNORE INTO site_options (key_name,`value`) VALUES ('playlist_legacy_imported','1');
COMMIT;
CREATE TABLE IF NOT EXISTS playlist_completions (completion_key VARCHAR(100) PRIMARY KEY,snapshot_id INT NOT NULL,run_id VARCHAR(100) NULL,start_at DATETIME NOT NULL,completed_at DATETIME NOT NULL,shown_json JSON NOT NULL,INDEX(snapshot_id)) ENGINE=InnoDB;

SET @has_column=(SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='episode_wishes' AND column_name='fulfilled_completion_key');
SET @ddl=IF(@has_column=0,'ALTER TABLE episode_wishes ADD COLUMN fulfilled_completion_key VARCHAR(100) NULL','SELECT 1');
PREPARE playlist_stmt FROM @ddl; EXECUTE playlist_stmt; DEALLOCATE PREPARE playlist_stmt;

SET @has_column=(SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='episode_wish_events' AND column_name='fulfilled_completion_key');
SET @ddl=IF(@has_column=0,'ALTER TABLE episode_wish_events ADD COLUMN fulfilled_completion_key VARCHAR(100) NULL','SELECT 1');
PREPARE playlist_stmt FROM @ddl; EXECUTE playlist_stmt; DEALLOCATE PREPARE playlist_stmt;

SET @has_column=(SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='playlist_corrections' AND column_name='completion_key');
SET @ddl=IF(@has_column=0,'ALTER TABLE playlist_corrections ADD COLUMN completion_key VARCHAR(100) NULL','SELECT 1');
PREPARE playlist_stmt FROM @ddl; EXECUTE playlist_stmt; DEALLOCATE PREPARE playlist_stmt;

SET @has_column=(SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='playlist_occurrences' AND column_name='generated_snapshot_id');
SET @ddl=IF(@has_column=0,'ALTER TABLE playlist_occurrences ADD COLUMN generated_snapshot_id INT NULL','SELECT 1');
PREPARE playlist_stmt FROM @ddl; EXECUTE playlist_stmt; DEALLOCATE PREPARE playlist_stmt;

SET @has_column=(SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='playlist_occurrences' AND column_name='outcome_json');
SET @ddl=IF(@has_column=0,'ALTER TABLE playlist_occurrences ADD COLUMN outcome_json JSON NULL','SELECT 1');
PREPARE playlist_stmt FROM @ddl; EXECUTE playlist_stmt; DEALLOCATE PREPARE playlist_stmt;
