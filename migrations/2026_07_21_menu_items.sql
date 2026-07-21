-- MLP-259: меню сайта — таблица пунктов + сид базовой навигации.
-- Идемпотентность: таблица IF NOT EXISTS, сид — только при пустой таблице.

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
