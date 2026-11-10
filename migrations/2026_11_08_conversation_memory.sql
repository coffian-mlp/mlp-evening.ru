-- A per-message application revision preserves source provenance without changing timestamp formats.
SET @conversation_column=(SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='chat_messages' AND column_name='conversation_revision');
SET @conversation_ddl=IF(@conversation_column=0,'ALTER TABLE chat_messages ADD COLUMN conversation_revision BIGINT UNSIGNED NOT NULL DEFAULT 0','SELECT 1');
PREPARE conversation_stmt FROM @conversation_ddl;
EXECUTE conversation_stmt;
DEALLOCATE PREPARE conversation_stmt;
-- Additive conversation memory; no existing chat or long-term memory is changed.
CREATE TABLE IF NOT EXISTS bot_conversation_summaries (
    session_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    first_id INT NOT NULL,
    started_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    cursor_id INT NOT NULL DEFAULT 0,
    source_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
    summary_text TEXT NOT NULL,
    checked_at DATETIME(6) NOT NULL DEFAULT '1970-01-01 00:00:00.000000',
    has_data TINYINT GENERATED ALWAYS AS (CHAR_LENGTH(summary_text)>0 OR CHAR_LENGTH(source_hash)>0 OR cursor_id<>0) STORED,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_attempt DATETIME NULL,
    disabled TINYINT NOT NULL DEFAULT 0,
    KEY conversation_expiry (expires_at),
    KEY conversation_check (checked_at,session_key),
    KEY conversation_material (has_data,expires_at),
    KEY conversation_start (started_at,first_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bot_memory_arrivals (
    user_id INT NOT NULL PRIMARY KEY,
    token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    gap_seconds BIGINT UNSIGNED NOT NULL,
    consent_revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
    checked_at DATETIME(6) NOT NULL DEFAULT '1970-01-01 00:00:00.000000',
    expires_at DATETIME NOT NULL,
    state VARCHAR(16) NOT NULL DEFAULT 'pending',
    claimed_at DATETIME NULL,
    greeting_reserved_at DATETIME NULL,
    delivery_message_id INT NULL,
    KEY arrival_expiry (expires_at),
    KEY arrival_check (checked_at,user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TEMPORARY TABLE conversation_enum_guard (valid TINYINT NOT NULL CHECK (valid=1));
INSERT INTO conversation_enum_guard SELECT IF(COUNT(*)=0,1,0) FROM llm_jobs WHERE type NOT IN ('mention','greeting','dynamic_command','cron_spontaneous','machine_spirit','stream_command','memory_scribe','evening_summary');
DROP TEMPORARY TABLE conversation_enum_guard;
ALTER TABLE llm_jobs MODIFY type ENUM('mention','greeting','dynamic_command','cron_spontaneous','machine_spirit','stream_command','memory_scribe','evening_summary') NOT NULL;
INSERT IGNORE INTO site_options(key_name,value) VALUES ('ai_evening_summary_enabled','0'),('ai_return_memory_enabled','0'),('ai_return_memory_days','14');
