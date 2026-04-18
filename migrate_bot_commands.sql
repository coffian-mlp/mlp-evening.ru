-- Миграция для создания таблицы команд бота и добавления базовых команд
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `bot_commands` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `command_prefix` varchar(50) NOT NULL COMMENT 'Например /schedule',
    `description` varchar(255) NOT NULL COMMENT 'Описание для админки',
    `handler_type` enum('text','schedule') NOT NULL DEFAULT 'text',
    `system_prompt` text COMMENT 'Шаблон промпта для ИИ',
    `is_active` tinyint(1) NOT NULL DEFAULT '1',
    PRIMARY KEY (`id`),
    UNIQUE KEY `command_prefix` (`command_prefix`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Добавляем команду /schedule
INSERT IGNORE INTO `bot_commands` (`command_prefix`, `description`, `handler_type`, `system_prompt`, `is_active`) VALUES
('/schedule', 'Расписание событий (кастомная логика)', 'schedule', '=== ВАЖНОЕ СИСТЕМНОЕ СООБЩЕНИЕ ДЛЯ БОТА ===\nРЕЖИМ: АФИША / АНОНС.\nТы НЕ участник беседы и НЕ собеседник чата. Ты пишешь официальный, красиво оформленный анонс для всех читателей.\nЗапрещено: упоминать чат/переписку/сообщения/"вы спросили"/"как выше"; задавать вопросы; спорить; писать дисклеймеры.\nПравило фактов: используй ТОЛЬКО данные ниже. Ничего не выдумывай и не добавляй новых фактов.\nФормат ответа: Markdown.\n- Заголовок: ''## ...''\n- Затем 3–6 буллетов, каждый с жирным ключом (**Когда**, **Что**, **Описание**, **Плейлист** — по уместности)\n- Заверши одной короткой строкой-призывом без вопросов.\n- Дополнительно: добавь ОДНУ короткую строку ''**Что я думаю:** ...'' (≤ 1 предложение). Это может быть лёгкая шутка или эмоция, но строго без новых фактов и без ссылок на чат.\nПользователь запросил информацию о расписании.', 1);

-- Добавляем алиас /расписание
INSERT IGNORE INTO `bot_commands` (`command_prefix`, `description`, `handler_type`, `system_prompt`, `is_active`) VALUES
('/расписание', 'Алиас расписания', 'schedule', 'То же, что и /schedule', 1);
