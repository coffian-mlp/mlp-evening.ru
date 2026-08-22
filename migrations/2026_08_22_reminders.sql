-- MLP-318: напоминалки («Лира, напомни через час…»). Идемпотентно.

CREATE TABLE IF NOT EXISTS `reminders` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `username` VARCHAR(50) NOT NULL COMMENT 'Снапшот ника для адресации при доставке',
    `text` VARCHAR(300) NOT NULL,
    `remind_at` DATETIME NOT NULL COMMENT 'UTC',
    `status` ENUM('pending','done','cancelled') NOT NULL DEFAULT 'pending',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_due` (`status`, `remind_at`),
    INDEX `idx_user` (`user_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;

-- handler_type: + reminder (целевой полный набор, NOT NULL DEFAULT сохранены).
ALTER TABLE `bot_commands` MODIFY COLUMN `handler_type` ENUM('text','schedule','poll','todo','image','image_chat','memory_add','memory_show','memory_forget','reminder') NOT NULL DEFAULT 'text';

-- Слэш-вход (дублирует свободную форму «Лира, напомни…»; светится в превью «/»).
INSERT INTO `bot_commands` (`command_prefix`, `description`, `handler_type`, `system_prompt`, `is_active`)
SELECT '/напомни', 'Напоминание ко времени: «/напомни через час достать колу», «покажи напоминалки», «отмени №N»', 'reminder', '', 1
WHERE NOT EXISTS (SELECT 1 FROM `bot_commands` WHERE `handler_type` = 'reminder');
