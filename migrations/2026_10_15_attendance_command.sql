-- MLP-399: append without dropping previously registered command handlers.
SET @attendance_handler_type = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bot_commands' AND COLUMN_NAME='handler_type');
SET @attendance_handler_ddl = IF(LOCATE("'attendance'", @attendance_handler_type)=0, CONCAT('ALTER TABLE bot_commands MODIFY handler_type ', LEFT(@attendance_handler_type,LENGTH(@attendance_handler_type)-1), ",'attendance') NOT NULL DEFAULT 'text'"), 'SELECT 1');
PREPARE attendance_handler_stmt FROM @attendance_handler_ddl;
EXECUTE attendance_handler_stmt;
DEALLOCATE PREPARE attendance_handler_stmt;
INSERT IGNORE INTO bot_commands(command_prefix,description,handler_type,system_prompt,is_active) VALUES
('/перерыв','Перекличка после перерыва: отметиться кнопкой «Я тут!»','attendance','',1);
