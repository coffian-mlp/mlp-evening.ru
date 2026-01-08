CREATE TABLE IF NOT EXISTS `chat_stickers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL COMMENT 'Код стикера без двоеточий (например: twilight_happy)',
  `image_url` varchar(255) NOT NULL,
  `collection` varchar(50) DEFAULT 'default' COMMENT 'Название коллекции/пака',
  `sort_order` int(11) DEFAULT '0' COMMENT 'Для сортировки вывода',
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

