<?php

// Список папок, которые должны существовать и быть доступны для записи
$directories = [
    'logs',
    'cache',
    'upload',
    'upload/avatars',
    'upload/stickers',
    'upload/chat',
    'upload/icon'
];

echo "🦄 Начинаю магию восстановления папок...\n";

foreach ($directories as $dir) {
    $path = __DIR__ . '/' . $dir;
    
    // 1. Создаем папку, если нет
    if (!is_dir($path)) {
        echo "➕ Создаю папку: $dir\n";
        if (!mkdir($path, 0777, true)) {
            echo "❌ Ошибка создания $dir\n";
            continue;
        }
    } else {
        echo "✅ Папка есть: $dir\n";
    }

    // 2. Ставим безопасные права 755 (rwxr-xr-x)
    // Владелец: RWX, Группа: RX, Мир: RX
    chmod($path, 0755);

    // 3. Создаем .gitkeep, чтобы Git видел папку
    $gitkeep = $path . '/.gitkeep';
    if (!file_exists($gitkeep)) {
        file_put_contents($gitkeep, ""); // Пустой файл
        echo "   📄 Добавлен .gitkeep\n";
    }
}

// 4. Дополнительно: создаем файлы логов, чтобы дать им права
$logFiles = ['logs/mail.log', 'logs/debug.log', 'logs/mail_errors.log'];
foreach ($logFiles as $file) {
    $path = __DIR__ . '/' . $file;
    if (!file_exists($path)) {
        touch($path);
        echo "📄 Создан лог-файл: $file\n";
    }
    chmod($path, 0644); // rw-r--r--
}

echo "\n✨ Готово! Все папки на месте (права 755/644).\n";
