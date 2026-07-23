-- MLP-270: беклог фидбека из чата (команда /todo боту).
-- Идемпотентно: IF NOT EXISTS + INSERT-guard.

CREATE TABLE IF NOT EXISTS `feedback_backlog` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NULL,
    `username` VARCHAR(50) NOT NULL,
    `message_id` INT NULL,
    `text` TEXT NOT NULL,
    `status` ENUM('new','done','dismissed') NOT NULL DEFAULT 'new',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;

-- handler_type — ENUM: расширяем на 'todo' (идемпотентно — MODIFY к целевому набору).
ALTER TABLE `bot_commands` MODIFY COLUMN `handler_type` ENUM('text','schedule','poll','todo') NOT NULL DEFAULT 'text';

-- Команда бота /todo (handler_type='todo', без LLM). Не дублируем при повторном прогоне.
INSERT INTO `bot_commands` (`command_prefix`, `description`, `handler_type`, `system_prompt`, `is_active`)
SELECT '/todo', 'Записать фидбек/идею в беклог (Лира сохранит и подтвердит)', 'todo', '', 1
WHERE NOT EXISTS (SELECT 1 FROM `bot_commands` WHERE `handler_type` = 'todo');
