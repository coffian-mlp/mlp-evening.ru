-- Debug-журнал обращений к нейронкам (запрос/ответ, TTL 7 дней — чистит LlmDebugLog при вставке). Идемпотентно.
CREATE TABLE IF NOT EXISTS `llm_debug_log` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `kind` VARCHAR(20) NOT NULL,
  `provider` VARCHAR(40) NOT NULL DEFAULT '',
  `model` VARCHAR(120) NOT NULL DEFAULT '',
  `status` VARCHAR(10) NOT NULL DEFAULT 'ok',
  `duration_ms` INT UNSIGNED NOT NULL DEFAULT 0,
  `request` MEDIUMTEXT NOT NULL,
  `response` MEDIUMTEXT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
