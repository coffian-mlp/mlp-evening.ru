-- MLP-223 (remember-me): persistent-token для «запомнить меня».
-- selector — публичный идентификатор строки (в cookie), validator_hash — sha256(validator).
-- Cookie: "<selector>:<validator>". Ротация при каждом авто-входе (sliding expiry).
CREATE TABLE IF NOT EXISTS `auth_tokens` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `selector` CHAR(24) NOT NULL,
    `validator_hash` CHAR(64) NOT NULL,
    `user_id` INT NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_selector` (`selector`),
    INDEX `idx_user` (`user_id`),
    INDEX `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
