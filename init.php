<?php
// init.php - Bootstrap файла
ob_start(); // Start Output Buffering for asset injection

// 1. Подключение конфигов
require_once __DIR__ . '/config.php';

// 2. Подключение классов ядра (Ручной autoload, как просил!)
require_once __DIR__ . '/src/Core/Application.php';
require_once __DIR__ . '/src/Core/Component.php';

// 3. Подключение классов проекта (Helpers)
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/ConfigManager.php';
require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/UserManager.php';
require_once __DIR__ . '/src/EpisodeManager.php';
require_once __DIR__ . '/src/StickerManager.php';
require_once __DIR__ . '/src/CentrifugoService.php';

// 4. Инициализация сессии
Auth::check();

// 5. Инициализация приложения
global $app;
$app = \Core\Application::getInstance();
