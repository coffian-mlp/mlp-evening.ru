<?php
// init.php - Bootstrap файла
ob_start(); // Start Output Buffering for asset injection

// 1. Подключение конфигов
require_once __DIR__ . '/config.php';

// 2. Автозагрузка классов (MLP-248, ADR-7): PSR-4 от src/ + classmap.
// Ручной require_once классов запрещён (architecture.md, §13).
require_once __DIR__ . '/autoload.php';

// 3. Инициализация сессии
Auth::check();
Auth::tryRememberLogin(); // remember-me: авто-вход по cookie, если сессии нет (MLP-223)

// 5. Инициализация приложения
global $app;
$app = \Core\Application::getInstance();

// Отладка
if (ConfigManager::getInstance()->getOption('debug_mode', 0)) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
}
