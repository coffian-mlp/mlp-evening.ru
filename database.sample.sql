-- MLP-455 application schema snapshot.
-- Run php bootstrap.php for schema, required seed and migration registration.
SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `login` varchar(50) NOT NULL,
  `nickname` varchar(50) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` varchar(20) NOT NULL DEFAULT 'user',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `is_banned` tinyint(1) DEFAULT '0',
  `muted_until` datetime DEFAULT NULL,
  `ban_reason` varchar(255) DEFAULT NULL,
  `ban_until` datetime DEFAULT NULL,
  `reset_token_hash` varchar(64) DEFAULT NULL,
  `reset_token_expires` datetime DEFAULT NULL,
  `last_seen` datetime DEFAULT NULL,
  `roles_json` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `login` (`login`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_last_seen` (`last_seen`),
  CONSTRAINT `user_roles_baseline` CHECK (((`roles_json` is null) or ((json_type(`roles_json`) = _utf8mb4'ARRAY') and json_contains(`roles_json`,json_quote(_utf8mb4'user')))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `chat_messages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `username` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `edited_at` datetime DEFAULT NULL,
  `conversation_revision` bigint unsigned NOT NULL DEFAULT 0,
  `is_deleted` tinyint(1) DEFAULT '0',
  `deleted_at` datetime DEFAULT NULL,
  `quoted_msg_ids` json DEFAULT NULL,
  `is_pinned` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `created_at` (`created_at`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_pinned` (`is_pinned`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `account_privacy` (
  `user_id` int NOT NULL,
  `state` varchar(20) NOT NULL,
  `policy_version` varchar(40) NOT NULL,
  `accepted_at` datetime DEFAULT NULL,
  `withdrawn_at` datetime DEFAULT NULL,
  `delete_after` datetime DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  KEY `privacy_due` (`state`,`delete_after`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `audit_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `action` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_id` int DEFAULT NULL,
  `details` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `auth_tokens` (
  `id` int NOT NULL AUTO_INCREMENT,
  `selector` char(24) NOT NULL,
  `validator_hash` char(64) NOT NULL,
  `user_id` int NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_selector` (`selector`),
  KEY `idx_user` (`user_id`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `bot_commands` (
  `id` int NOT NULL AUTO_INCREMENT,
  `command_prefix` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Например /schedule',
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Описание для админки',
  `handler_type` enum('text','schedule','poll','todo','image','image_chat','memory_add','memory_show','memory_forget','reminder','recap','oc_set','ban','mute','unban','playlist','attendance') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'text',
  `system_prompt` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Шаблон промпта для ИИ',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `command_prefix` (`command_prefix`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `bot_memory` (
  `id` int NOT NULL AUTO_INCREMENT,
  `kind` enum('dossier','meme') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` int DEFAULT NULL COMMENT 'Субъект досье; NULL для мемов',
  `text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Один факт/мем, нормализован (одна строка)',
  `source` enum('manual','auto','oc') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `created_by` int DEFAULT NULL COMMENT 'Автор ручной записи',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_archived` tinyint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_kind_user` (`kind`,`user_id`),
  KEY `idx_memory_active` (`kind`,`is_archived`,`source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `chat_reactions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `message_id` int NOT NULL,
  `user_id` int NOT NULL,
  `reaction` varchar(32) NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_reaction` (`message_id`,`user_id`,`reaction`),
  KEY `message_id` (`message_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `chat_reactions_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `chat_messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_reactions_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `chat_stickers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `pack_id` int DEFAULT NULL,
  `code` varchar(50) NOT NULL COMMENT 'Код стикера без двоеточий',
  `image_url` varchar(255) NOT NULL,
  `thumb_url` varchar(255) DEFAULT NULL,
  `collection` varchar(50) DEFAULT 'default',
  `sort_order` int DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `pack_id` (`pack_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `command_interactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(64) NOT NULL,
  `owner_id` int NOT NULL,
  `source_message_id` int DEFAULT NULL,
  `source_version` char(64) NOT NULL,
  `bot_message_id` int DEFAULT NULL,
  `bot_user_id` int DEFAULT NULL,
  `options_json` json NOT NULL,
  `context_json` json DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `state` varchar(16) NOT NULL DEFAULT 'pending',
  `selected_key` varchar(64) DEFAULT NULL,
  `outcome_json` json DEFAULT NULL,
  `result_message_id` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `origin` varchar(16) NOT NULL DEFAULT 'command',
  `system_key` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `interaction_source` (`type`,`owner_id`,`source_message_id`),
  UNIQUE KEY `interaction_bot_message` (`bot_message_id`),
  UNIQUE KEY `interaction_system` (`type`,`system_key`),
  KEY `interaction_expiry` (`state`,`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `episode_list` (
  `ID` int NOT NULL AUTO_INCREMENT,
  `TITLE` text NOT NULL,
  `TIMES_WATCHED` int NOT NULL DEFAULT '0',
  `WANNA_WATCH` int NOT NULL DEFAULT '0',
  `TWOPART_ID` int DEFAULT NULL,
  `LENGTH` int NOT NULL DEFAULT '1',
  `legacy_wanna_watch` int NOT NULL DEFAULT '0',
  `IMDB_ID` varchar(16) DEFAULT NULL,
  `IMDB_RATING` decimal(3,1) DEFAULT NULL,
  `IMDB_VOTES` int unsigned DEFAULT NULL,
  `IMDB_HISTOGRAM` json DEFAULT NULL,
  `IMDB_SD` decimal(12,9) DEFAULT NULL,
  `IMDB_RETRIEVED_AT` datetime DEFAULT NULL,
  `IMDB_SOURCE_ASOF` datetime DEFAULT NULL,
  `IMDB_PROVENANCE` json DEFAULT NULL,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `uq_episode_imdb_id` (`IMDB_ID`),
  KEY `idx_episode_imdb_rating` (`IMDB_RATING`),
  KEY `idx_episode_imdb_sd` (`IMDB_SD`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `episode_wish_events` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `operation_key` varchar(190) NOT NULL,
  `user_id` int DEFAULT NULL,
  `episode_id` int NOT NULL,
  `kind` varchar(20) NOT NULL,
  `status` varchar(20) NOT NULL,
  `created_at` datetime NOT NULL,
  `outcome_json` json NOT NULL,
  `snapshot_id` int DEFAULT NULL,
  `fulfilled_completion_key` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `operation_key` (`operation_key`),
  KEY `user_id` (`user_id`,`created_at`),
  KEY `episode_id` (`episode_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `episode_wish_locks` (
  `user_id` int NOT NULL,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `episode_wishes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `episode_id` int NOT NULL,
  `status` varchar(20) NOT NULL,
  `accepted_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `fulfilled_snapshot_id` int DEFAULT NULL,
  `fulfilled_completion_key` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `status` (`status`,`episode_id`),
  KEY `idx_episode_wish_owner` (`user_id`,`episode_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `events` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `start_time` datetime NOT NULL,
  `duration_minutes` int NOT NULL DEFAULT '60',
  `is_recurring` tinyint(1) DEFAULT '0',
  `recurrence_rule` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `use_playlist` tinyint(1) DEFAULT '0',
  `generate_new_playlist` tinyint(1) DEFAULT '0',
  `color` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '#6d2f8e',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `monitor_stream` tinyint NOT NULL DEFAULT '0',
  `streamer_user_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `start_time` (`start_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `feedback_backlog` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `username` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `message_id` int DEFAULT NULL,
  `text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('new','done','dismissed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'new',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `llm_debug_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `kind` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `provider` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `model` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `status` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ok',
  `duration_ms` int unsigned NOT NULL DEFAULT '0',
  `request` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `response` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `llm_jobs` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `type` enum('mention','greeting','dynamic_command','cron_spontaneous','machine_spirit','stream_command','memory_scribe','evening_summary') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` json NOT NULL COMMENT 'message, message_id, user_id, username, quoted_ids, command',
  `run_after` datetime NOT NULL COMMENT 'когда можно исполнять (lifelike-задержка)',
  `status` enum('pending','processing','done','failed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `attempts` tinyint NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL,
  `claimed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_due` (`status`,`run_after`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `llm_usage` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `provider` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `task` varchar(48) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `duration_ms` int unsigned NOT NULL,
  `input_tokens` bigint unsigned DEFAULT NULL,
  `output_tokens` bigint unsigned DEFAULT NULL,
  `total_tokens` bigint unsigned DEFAULT NULL,
  `estimated_cost` decimal(22,8) DEFAULT NULL,
  `currency` char(3) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `login_attempts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `attempts_count` int DEFAULT '0',
  `last_attempt_at` datetime DEFAULT NULL,
  `blocked_until` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ip_address` (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `lyra_notification_links` (
  `user_id` int NOT NULL,
  `telegram_uid` varchar(32) NOT NULL,
  `bot_fingerprint` char(64) NOT NULL,
  `chat_id` bigint DEFAULT NULL,
  `connected_at` datetime DEFAULT NULL,
  `token_hash` char(64) DEFAULT NULL,
  `token_expires` datetime DEFAULT NULL,
  `disabled_reason` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `notification_token` (`token_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `menu_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `parent_id` int DEFAULT NULL COMMENT 'NULL = корневой; 2 уровня max',
  `title` varchar(100) NOT NULL,
  `url` varchar(255) DEFAULT NULL COMMENT 'NULL = некликабельная раскрывашка',
  `sort_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `visibility` enum('all','users','admins') NOT NULL DEFAULT 'all',
  `is_external` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'новая вкладка + значок ↗',
  `show_in_header` tinyint(1) NOT NULL DEFAULT '1',
  `show_in_burger` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `parent_id` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `online_sessions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `session_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` int DEFAULT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_seen` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_session` (`session_id`),
  KEY `idx_last_seen` (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `playlist_completions` (
  `completion_key` varchar(100) NOT NULL,
  `snapshot_id` int NOT NULL,
  `run_id` varchar(100) DEFAULT NULL,
  `start_at` datetime NOT NULL,
  `completed_at` datetime NOT NULL,
  `shown_json` json NOT NULL,
  PRIMARY KEY (`completion_key`),
  KEY `snapshot_id` (`snapshot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `playlist_corrections` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `snapshot_id` int NOT NULL,
  `actor_id` int NOT NULL,
  `operation_key` varchar(190) NOT NULL,
  `created_at` datetime NOT NULL,
  `before_json` json NOT NULL,
  `after_json` json NOT NULL,
  `completion_key` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `operation_key` (`operation_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `playlist_occurrences` (
  `run_id` varchar(100) NOT NULL,
  `event_id` int NOT NULL,
  `start_at` datetime NOT NULL,
  `end_at` datetime NOT NULL,
  `snapshot_id` int NOT NULL,
  `state` varchar(30) NOT NULL DEFAULT 'pending',
  `metadata_json` json NOT NULL,
  `generated_snapshot_id` int DEFAULT NULL,
  `outcome_json` json DEFAULT NULL,
  PRIMARY KEY (`run_id`),
  KEY `state` (`state`,`end_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `playlist_snapshots` (
  `id` int NOT NULL AUTO_INCREMENT,
  `created_at` datetime NOT NULL,
  `payload_json` json NOT NULL,
  `completed_at` datetime DEFAULT NULL,
  `completion_json` json DEFAULT NULL,
  `origin` varchar(30) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `poll_options` (
  `id` int NOT NULL AUTO_INCREMENT,
  `poll_id` int NOT NULL,
  `text` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `image_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `position` tinyint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_poll` (`poll_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `poll_votes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `poll_id` int NOT NULL,
  `option_id` int NOT NULL,
  `user_id` int NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_vote` (`poll_id`,`user_id`,`option_id`),
  KEY `idx_poll` (`poll_id`),
  KEY `idx_poll_user` (`poll_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `polls` (
  `id` int NOT NULL AUTO_INCREMENT,
  `question` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` int NOT NULL,
  `created_at` datetime NOT NULL,
  `status` enum('open','closed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `is_multi` tinyint(1) NOT NULL DEFAULT '0',
  `is_anonymous` tinyint(1) NOT NULL DEFAULT '0',
  `closes_at` datetime DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `message_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_message` (`message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `privacy_account_choices` (
  `user_id` int NOT NULL,
  `analytics` tinyint NOT NULL DEFAULT '0',
  `external_media` tinyint NOT NULL DEFAULT '0',
  `policy_version` varchar(40) NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `privacy_browser_owners` (
  `token_hash` char(64) NOT NULL,
  `user_id` int NOT NULL,
  PRIMARY KEY (`token_hash`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `privacy_choices` (
  `token_hash` char(64) NOT NULL,
  `analytics` tinyint NOT NULL DEFAULT '0',
  `external_media` tinyint NOT NULL DEFAULT '0',
  `policy_version` varchar(40) NOT NULL,
  `updated_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`token_hash`),
  KEY `expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `privacy_consent_events` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `kind` varchar(20) NOT NULL,
  `policy_version` varchar(40) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `privacy_files` (
  `path` varchar(500) NOT NULL,
  `user_id` int DEFAULT NULL,
  `delete_pending` tinyint NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`path`),
  KEY `user_id` (`user_id`),
  KEY `delete_pending` (`delete_pending`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `privacy_mail_cleanup` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_mail` (`user_id`,`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `privacy_sessions` (
  `session_id` varchar(128) NOT NULL,
  `user_id` int DEFAULT NULL,
  `delete_pending` tinyint NOT NULL DEFAULT '0',
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`session_id`),
  KEY `user_id` (`user_id`),
  KEY `delete_pending` (`delete_pending`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `reminders` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `username` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Снапшот ника для адресации при доставке',
  `text` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `remind_at` datetime NOT NULL COMMENT 'UTC',
  `status` enum('pending','done','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_due` (`status`,`remind_at`),
  KEY `idx_user` (`user_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `site_options` (
  `key_name` varchar(50) NOT NULL,
  `value` text,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `sticker_packs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL COMMENT 'Уникальный код пака',
  `name` varchar(100) NOT NULL COMMENT 'Название пака',
  `icon_url` varchar(255) DEFAULT NULL,
  `author_name` varchar(120) DEFAULT NULL,
  `author_url` varchar(2048) DEFAULT NULL,
  `sort_order` int DEFAULT '0',
  `is_active` tinyint(1) DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `stream_incidents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `run_id` varchar(80) NOT NULL,
  `nonce` char(16) NOT NULL,
  `state` varchar(24) NOT NULL DEFAULT 'open',
  `lost_at` datetime NOT NULL,
  `acknowledged_at` datetime DEFAULT NULL,
  `acknowledged_by` int DEFAULT NULL,
  `recovered_at` datetime DEFAULT NULL,
  `interaction_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `incident_run` (`run_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `stream_monitor_deliveries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `incident_id` bigint unsigned NOT NULL,
  `stage` varchar(32) NOT NULL,
  `state` varchar(16) NOT NULL DEFAULT 'pending',
  `attempts` int NOT NULL DEFAULT '0',
  `retry_at` datetime DEFAULT NULL,
  `message_id` bigint DEFAULT NULL,
  `reason` varchar(100) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `incident_stage` (`incident_id`,`stage`),
  KEY `delivery_pending` (`state`,`retry_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `stream_monitor_runs` (
  `run_id` varchar(80) NOT NULL,
  `event_id` int NOT NULL,
  `streamer_user_id` int NOT NULL,
  `ends_at` datetime NOT NULL,
  `path` varchar(64) NOT NULL,
  `health` varchar(16) NOT NULL DEFAULT 'unknown',
  `last_checked` datetime DEFAULT NULL,
  `progress_key` varchar(160) DEFAULT NULL,
  `progress_at` datetime DEFAULT NULL,
  `healthy_since` datetime DEFAULT NULL,
  `armed` tinyint NOT NULL DEFAULT '0',
  `lost_at` datetime DEFAULT NULL,
  `recovered_since` datetime DEFAULT NULL,
  `incident_id` bigint unsigned DEFAULT NULL,
  `ended` tinyint NOT NULL DEFAULT '0',
  PRIMARY KEY (`run_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `stream_reaction_limits` (
  `user_id` int NOT NULL,
  `last_at` datetime NOT NULL,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `stream_reactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `reaction` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `telegram_announcement_bot_cursors` (
  `bot_id` bigint NOT NULL,
  `update_offset` bigint NOT NULL DEFAULT '0',
  PRIMARY KEY (`bot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `telegram_announcement_bot_updates` (
  `bot_id` bigint NOT NULL,
  `update_id` bigint NOT NULL,
  `processed_at` datetime NOT NULL,
  PRIMARY KEY (`bot_id`,`update_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `telegram_announcement_campaigns` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `run_id` varchar(100) NOT NULL,
  `stream_number` int unsigned NOT NULL,
  `start_at` datetime NOT NULL,
  `fingerprint` char(64) NOT NULL,
  `facts_json` mediumtext NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `run_unique` (`run_id`),
  UNIQUE KEY `number_unique` (`stream_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `telegram_announcement_posts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `campaign_id` bigint unsigned NOT NULL,
  `kind` varchar(16) NOT NULL,
  `current_revision` bigint unsigned DEFAULT NULL,
  `due_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `campaign_kind` (`campaign_id`,`kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `telegram_announcement_revisions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `post_id` bigint unsigned NOT NULL,
  `version` int unsigned NOT NULL,
  `nonce` char(16) NOT NULL,
  `state` varchar(24) NOT NULL DEFAULT 'text_pending',
  `caption` text,
  `previous_caption` text,
  `image_path` varchar(255) DEFAULT NULL,
  `feedback` text NOT NULL,
  `preview_photo_id` bigint DEFAULT NULL,
  `preview_controls_id` bigint DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `channel_message_id` bigint DEFAULT NULL,
  `last_error` varchar(100) DEFAULT NULL,
  `retry_at` datetime DEFAULT NULL,
  `attempts` int unsigned NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `post_version` (`post_id`,`version`),
  KEY `pending` (`state`,`retry_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `telegram_announcement_updates` (
  `update_id` bigint NOT NULL,
  `processed_at` datetime NOT NULL,
  PRIMARY KEY (`update_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `user_options` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `option_key` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `option_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_option` (`user_id`,`option_key`),
  CONSTRAINT `user_options_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_socials` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `provider` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'telegram, discord, vk',
  `provider_uid` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ID пользователя в соцсети',
  `username` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Никнейм в соцсети',
  `first_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `avatar_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_provider_user` (`provider`,`provider_uid`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `user_socials_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ux_daily` (
  `day` date NOT NULL,
  `dimension` varchar(25) NOT NULL,
  `label` varchar(64) NOT NULL,
  `views` int NOT NULL,
  `visits` int NOT NULL,
  PRIMARY KEY (`day`,`dimension`,`label`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `ux_pageviews` (
  `page_id` char(32) NOT NULL,
  `visit_id` char(32) NOT NULL,
  `consent_hash` char(64) NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `path` varchar(64) NOT NULL,
  `device` varchar(20) NOT NULL,
  `browser` varchar(40) NOT NULL,
  `os` varchar(30) NOT NULL,
  `screen` varchar(30) NOT NULL,
  `viewport` varchar(30) NOT NULL,
  `density` decimal(4,2) NOT NULL,
  `pinch_scale` decimal(4,2) DEFAULT NULL,
  `font_scale` smallint NOT NULL,
  `theme` varchar(20) NOT NULL,
  `load_ms` int DEFAULT NULL,
  `chat_error` tinyint NOT NULL DEFAULT '0',
  `player_error` tinyint NOT NULL DEFAULT '0',
  `overflow` tinyint NOT NULL DEFAULT '0',
  PRIMARY KEY (`page_id`),
  KEY `created_at` (`created_at`),
  KEY `consent_hash` (`consent_hash`),
  KEY `visit_id` (`visit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `ux_peaks` (
  `day` date NOT NULL,
  `peak` int NOT NULL,
  PRIMARY KEY (`day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `watching_now` (
  `ID` int NOT NULL AUTO_INCREMENT,
  `EPNUM` int NOT NULL,
  `TITLE` text NOT NULL,
  PRIMARY KEY (`ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS bot_conversation_summaries (
    session_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    first_id INT NOT NULL,
    started_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    cursor_id INT NOT NULL DEFAULT 0,
    source_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
    summary_text TEXT NOT NULL,
    checked_at DATETIME(6) NOT NULL DEFAULT '1970-01-01 00:00:00.000000',
    has_data TINYINT GENERATED ALWAYS AS (CHAR_LENGTH(summary_text)>0 OR CHAR_LENGTH(source_hash)>0 OR cursor_id<>0) STORED,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_attempt DATETIME NULL,
    disabled TINYINT NOT NULL DEFAULT 0,
    KEY conversation_expiry (expires_at),
    KEY conversation_check (checked_at,session_key),
    KEY conversation_material (has_data,expires_at),
    KEY conversation_start (started_at,first_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bot_memory_arrivals (
    user_id INT NOT NULL PRIMARY KEY,
    token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    gap_seconds BIGINT UNSIGNED NOT NULL,
    consent_revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
    checked_at DATETIME(6) NOT NULL DEFAULT '1970-01-01 00:00:00.000000',
    expires_at DATETIME NOT NULL,
    state VARCHAR(16) NOT NULL DEFAULT 'pending',
    claimed_at DATETIME NULL,
    greeting_reserved_at DATETIME NULL,
    delivery_message_id INT NULL,
    KEY arrival_expiry (expires_at),
    KEY arrival_check (checked_at,user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
