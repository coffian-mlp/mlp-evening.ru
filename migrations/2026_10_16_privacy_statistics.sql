CREATE TABLE IF NOT EXISTS account_privacy (
    user_id INT NOT NULL PRIMARY KEY,
    state VARCHAR(20) NOT NULL,
    policy_version VARCHAR(40) NOT NULL,
    accepted_at DATETIME NULL,
    withdrawn_at DATETIME NULL,
    delete_after DATETIME NULL,
    INDEX privacy_due(state,delete_after)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS privacy_choices (
    token_hash CHAR(64) NOT NULL PRIMARY KEY,
    analytics TINYINT NOT NULL DEFAULT 0,
    external_media TINYINT NOT NULL DEFAULT 0,
    policy_version VARCHAR(40) NOT NULL,
    updated_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    INDEX(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS privacy_consent_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    kind VARCHAR(20) NOT NULL,
    policy_version VARCHAR(40) NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS privacy_files (
    path VARCHAR(500) NOT NULL PRIMARY KEY,
    user_id INT NULL,
    delete_pending TINYINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    INDEX(user_id), INDEX(delete_pending)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS privacy_sessions (
    session_id VARCHAR(128) PRIMARY KEY,
    user_id INT NULL,
    delete_pending TINYINT NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL,
    INDEX(user_id), INDEX(delete_pending)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS privacy_mail_cleanup (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    email VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY user_mail(user_id,email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ux_pageviews (
    page_id CHAR(32) NOT NULL PRIMARY KEY,
    visit_id CHAR(32) NOT NULL,
    consent_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    path VARCHAR(64) NOT NULL,
    device VARCHAR(20) NOT NULL,
    browser VARCHAR(40) NOT NULL,
    os VARCHAR(30) NOT NULL,
    screen VARCHAR(30) NOT NULL,
    viewport VARCHAR(30) NOT NULL,
    density DECIMAL(4,2) NOT NULL,
    pinch_scale DECIMAL(4,2) NULL,
    font_scale SMALLINT NOT NULL,
    theme VARCHAR(20) NOT NULL,
    load_ms INT NULL,
    chat_error TINYINT NOT NULL DEFAULT 0,
    player_error TINYINT NOT NULL DEFAULT 0,
    overflow TINYINT NOT NULL DEFAULT 0,
    INDEX(created_at), INDEX(consent_hash), INDEX(visit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ux_daily (
    day DATE NOT NULL,
    dimension VARCHAR(25) NOT NULL,
    label VARCHAR(64) NOT NULL,
    views INT NOT NULL,
    visits INT NOT NULL,
    PRIMARY KEY(day,dimension,label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ux_peaks (
    day DATE PRIMARY KEY,
    peak INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO site_options(key_name,value) VALUES ('privacy_operator','Овчаров Константин Александрович'),('privacy_contact_email','');
