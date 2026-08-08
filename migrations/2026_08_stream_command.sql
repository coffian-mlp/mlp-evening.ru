-- MLP-307: команды стрима переходят к Лире («дух машины» упразднён).
-- Новый тип задачи в очереди + перенос настроек machine_spirit_* → stream_command_*.

ALTER TABLE `llm_jobs`
  MODIFY `type` ENUM('mention','greeting','dynamic_command','cron_spontaneous','machine_spirit','stream_command') NOT NULL;

-- Перенос значений опций (INSERT IGNORE: повторный прогон миграции безопасен)
INSERT IGNORE INTO `site_options` (`key_name`, `value`)
  SELECT 'stream_command_enabled', `value` FROM `site_options` WHERE `key_name` = 'machine_spirit_enabled';
INSERT IGNORE INTO `site_options` (`key_name`, `value`)
  SELECT 'stream_command_owner_id', `value` FROM `site_options` WHERE `key_name` = 'machine_spirit_owner_id';
INSERT IGNORE INTO `site_options` (`key_name`, `value`)
  SELECT 'stream_command_cooldown', `value` FROM `site_options` WHERE `key_name` = 'machine_spirit_cooldown';

DELETE FROM `site_options` WHERE `key_name` IN (
  'machine_spirit_enabled', 'machine_spirit_owner_id', 'machine_spirit_cooldown',
  'machine_spirit_prompt', 'machine_spirit_user_login', 'machine_spirit_commands',
  'machine_spirit_last_refusal'
);
