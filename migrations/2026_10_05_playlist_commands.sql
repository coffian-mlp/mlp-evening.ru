-- MLP-361: extend the existing enum instead of replacing its historical values.
SET @playlist_handler_type = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bot_commands' AND COLUMN_NAME='handler_type');
SET @playlist_handler_ddl = IF(LOCATE("'playlist'", @playlist_handler_type)=0, CONCAT('ALTER TABLE bot_commands MODIFY handler_type ', LEFT(@playlist_handler_type,LENGTH(@playlist_handler_type)-1), ",'playlist') NOT NULL DEFAULT 'text'"), 'SELECT 1');
PREPARE playlist_handler_stmt FROM @playlist_handler_ddl;
EXECUTE playlist_handler_stmt;
DEALLOCATE PREPARE playlist_handler_stmt;
INSERT IGNORE INTO bot_commands(command_prefix,description,handler_type,system_prompt,is_active) VALUES
('/хочу','Пожелание посмотреть эпизод: номер, название, сезонный код или описание','playlist','',1),
('/желания','Собственные активные пожелания','playlist','',1),
('/передумал','Отмена собственного пожелания','playlist','',1),
('/передумала','Отмена собственного пожелания','playlist','',1),
('/топ','Пять эпизодов с наибольшим числом пожеланий','playlist','',1),
('/плейлист','Опубликованный плейлист следующего вечерка','playlist','',1);
