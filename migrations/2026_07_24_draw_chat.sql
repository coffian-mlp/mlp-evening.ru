-- MLP-277: /нарисуйчат — Лира рисует происходящее в чате (handler_type='image_chat').
-- Идемпотентно.

ALTER TABLE `bot_commands` MODIFY COLUMN `handler_type` ENUM('text','schedule','poll','todo','image','image_chat') NOT NULL DEFAULT 'text';

INSERT INTO `bot_commands` (`command_prefix`, `description`, `handler_type`, `system_prompt`, `is_active`)
SELECT '/нарисуйчат', 'Лира рисует сценку того, что происходит в чате (LLM-режиссёр + генерация)', 'image_chat', '', 1
WHERE NOT EXISTS (SELECT 1 FROM `bot_commands` WHERE `handler_type` = 'image_chat');
