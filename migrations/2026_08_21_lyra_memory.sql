-- MLP-314: долгая память Лиры — таблица bot_memory, команды памяти, тип очереди memory_scribe.
-- Идемпотентно: IF NOT EXISTS / MODIFY к целевому набору / INSERT IGNORE / INSERT-guard.

CREATE TABLE IF NOT EXISTS `bot_memory` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `kind` ENUM('dossier','meme') NOT NULL,
    `user_id` INT NULL COMMENT 'Субъект досье; NULL для мемов',
    `text` TEXT NOT NULL COMMENT 'Один факт/мем, нормализован (одна строка)',
    `source` ENUM('manual','auto') NOT NULL DEFAULT 'manual',
    `created_by` INT NULL COMMENT 'Автор ручной записи',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_kind_user` (`kind`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;

-- handler_type: MODIFY к целевому полному набору (идемпотентно).
ALTER TABLE `bot_commands` MODIFY COLUMN `handler_type` ENUM('text','schedule','poll','todo','image','image_chat','memory_add','memory_show','memory_forget') NOT NULL DEFAULT 'text';

-- llm_jobs.type: + memory_scribe (целевой полный набор).
ALTER TABLE `llm_jobs` MODIFY COLUMN `type` ENUM('mention','greeting','dynamic_command','cron_spontaneous','machine_spirit','stream_command','memory_scribe') NOT NULL;

-- Команды памяти (guard-сиды: не дублируем при повторном прогоне).
INSERT INTO `bot_commands` (`command_prefix`, `description`, `handler_type`, `system_prompt`, `is_active`)
SELECT '/запомни', 'Записать факт о пользователе (@ник факт) или мем чата в память Лиры (модераторы)', 'memory_add', '', 1
WHERE NOT EXISTS (SELECT 1 FROM `bot_commands` WHERE `handler_type` = 'memory_add');

INSERT INTO `bot_commands` (`command_prefix`, `description`, `handler_type`, `system_prompt`, `is_active`)
SELECT '/память', 'Показать, что Лира помнит о тебе', 'memory_show', '', 1
WHERE NOT EXISTS (SELECT 1 FROM `bot_commands` WHERE `handler_type` = 'memory_show');

INSERT INTO `bot_commands` (`command_prefix`, `description`, `handler_type`, `system_prompt`, `is_active`)
SELECT '/забудь', 'Удалить запись памяти по номеру (модераторы)', 'memory_forget', '', 1
WHERE NOT EXISTS (SELECT 1 FROM `bot_commands` WHERE `handler_type` = 'memory_forget');

-- Маркер автописи: старт с текущего конца чата — бэкфилл истории исключён.
-- INSERT IGNORE (key_name — PRIMARY KEY): повторный прогон не перезаписывает маркер.
INSERT IGNORE INTO `site_options` (`key_name`, `value`)
SELECT 'bot_memory_last_id', COALESCE(MAX(`id`), 0) FROM `chat_messages`;

-- Дефолтные опции подсистемы памяти (дашборд на чистой БД показывает реальные значения).
INSERT IGNORE INTO `site_options` (`key_name`, `value`) VALUES
('ai_memory_enabled', '1'),
('ai_memory_auto', '0'),
('ai_memory_interval', '21600'),
('ai_memory_view_role', 'all'),
('ai_memory_teach_role', 'moderator'),
('ai_memory_block_limit', '2400'),
('ai_memory_user_limit', '400'),
('ai_memory_meme_limit', '800');
