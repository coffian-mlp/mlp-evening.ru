CREATE TABLE IF NOT EXISTS telegram_announcement_campaigns (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 run_id VARCHAR(100) NOT NULL,
 stream_number INT UNSIGNED NOT NULL,
 start_at DATETIME NOT NULL,
 fingerprint CHAR(64) NOT NULL,
 facts_json MEDIUMTEXT NOT NULL,
 UNIQUE KEY run_unique(run_id), UNIQUE KEY number_unique(stream_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS telegram_announcement_posts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 campaign_id BIGINT UNSIGNED NOT NULL,
 kind VARCHAR(16) NOT NULL,
 current_revision BIGINT UNSIGNED NULL,
 due_at DATETIME NOT NULL,
 expires_at DATETIME NOT NULL,
 UNIQUE KEY campaign_kind(campaign_id,kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS telegram_announcement_revisions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 post_id BIGINT UNSIGNED NOT NULL,
 version INT UNSIGNED NOT NULL,
 nonce CHAR(16) NOT NULL,
 state VARCHAR(24) NOT NULL DEFAULT 'text_pending',
 caption TEXT NULL,
 previous_caption TEXT NULL,
 image_path VARCHAR(255) NULL,
 feedback TEXT NOT NULL,
 preview_photo_id BIGINT NULL,
 preview_controls_id BIGINT NULL,
 approved_at DATETIME NULL,
 channel_message_id BIGINT NULL,
 last_error VARCHAR(100) NULL,
 retry_at DATETIME NULL,
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL,
 UNIQUE KEY post_version(post_id,version), KEY pending(state,retry_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS telegram_announcement_updates (
 update_id BIGINT PRIMARY KEY,
 processed_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
