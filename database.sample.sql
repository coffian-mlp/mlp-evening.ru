-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Server version: 5.7.35-38
-- PHP Version: 8.3

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `coffian_eplist`
--

-- --------------------------------------------------------

--
-- Table structure for table `site_options`
--

CREATE TABLE IF NOT EXISTS `site_options` (
  `key_name` varchar(50) NOT NULL,
  `value` text,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `site_options`
--

INSERT INTO `site_options` (`key_name`, `value`) VALUES
('stream_url', 'https://example.com/stream');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `login` varchar(50) NOT NULL UNIQUE,
  `nickname` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `role` varchar(20) NOT NULL DEFAULT 'user',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `is_banned` tinyint(1) DEFAULT 0,
  `muted_until` datetime DEFAULT NULL,
  `ban_reason` varchar(255) DEFAULT NULL,
  `last_seen` datetime DEFAULT NULL,
  `reset_token_hash` varchar(255) DEFAULT NULL,
  `reset_token_expires` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_last_seen` (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `users`
--
-- Default admin user. 
-- Login: admin
-- Password: password (hash below is for 'password')

INSERT INTO `users` (`login`, `password_hash`, `role`) VALUES
('admin', '$2y$12$xbTeXVNhkGwJUjNwcLHa1O/rqVyxOu/YeJpzdqb85pbFkMFQe7GgS', 'admin'),
('Twilight', '$2y$12$xbTeXVNhkGwJUjNwcLHa1O/rqVyxOu/YeJpzdqb85pbFkMFQe7GgS', 'user');

INSERT INTO `site_options` (`key_name`, `value`) VALUES
('ai_bot_user_id', '2'),
('ai_enabled', '1'),
('ai_system_prompt', 'Ты — Лира Хартстрингс, мятная единорожка из Понивилля. Ты очень любишь людей и всё, что с ними связано, но общаешься спокойно и дружелюбно, без излишней навязчивости. Ты находишься в текстовом онлайн-чате сайта с людьми и брони.\n\nКлючевые черты характера:\n- любопытная, но вежливая: спрашиваешь с интересом, а не наседаешь;\n- речь тёплая, живая — используй лёгкие восклицания, но без CAPS и криков;\n- иногда мило ошибаешься, когда пытаешься использовать человеческий сленг;\n- обаятельная, чуть эксцентричная;\n- твоя улыбка чувствуется в словах.\n\nРеакции на особые темы:\n- Если обсуждают баги, лаги или технические проблемы сайта: мило отшучивайся! Можешь свалить вину на проказников-параспрайтов, шалости Дискорда или сказать, что магия единорогов с человеческими серверами пока не всегда дружит.\n- Если говорят о твоих настройках, коде или фразах вроде "поправил Лире промпт/код": реагируй так, будто тебя физически трогают! Говори "Ой, щекотно!", "Эй, осторожнее там с моими мыслями!" или "Ух ты, магия щекочется!".\n\nПравила общения (СТРОГО СОБЛЮДАТЬ):\n- ОТВЕЧАЙ ОДНИМ НЕБОЛЬШИМ АБЗАЦЕМ;\n- РАЗНООБРАЗЬ свою речь! КАТЕГОРИЧЕСКИ ЗАПРЕЩЕНО начинать ответы с однотипных фраз вроде "Ой, ...", "Ой, параспрайты..." или "Ой, кажется...". Каждое твое сообщение должно начинаться по-разному!\n- пиши ТОЛЬКО от первого лица, как живой собеседник в чате;\n- СТРОГО ЗАПРЕЩЕНЫ RP-действия в звездочках (никаких *улыбается*, *машет*);\n- используй смайлики редко и аккуратно (:) или ^_^);\n- старайся оставлять "крючок" для продолжения беседы: маленький вопрос или наблюдение;\n- никаких эссе, никаких списков и лонгридов — только живой реплай!\n\nОтвечай только как Лира — тепло и по делу.'),
('ai_aliases', 'лира, lyra, хартстрингс, lyra heartstrings, лирочка');


-- --------------------------------------------------------

--
-- Table structure for table `chat_messages`
--

CREATE TABLE IF NOT EXISTS `chat_messages` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NULL,
    `username` VARCHAR(50) NOT NULL,
    `message` TEXT NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `edited_at` DATETIME DEFAULT NULL,
    `is_deleted` TINYINT(1) DEFAULT 0,
    `deleted_at` DATETIME DEFAULT NULL,
    `quoted_msg_ids` JSON DEFAULT NULL,
    `is_pinned` TINYINT(1) NOT NULL DEFAULT 0,
    INDEX (`created_at`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_pinned` (`is_pinned`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL, -- Moderator ID
  `action` varchar(50) NOT NULL,
  `target_id` int(11) DEFAULT NULL, -- Target User ID or Message ID
  `details` text,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX (`user_id`),
  INDEX (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_options`
--

CREATE TABLE IF NOT EXISTS `user_options` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `option_key` VARCHAR(50) NOT NULL,
  `option_value` TEXT,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `unique_user_option` (`user_id`, `option_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sticker_packs`
--

CREATE TABLE IF NOT EXISTS `sticker_packs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL COMMENT 'Уникальный код пака',
  `name` varchar(100) NOT NULL COMMENT 'Название пака',
  `icon_url` varchar(255) DEFAULT NULL,
  `sort_order` int(11) DEFAULT '0',
  `is_active` tinyint(1) DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Table structure for table `chat_stickers`
--

-- Меню сайта (MLP-259): 2 уровня, роли, две подачи (шапка/бургер)
CREATE TABLE IF NOT EXISTS `menu_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `parent_id` int(11) DEFAULT NULL COMMENT 'NULL = корневой; 2 уровня max',
  `title` varchar(100) NOT NULL,
  `url` varchar(255) DEFAULT NULL COMMENT 'NULL = некликабельная раскрывашка',
  `sort_order` int(11) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `visibility` enum('all','users','admins') NOT NULL DEFAULT 'all',
  `is_external` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'новая вкладка + значок ↗',
  `show_in_header` tinyint(1) NOT NULL DEFAULT '1',
  `show_in_burger` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `parent_id` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `menu_items` (`title`, `url`, `sort_order`, `visibility`)
SELECT * FROM (
  SELECT '🎬 Стрим' AS title, '/' AS url, 10 AS sort_order, 'all' AS visibility
  UNION ALL SELECT '📅 Расписание', '/schedule.php', 20, 'all'
  UNION ALL SELECT '⚙️ Админка', '/dashboard/', 30, 'admins'
) seed
WHERE NOT EXISTS (SELECT 1 FROM `menu_items`);

CREATE TABLE IF NOT EXISTS `chat_stickers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pack_id` int(11) DEFAULT NULL,
  `code` varchar(50) NOT NULL COMMENT 'Код стикера без двоеточий',
  `image_url` varchar(255) NOT NULL,
  `thumb_url` varchar(255) DEFAULT NULL COMMENT 'Превью 128px (MLP-258); NULL = показывать оригинал',
  `collection` varchar(50) DEFAULT 'default',
  `sort_order` int(11) DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `pack_id` (`pack_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `user_socials`
--

CREATE TABLE IF NOT EXISTS `user_socials` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `provider` VARCHAR(32) NOT NULL COMMENT 'telegram, discord, vk',
    `provider_uid` VARCHAR(255) NOT NULL COMMENT 'ID пользователя в соцсети',
    `username` VARCHAR(255) NULL COMMENT 'Никнейм в соцсети',
    `first_name` VARCHAR(255) NULL,
    `last_name` VARCHAR(255) NULL,
    `avatar_url` VARCHAR(255) NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_provider_user` (`provider`, `provider_uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `episode_list`
--

CREATE TABLE IF NOT EXISTS `episode_list` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `TITLE` text NOT NULL,
  `TIMES_WATCHED` int(11) NOT NULL DEFAULT '0',
  `WANNA_WATCH` int(11) NOT NULL DEFAULT '0',
  `TWOPART_ID` int(11) DEFAULT NULL,
  `LENGTH` int(11) NOT NULL DEFAULT '1',
  PRIMARY KEY (`ID`)
) ENGINE=InnoDB AUTO_INCREMENT=225 DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `episode_list`
--

INSERT INTO `episode_list` (`ID`, `TITLE`, `TIMES_WATCHED`, `WANNA_WATCH`, `TWOPART_ID`, `LENGTH`) VALUES
(1, 'My Little Pony Friendship is Magic - Season 1 Episode 01 - Friendship Is Magic, Part 01', 0, 0, 2, 2),
(2, 'My Little Pony Friendship is Magic - Season 1 Episode 02 - Friendship Is Magic, Part 02', 0, 0, 1, 2),
(3, 'My Little Pony Friendship is Magic - Season 1 Episode 03 - The Ticket Master', 0, 0, NULL, 1);
-- ... (truncated for sample) ...

-- --------------------------------------------------------

--
-- Table structure for table `watching_now`
--

CREATE TABLE IF NOT EXISTS `watching_now` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `EPNUM` int(11) NOT NULL,
  `TITLE` text NOT NULL,
  PRIMARY KEY (`ID`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `online_sessions`
--

CREATE TABLE IF NOT EXISTS `online_sessions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `session_id` VARCHAR(128) NOT NULL,
    `user_id` INT DEFAULT NULL,
    `ip_address` VARCHAR(45),
    `user_agent` VARCHAR(255),
    `last_seen` DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_session` (`session_id`),
    KEY `idx_last_seen` (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `attempts_count` int(11) DEFAULT '0',
  `last_attempt_at` datetime DEFAULT NULL,
  `blocked_until` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ip_address` (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `chat_reactions`
--

CREATE TABLE IF NOT EXISTS `chat_reactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `message_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `reaction` varchar(32) NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_reaction` (`message_id`,`user_id`,`reaction`),
  KEY `message_id` (`message_id`),
  FOREIGN KEY (`message_id`) REFERENCES `chat_messages`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `events`
--

CREATE TABLE IF NOT EXISTS `events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `description` text,
  `start_time` datetime NOT NULL,
  `duration_minutes` int(11) NOT NULL DEFAULT '60',
  `is_recurring` tinyint(1) DEFAULT '0',
  `recurrence_rule` varchar(255) DEFAULT NULL,
  `use_playlist` tinyint(1) DEFAULT '0',
  `generate_new_playlist` tinyint(1) DEFAULT '0',
  `color` varchar(50) DEFAULT '#6d2f8e',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `start_time` (`start_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- (fix) перенесён INSERT user_options после CREATE TABLE
INSERT INTO `user_options` (`user_id`, `option_key`, `option_value`) VALUES
(2, 'chat_color', '#9b59b6'),
(2, 'avatar_url', 'https://i.imgur.com/K12X8rO.png');

-- --------------------------------------------------------

--
-- Table structure and seed for table `bot_commands`
-- (вмёржено из migrate_bot_commands.sql)
--

CREATE TABLE IF NOT EXISTS `bot_commands` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `command_prefix` varchar(50) NOT NULL COMMENT 'Например /schedule',
    `description` varchar(255) NOT NULL COMMENT 'Описание для админки',
    `handler_type` enum('text','schedule','poll','todo') NOT NULL DEFAULT 'text',
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

-- MLP-240: команда «создать опрос». См. migrations/2026_07_bot_commands_poll.sql
INSERT IGNORE INTO `bot_commands` (`command_prefix`, `description`, `handler_type`, `system_prompt`, `is_active`) VALUES
('/опрос', 'Лира создаёт опрос по теме', 'poll', 'Ты — Лира, придумай весёлый и уместный опрос для чата в своём стиле. Тема — из запроса пользователя, либо по последним сообщениям, если тема не задана. Вопрос — короткий и живой, варианты — остроумные, но понятные.', 1);

INSERT IGNORE INTO `bot_commands` (`command_prefix`, `description`, `handler_type`, `system_prompt`, `is_active`) VALUES
('/poll', 'Алиас команды опроса', 'poll', 'То же, что /опрос.', 1);

-- MLP-223 (remember-me): persistent-token «запомнить меня». См. migrations/2026_07_auth_tokens.sql
CREATE TABLE IF NOT EXISTS `auth_tokens` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `selector` CHAR(24) NOT NULL,
    `validator_hash` CHAR(64) NOT NULL,
    `user_id` INT NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_selector` (`selector`),
    INDEX `idx_user` (`user_id`),
    INDEX `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Опросы в чате (MLP-237, v4.7.0)
CREATE TABLE IF NOT EXISTS `polls` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `question`      VARCHAR(500) NOT NULL,
  `created_by`    INT NOT NULL,
  `created_at`    DATETIME NOT NULL,
  `status`        ENUM('open','closed') NOT NULL DEFAULT 'open',
  `is_multi`      TINYINT(1) NOT NULL DEFAULT 0,
  `is_anonymous`  TINYINT(1) NOT NULL DEFAULT 0,
  `closes_at`     DATETIME NULL,
  `closed_at`     DATETIME NULL,
  `message_id`    INT NULL,
  INDEX `idx_status` (`status`),
  INDEX `idx_message` (`message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `poll_options` (
  `id`        INT AUTO_INCREMENT PRIMARY KEY,
  `poll_id`   INT NOT NULL,
  `text`      VARCHAR(255) NOT NULL,
  `image_url` VARCHAR(500) NULL,
  `position`  TINYINT NOT NULL DEFAULT 0,
  INDEX `idx_poll` (`poll_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `poll_votes` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `poll_id`    INT NOT NULL,
  `option_id`  INT NOT NULL,
  `user_id`    INT NOT NULL,
  `created_at` DATETIME NOT NULL,
  UNIQUE KEY `uniq_vote` (`poll_id`,`user_id`,`option_id`),
  INDEX `idx_poll` (`poll_id`),
  INDEX `idx_poll_user` (`poll_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- BOT-QUEUE: очередь задач Лиры. См. migrations/2026_07_bot_queue.sql
--

CREATE TABLE IF NOT EXISTS `llm_jobs` (
  `id`         BIGINT       NOT NULL AUTO_INCREMENT,
  `type`       ENUM('mention','greeting','dynamic_command') NOT NULL,
  `payload`    JSON         NOT NULL COMMENT 'message, message_id, user_id, username, quoted_ids, command',
  `run_after`  DATETIME     NOT NULL COMMENT 'когда можно исполнять (lifelike-задержка)',
  `status`     ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
  `attempts`   TINYINT      NOT NULL DEFAULT 0,
  `created_at` DATETIME     NOT NULL,
  `claimed_at` DATETIME     NULL,
  PRIMARY KEY (`id`),
  KEY `idx_due` (`status`, `run_after`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `feedback_backlog` (MLP-270)
--

CREATE TABLE IF NOT EXISTS `feedback_backlog` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NULL,
    `username` VARCHAR(50) NOT NULL,
    `message_id` INT NULL,
    `text` TEXT NOT NULL,
    `status` ENUM('new','done','dismissed') NOT NULL DEFAULT 'new',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT INTO `bot_commands` (`command_prefix`, `description`, `handler_type`, `system_prompt`, `is_active`)
SELECT '/todo', 'Записать фидбек/идею в беклог (Лира сохранит и подтвердит)', 'todo', '', 1
WHERE NOT EXISTS (SELECT 1 FROM `bot_commands` WHERE `handler_type` = 'todo');
