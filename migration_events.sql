CREATE TABLE IF NOT EXISTS `events` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `start_time` DATETIME NOT NULL,
  `duration_minutes` INT NOT NULL DEFAULT 60,
  `is_recurring` TINYINT(1) DEFAULT 0,
  `recurrence_rule` VARCHAR(255) DEFAULT NULL,
  `use_playlist` TINYINT(1) DEFAULT 0,
  `generate_new_playlist` TINYINT(1) DEFAULT 0,
  `color` VARCHAR(50) DEFAULT '#6d2f8e',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (`start_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
