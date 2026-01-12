     1|-- phpMyAdmin SQL Dump
     2|-- version 5.2.2
     3|-- https://www.phpmyadmin.net/
     4|--
     5|-- Host: localhost
     6|-- Server version: 5.7.35-38
     7|-- PHP Version: 8.3
     8|
     9|SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
    10|SET time_zone = "+00:00";
    11|
    12|/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
    13|/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
    14|/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
    15|/*!40101 SET NAMES utf8mb4 */;
    16|
    17|--
    18|-- Database: `coffian_eplist`
    19|--
    20|
    21|-- --------------------------------------------------------
    22|
    23|--
    24|-- Table structure for table `site_options`
    25|--
    26|
    27|CREATE TABLE IF NOT EXISTS `site_options` (
    28|  `key_name` varchar(50) NOT NULL,
    29|  `value` text,
    30|  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    31|  PRIMARY KEY (`key_name`)
    32|) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    33|
    34|--
    35|-- Dumping data for table `site_options`
    36|--
    37|
    38|INSERT INTO `site_options` (`key_name`, `value`) VALUES
    39|('stream_url', 'https://example.com/stream');
    40|
    41|-- --------------------------------------------------------
    42|
    43|--
    44|-- Table structure for table `users`
    45|--
    46|
    47|CREATE TABLE IF NOT EXISTS `users` (
    48|  `id` int(11) NOT NULL AUTO_INCREMENT,
    49|  `login` varchar(50) NOT NULL UNIQUE,
    50|  `nickname` varchar(50) NOT NULL,
    51|  `password_hash` varchar(255) NOT NULL,
    52|  `role` varchar(20) NOT NULL DEFAULT 'user',
    53|  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    54|  `is_banned` tinyint(1) DEFAULT 0,
    55|  `muted_until` datetime DEFAULT NULL,
    56|  `ban_reason` varchar(255) DEFAULT NULL,
    57|  `last_seen` datetime DEFAULT NULL,
    58|  PRIMARY KEY (`id`),
    59|  KEY `idx_last_seen` (`last_seen`)
    60|) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    61|
    62|--
    63|-- Dumping data for table `users`
    64|--
    65|-- Default admin user. 
    66|-- Login: admin
    67|-- Password: password (hash below is for 'password')
    68|
    69|INSERT INTO `users` (`login`, `password_hash`, `role`) VALUES
    70|('admin', '$2y$10$n.9/9/9/9/9/9/9/9/9/9/9/9/9/9/9/9/9/9/9/9/9/9/9/9/9', 'admin');
    71|
    72|-- --------------------------------------------------------
    73|
    74|--
    75|-- Table structure for table `chat_messages`
    76|--
    77|
    78|CREATE TABLE IF NOT EXISTS `chat_messages` (
    79|    `id` INT AUTO_INCREMENT PRIMARY KEY,
    80|    `user_id` INT NULL,
    81|    `username` VARCHAR(50) NOT NULL,
    82|    `message` TEXT NOT NULL,
    83|    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    84|    `edited_at` DATETIME DEFAULT NULL,
    85|    `is_deleted` TINYINT(1) DEFAULT 0,
    86|    `deleted_at` DATETIME DEFAULT NULL,
    87|    `quoted_msg_ids` JSON DEFAULT NULL,
    88|    INDEX (`created_at`)
    89|) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;
    90|
    91|-- --------------------------------------------------------
    92|
    93|--
    94|-- Table structure for table `audit_logs`
    95|--
    96|
    97|CREATE TABLE IF NOT EXISTS `audit_logs` (
    98|  `id` int(11) NOT NULL AUTO_INCREMENT,
    99|  `user_id` int(11) DEFAULT NULL, -- Moderator ID
   100|  `action` varchar(50) NOT NULL,
   101|  `target_id` int(11) DEFAULT NULL, -- Target User ID or Message ID
   102|  `details` text,
   103|  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
   104|  PRIMARY KEY (`id`),
   105|  INDEX (`user_id`),
   106|  INDEX (`action`)
   107|) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
   108|
   109|-- --------------------------------------------------------
   110|
   111|--
   112|-- Table structure for table `user_options`
   113|--
   114|
   115|CREATE TABLE IF NOT EXISTS `user_options` (
   116|  `id` INT AUTO_INCREMENT PRIMARY KEY,
   117|  `user_id` INT NOT NULL,
   118|  `option_key` VARCHAR(50) NOT NULL,
   119|  `option_value` TEXT,
   120|  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
   121|  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
   122|  UNIQUE KEY `unique_user_option` (`user_id`, `option_key`)
   123|) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
   124|
   125|-- --------------------------------------------------------
   126|
   127|--
   128|-- Table structure for table `sticker_packs`
   129|--
   130|
   131|CREATE TABLE IF NOT EXISTS `sticker_packs` (
   132|  `id` int(11) NOT NULL AUTO_INCREMENT,
   133|  `code` varchar(50) NOT NULL COMMENT 'Уникальный код пака',
   134|  `name` varchar(100) NOT NULL COMMENT 'Название пака',
   135|  `icon_url` varchar(255) DEFAULT NULL,
   136|  `sort_order` int(11) DEFAULT '0',
   137|  `is_active` tinyint(1) DEFAULT '1',
   138|  PRIMARY KEY (`id`),
   139|  UNIQUE KEY `code` (`code`)
   140|) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
   141|
   142|--
   143|-- Table structure for table `chat_stickers`
   144|--
   145|
   146|CREATE TABLE IF NOT EXISTS `chat_stickers` (
   147|  `id` int(11) NOT NULL AUTO_INCREMENT,
   148|  `pack_id` int(11) DEFAULT NULL,
   149|  `code` varchar(50) NOT NULL COMMENT 'Код стикера без двоеточий',
   150|  `image_url` varchar(255) NOT NULL,
   151|  `collection` varchar(50) DEFAULT 'default',
   152|  `sort_order` int(11) DEFAULT '0',
   153|  PRIMARY KEY (`id`),
   154|  UNIQUE KEY `code` (`code`),
   155|  KEY `pack_id` (`pack_id`)
   156|) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
   157|
   158|-- --------------------------------------------------------
   159|
   160|--
   161|-- Table structure for table `user_socials`
   162|--
   163|
   164|CREATE TABLE IF NOT EXISTS `user_socials` (
   165|    `id` INT AUTO_INCREMENT PRIMARY KEY,
   166|    `user_id` INT NOT NULL,
   167|    `provider` VARCHAR(32) NOT NULL COMMENT 'telegram, discord, vk',
   168|    `provider_uid` VARCHAR(255) NOT NULL COMMENT 'ID пользователя в соцсети',
   169|    `username` VARCHAR(255) NULL COMMENT 'Никнейм в соцсети',
   170|    `first_name` VARCHAR(255) NULL,
   171|    `last_name` VARCHAR(255) NULL,
   172|    `avatar_url` VARCHAR(255) NULL,
   173|    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
   174|    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
   175|    UNIQUE KEY `unique_provider_user` (`provider`, `provider_uid`)
   176|) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
   177|
   178|-- --------------------------------------------------------
   179|
   180|--
   181|-- Table structure for table `episode_list`
   182|--
   183|
   184|CREATE TABLE IF NOT EXISTS `episode_list` (
   185|  `ID` int(11) NOT NULL AUTO_INCREMENT,
   186|  `TITLE` text NOT NULL,
   187|  `TIMES_WATCHED` int(11) NOT NULL DEFAULT '0',
   188|  `WANNA_WATCH` int(11) NOT NULL DEFAULT '0',
   189|  `TWOPART_ID` int(11) DEFAULT NULL,
   190|  `LENGTH` int(11) NOT NULL DEFAULT '1',
   191|  PRIMARY KEY (`ID`)
   192|) ENGINE=InnoDB AUTO_INCREMENT=225 DEFAULT CHARSET=utf8mb4;
   193|
   194|--
   195|-- Dumping data for table `episode_list`
   196|--
   197|
   198|INSERT INTO `episode_list` (`ID`, `TITLE`, `TIMES_WATCHED`, `WANNA_WATCH`, `TWOPART_ID`, `LENGTH`) VALUES
   199|(1, 'My Little Pony Friendship is Magic - Season 1 Episode 01 - Friendship Is Magic, Part 01', 0, 0, 2, 2),
   200|(2, 'My Little Pony Friendship is Magic - Season 1 Episode 02 - Friendship Is Magic, Part 02', 0, 0, 1, 2),
   201|(3, 'My Little Pony Friendship is Magic - Season 1 Episode 03 - The Ticket Master', 0, 0, NULL, 1);
   202|-- ... (truncated for sample) ...
   203|
   204|-- --------------------------------------------------------
   205|
   206|--
   207|-- Table structure for table `watching_now`
   208|--
   209|
   210|CREATE TABLE IF NOT EXISTS `watching_now` (
   211|  `ID` int(11) NOT NULL AUTO_INCREMENT,
   212|  `EPNUM` int(11) NOT NULL,
   213|  `TITLE` text NOT NULL,
   214|  PRIMARY KEY (`ID`)
   215|) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4;
   216|
   217|-- --------------------------------------------------------
   218|
   219|--
   220|-- Table structure for table `online_sessions`
   221|--
   222|
   223|CREATE TABLE IF NOT EXISTS `online_sessions` (
   224|    `id` INT AUTO_INCREMENT PRIMARY KEY,
   225|    `session_id` VARCHAR(128) NOT NULL,
   226|    `user_id` INT DEFAULT NULL,
   227|    `ip_address` VARCHAR(45),
   228|    `user_agent` VARCHAR(255),
   229|    `last_seen` DATETIME DEFAULT CURRENT_TIMESTAMP,
   230|    UNIQUE KEY `unique_session` (`session_id`),
   231|    KEY `idx_last_seen` (`last_seen`)
   232|) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
   233|
   234|/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
   235|/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
   236|/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

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
   237|