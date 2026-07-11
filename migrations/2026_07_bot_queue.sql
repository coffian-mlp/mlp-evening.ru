-- BOT-QUEUE: очередь задач для бота Лиры (реактивные триггеры).
-- Прогонять один раз при деплое фичи. Проактив (спонтанные/анонсы) в очередь НЕ пишется.
CREATE TABLE IF NOT EXISTS `llm_jobs` (
  `id`         BIGINT       NOT NULL AUTO_INCREMENT,
  `type`       ENUM('mention','greeting','dynamic_command') NOT NULL,
  `payload`    JSON         NOT NULL COMMENT 'message, message_id, user_id, username, quoted_ids, command',
  `run_after`  DATETIME     NOT NULL COMMENT 'когда можно исполнять (lifelike-задержка)',
  `status`     ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
  `attempts`   TINYINT      NOT NULL DEFAULT 0,
  `created_at` DATETIME     NOT NULL,
  `claimed_at` DATETIME     NULL,
  PRIMARY KEY (`id`),
  KEY `idx_due` (`status`, `run_after`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
