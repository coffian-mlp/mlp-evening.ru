-- 1. Создаем таблицу коллекций (паков)
CREATE TABLE IF NOT EXISTS `sticker_packs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL COMMENT 'Уникальный код пака',
  `name` varchar(100) NOT NULL COMMENT 'Отображаемое название',
  `icon_url` varchar(255) DEFAULT NULL,
  `sort_order` int(11) DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Временная миграция данных: Создаем дефолтный пак
INSERT INTO `sticker_packs` (`code`, `name`) VALUES ('default', 'Standard Pack')
ON DUPLICATE KEY UPDATE id=id;

-- 3. Обновляем таблицу стикеров: меняем collection (string) на pack_id (int)
-- Сначала добавляем колонку
ALTER TABLE `chat_stickers` ADD COLUMN `pack_id` int(11) DEFAULT NULL AFTER `image_url`;

-- Пытаемся связать существующие стикеры с паками (если мы их уже насоздавали)
-- Для упрощения сейчас привяжем всё к дефолтному паку, так как мы только начали
UPDATE `chat_stickers` SET `pack_id` = (SELECT id FROM sticker_packs WHERE code='default');

-- Удаляем старую колонку collection
ALTER TABLE `chat_stickers` DROP COLUMN `collection`;

-- Делаем pack_id обязательным и добавляем внешний ключ
ALTER TABLE `chat_stickers` MODIFY COLUMN `pack_id` int(11) NOT NULL;
ALTER TABLE `chat_stickers` ADD CONSTRAINT `fk_sticker_pack` FOREIGN KEY (`pack_id`) REFERENCES `sticker_packs` (`id`) ON DELETE CASCADE;

