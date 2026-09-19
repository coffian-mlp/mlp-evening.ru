-- MLP-335: пони-облики участников (ОС) для художницы.
-- bot_memory.source: 'oc' — облик, придуманный Лирой (автосжатие памяти его не трогает).
-- bot_commands: /яос — человек задаёт или переписывает свой облик (handler_type = oc_set, без прав).

ALTER TABLE `bot_memory`
  MODIFY `source` ENUM('manual','auto','oc') NOT NULL DEFAULT 'manual';

ALTER TABLE `bot_commands`
  MODIFY `handler_type` ENUM('text','schedule','poll','todo','image','image_chat','memory_add','memory_show','memory_forget','reminder','recap','oc_set') NOT NULL DEFAULT 'text';

INSERT INTO `bot_commands` (`command_prefix`, `description`, `handler_type`, `system_prompt`, `is_active`)
  SELECT '/яос', 'Свой пони-облик для рисунков Лиры: «/яос серая кобылка в очках» (без аргумента — показать текущий)', 'oc_set', '', 1
   WHERE NOT EXISTS (SELECT 1 FROM `bot_commands` WHERE `command_prefix` = '/яос');
