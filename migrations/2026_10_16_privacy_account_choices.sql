CREATE TABLE IF NOT EXISTS privacy_account_choices (
    user_id INT NOT NULL PRIMARY KEY,
    analytics TINYINT NOT NULL DEFAULT 0,
    external_media TINYINT NOT NULL DEFAULT 0,
    policy_version VARCHAR(40) NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS privacy_browser_owners (
    token_hash CHAR(64) NOT NULL PRIMARY KEY,
    user_id INT NOT NULL,
    INDEX(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
