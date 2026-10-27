ALTER TABLE events
    ADD COLUMN monitor_stream TINYINT NOT NULL DEFAULT 0,
    ADD COLUMN streamer_user_id INT NULL;

ALTER TABLE command_interactions
    MODIFY source_message_id INT NULL,
    ADD COLUMN origin VARCHAR(16) NOT NULL DEFAULT 'command',
    ADD COLUMN system_key VARCHAR(100) NULL,
    ADD UNIQUE KEY interaction_system (type, system_key);

CREATE TABLE IF NOT EXISTS lyra_notification_links (
    user_id INT NOT NULL PRIMARY KEY,
    telegram_uid VARCHAR(32) NOT NULL,
    bot_fingerprint CHAR(64) NOT NULL,
    chat_id BIGINT NULL,
    connected_at DATETIME NULL,
    token_hash CHAR(64) NULL,
    token_expires DATETIME NULL,
    disabled_reason VARCHAR(64) NULL,
    UNIQUE KEY notification_token (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stream_monitor_runs (
    run_id VARCHAR(80) NOT NULL PRIMARY KEY,
    event_id INT NOT NULL,
    streamer_user_id INT NOT NULL,
    ends_at DATETIME NOT NULL,
    path VARCHAR(64) NOT NULL,
    health VARCHAR(16) NOT NULL DEFAULT 'unknown',
    last_checked DATETIME NULL,
    progress_key VARCHAR(160) NULL,
    progress_at DATETIME NULL,
    healthy_since DATETIME NULL,
    armed TINYINT NOT NULL DEFAULT 0,
    lost_at DATETIME NULL,
    recovered_since DATETIME NULL,
    incident_id BIGINT UNSIGNED NULL,
    ended TINYINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stream_incidents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    run_id VARCHAR(80) NOT NULL,
    nonce CHAR(16) NOT NULL,
    state VARCHAR(24) NOT NULL DEFAULT 'open',
    lost_at DATETIME NOT NULL,
    acknowledged_at DATETIME NULL,
    acknowledged_by INT NULL,
    recovered_at DATETIME NULL,
    interaction_id BIGINT UNSIGNED NULL,
    KEY incident_run (run_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stream_monitor_deliveries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    incident_id BIGINT UNSIGNED NOT NULL,
    stage VARCHAR(32) NOT NULL,
    state VARCHAR(16) NOT NULL DEFAULT 'pending',
    attempts INT NOT NULL DEFAULT 0,
    retry_at DATETIME NULL,
    message_id BIGINT NULL,
    reason VARCHAR(100) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY incident_stage (incident_id, stage),
    KEY delivery_pending (state, retry_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
