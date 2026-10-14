-- Keep legacy receipts intact; bot-specific polling state is additive.
CREATE TABLE IF NOT EXISTS telegram_announcement_bot_updates (
 bot_id BIGINT NOT NULL,
 update_id BIGINT NOT NULL,
 processed_at DATETIME NOT NULL,
 PRIMARY KEY (bot_id, update_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS telegram_announcement_bot_cursors (
 bot_id BIGINT PRIMARY KEY,
 update_offset BIGINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO site_options(key_name,value)
 SELECT 'announcements_legacy_token_hash',SHA2(value,256)
 FROM site_options WHERE key_name='announcements_token' AND value<>'';
