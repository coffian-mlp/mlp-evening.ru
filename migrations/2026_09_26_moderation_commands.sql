-- MLP-350: /бан и /мут — модератору санкция сразу, остальным жалоба: Лира оценивает реплики
-- обвиняемого по правилам чата и зовёт модераторов из присутствующих. Сама никого не наказывает.
-- handler_type: + ban, mute (целевой полный набор; NOT NULL DEFAULT сохранены).
ALTER TABLE `bot_commands`
  MODIFY `handler_type` ENUM('text','schedule','poll','todo','image','image_chat','memory_add','memory_show','memory_forget','reminder','recap','oc_set','ban','mute') NOT NULL DEFAULT 'text';

-- Команды заводятся, только если такого типа ещё нет и префикс свободен (UNIQUE command_prefix).
INSERT INTO `bot_commands` (`command_prefix`, `description`, `handler_type`, `system_prompt`, `is_active`)
SELECT '/бан', 'Бан (модераторы) или жалоба: Лира посмотрит переписку и позовёт модераторов', 'ban', '', 1
WHERE NOT EXISTS (SELECT 1 FROM `bot_commands` WHERE `handler_type` = 'ban' OR `command_prefix` = '/бан');

INSERT INTO `bot_commands` (`command_prefix`, `description`, `handler_type`, `system_prompt`, `is_active`)
SELECT '/мут', 'Мут на N минут (модераторы) или жалоба: Лира позовёт модераторов', 'mute', '', 1
WHERE NOT EXISTS (SELECT 1 FROM `bot_commands` WHERE `handler_type` = 'mute' OR `command_prefix` = '/мут');
