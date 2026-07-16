-- MLP-237: опросы в чате. Владелец — PollManager.
-- polls (вопрос+флаги), poll_options (варианты), poll_votes (голоса).
-- Идемпотентно (CREATE IF NOT EXISTS). message_id — связь с сообщением-карточкой (MLP-239).
CREATE TABLE IF NOT EXISTS `polls` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `question`      VARCHAR(500) NOT NULL,
  `created_by`    INT NOT NULL,
  `created_at`    DATETIME NOT NULL,
  `status`        ENUM('open','closed') NOT NULL DEFAULT 'open',
  `is_multi`      TINYINT(1) NOT NULL DEFAULT 0,
  `is_anonymous`  TINYINT(1) NOT NULL DEFAULT 0,
  `closes_at`     DATETIME NULL,
  `closed_at`     DATETIME NULL,
  `message_id`    INT NULL,
  INDEX `idx_status` (`status`),
  INDEX `idx_message` (`message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `poll_options` (
  `id`        INT AUTO_INCREMENT PRIMARY KEY,
  `poll_id`   INT NOT NULL,
  `text`      VARCHAR(255) NOT NULL,
  `image_url` VARCHAR(500) NULL,
  `position`  TINYINT NOT NULL DEFAULT 0,
  INDEX `idx_poll` (`poll_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `poll_votes` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `poll_id`    INT NOT NULL,
  `option_id`  INT NOT NULL,
  `user_id`    INT NOT NULL,
  `created_at` DATETIME NOT NULL,
  UNIQUE KEY `uniq_vote` (`poll_id`,`user_id`,`option_id`),
  INDEX `idx_poll` (`poll_id`),
  INDEX `idx_poll_user` (`poll_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
