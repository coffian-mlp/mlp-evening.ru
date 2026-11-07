-- Incremental upgrade after the frozen MLP-455 baseline. No source text is copied.
CREATE TABLE IF NOT EXISTS memory_episodes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    episode_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_kind VARCHAR(16) NOT NULL,
    first_source_id INT NOT NULL,
    last_source_id INT NOT NULL,
    source_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    profile_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    authors_json JSON NOT NULL,
    started_at DATETIME NOT NULL,
    ended_at DATETIME NOT NULL,
    associations TEXT NULL,
    embedding MEDIUMBLOB NULL,
    dimensions SMALLINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY memory_episode_key (episode_key),
    KEY memory_episode_state (status, id),
    KEY memory_episode_range (source_kind, first_source_id, last_source_id),
    FULLTEXT KEY memory_associations (associations)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS memory_episode_sources (
    episode_id BIGINT UNSIGNED NOT NULL,
    source_kind VARCHAR(16) NOT NULL,
    source_id INT NOT NULL,
    user_id INT NULL,
    related_user_id INT NULL,
    start_offset INT UNSIGNED NOT NULL,
    end_offset INT UNSIGNED NOT NULL,
    source_signature CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (episode_id, source_id, start_offset),
    KEY memory_source_lookup (source_kind, source_id),
    KEY memory_source_user (user_id),
    KEY memory_source_related (related_user_id),
    CONSTRAINT memory_source_episode FOREIGN KEY (episode_id) REFERENCES memory_episodes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

