-- MLP-325: /штош — личный итог вечера по репликам вызвавшего (handler_type = recap).
-- Раньше команда была текстовой с общим промптом (два /штош подряд — два одинаковых ответа).
-- Данные (реплики за 8 часов) собирает код (LLM\RecapCommand); промпт команды задаёт только тон.

ALTER TABLE `bot_commands`
  MODIFY `handler_type` ENUM('text','schedule','poll','todo','image','image_chat','memory_add','memory_show','memory_forget','reminder','recap') NOT NULL DEFAULT 'text';

-- Команда на свежей установке (на проде уже есть — создана через дашборд); без UNIQUE-ключа
-- по префиксу — через NOT EXISTS, повторный прогон безопасен.
INSERT INTO `bot_commands` (`command_prefix`, `description`, `handler_type`, `system_prompt`, `is_active`)
  SELECT '/штош', 'Личный итог вечера по репликам вызвавшего (LLM)', 'recap', '', 1
   WHERE NOT EXISTS (SELECT 1 FROM `bot_commands` WHERE `command_prefix` = '/штош');

UPDATE `bot_commands`
   SET `handler_type`  = 'recap',
       `description`   = 'Личный итог вечера по репликам вызвавшего (LLM)',
       `system_prompt` = 'Тон — тёплый и чуть ироничный, как у подруги, которая весь вечер сидела рядом в чате и всё видела.'
 WHERE `command_prefix` = '/штош';
