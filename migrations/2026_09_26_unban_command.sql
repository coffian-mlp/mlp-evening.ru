-- MLP-353: /разбан — модератор или админ снимает с участника действующие бан и мут.
-- handler_type: + unban (целевой полный набор; NOT NULL DEFAULT сохранены).
ALTER TABLE `bot_commands`
  MODIFY `handler_type` ENUM('text','schedule','poll','todo','image','image_chat','memory_add','memory_show','memory_forget','reminder','recap','oc_set','ban','mute','unban') NOT NULL DEFAULT 'text';

INSERT INTO `bot_commands` (`command_prefix`, `description`, `handler_type`, `system_prompt`, `is_active`)
SELECT '/разбан', 'Снять бан и мут (модераторы)', 'unban', '', 1
WHERE NOT EXISTS (SELECT 1 FROM `bot_commands` WHERE `handler_type` = 'unban' OR `command_prefix` = '/разбан');
