-- MLP-274: Лира-художница — команда /нарисуй (handler_type='image').
-- Идемпотентно.

ALTER TABLE `bot_commands` MODIFY COLUMN `handler_type` ENUM('text','schedule','poll','todo','image') NOT NULL DEFAULT 'text';

INSERT INTO `bot_commands` (`command_prefix`, `description`, `handler_type`, `system_prompt`, `is_active`)
SELECT '/нарисуй', 'Лира рисует картинку в наивном стиле (генерация, лимит в день)', 'image',
       'A naive child''s crayon drawing, wobbly uneven lines, smudges, drawn clumsily as if a pony held the crayon in her mouth, simple flat colors, paper texture, charming and silly. Subject:', 1
WHERE NOT EXISTS (SELECT 1 FROM `bot_commands` WHERE `handler_type` = 'image');
