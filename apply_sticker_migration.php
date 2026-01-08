<?php
// Скрипт для применения миграции таблицы стикеров
// Использует существующее подключение и конфиг

require_once __DIR__ . '/src/Database.php';

try {
    $db = Database::getInstance()->getConnection();
    
    echo "Подключение к БД успешно.\n";
    
    $sql = "CREATE TABLE IF NOT EXISTS `chat_stickers` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `code` varchar(50) NOT NULL COMMENT 'Код стикера без двоеточий (например: twilight_happy)',
      `image_url` varchar(255) NOT NULL,
      `collection` varchar(50) DEFAULT 'default' COMMENT 'Название коллекции/пака',
      `sort_order` int(11) DEFAULT '0' COMMENT 'Для сортировки вывода',
      PRIMARY KEY (`id`),
      UNIQUE KEY `code` (`code`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    
    if ($db->query($sql)) {
        echo "Таблица `chat_stickers` успешно создана или уже существует.\n";
    } else {
        echo "Ошибка при создании таблицы: " . $db->error . "\n";
    }
    
} catch (Exception $e) {
    echo "Критическая ошибка: " . $e->getMessage() . "\n";
}

