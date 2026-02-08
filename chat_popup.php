<?php
// chat_popup.php - Standalone Chat Window
require_once __DIR__ . '/init.php';

$app->setTitle('Чат - Поняшный вечерок');

// Стили для попапа теперь подключаются автоматически из шаблона 'popup' компонента Chat
// $app->addCss('/assets/css/main.css'); // Этот стиль может потребоваться, если он не подключен внутри шаблона,
// но лучше, чтобы компонент был самодостаточным. Однако глобальные переменные CSS часто нужны.
// Добавим его здесь явно, так как popup - это "голая" страница без main-header.
$app->addCss('/assets/css/main.css');

// Вставляем HEAD (который вызовет $app->showHead())
require_once __DIR__ . '/src/templates/header.php';

// Подключаем компонент Чата с шаблоном 'popup'
$app->includeComponent('Chat', 'popup', [
    'mode' => 'popup'
]);

// Подключаем FOOTER (скрипты и finalize)
require_once __DIR__ . '/src/templates/footer.php';
