-- MLP-240: команда бота «создать опрос» (handler_type='poll'), по аналогии со 'schedule'.
-- Добавляем значение в enum + seed команды. Идемпотентно: MODIFY повторяемо, INSERT IGNORE.
-- Имя файла сортируется ПОСЛЕ 2026_07_bot_commands.sql ('.' < '_'), таблица уже создана.
ALTER TABLE `bot_commands`
    MODIFY COLUMN `handler_type` ENUM('text','schedule','poll') NOT NULL DEFAULT 'text';

INSERT IGNORE INTO `bot_commands` (`command_prefix`, `description`, `handler_type`, `system_prompt`, `is_active`) VALUES
('/опрос', 'Лира создаёт опрос по теме', 'poll', 'Ты — Лира, придумай весёлый и уместный опрос для чата в своём стиле. Тема — из запроса пользователя, либо по последним сообщениям, если тема не задана. Вопрос — короткий и живой, варианты — остроумные, но понятные.', 1);

INSERT IGNORE INTO `bot_commands` (`command_prefix`, `description`, `handler_type`, `system_prompt`, `is_active`) VALUES
('/poll', 'Алиас команды опроса', 'poll', 'То же, что /опрос.', 1);
