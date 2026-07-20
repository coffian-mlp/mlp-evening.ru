<?php
use Domain\Auth;

require_once __DIR__ . '/../init.php';

// 🔒 ЗАЩИТА: Только для авторизованных
Auth::requireAdmin();

// DB-операции (get_row/update_row/export) — через api.php (MLP-255: Api\DbAdminController).

$app->setTitle('Dashboard - MLP Evening');
$app->addCss('/assets/css/dashboard.css');
$app->addJs('/assets/js/dashboard.js');

$bodyClass = 'dashboard-layout';
$showChatBro = false; 
$showPageHeader = true; // Включаем общий хедер

require_once __DIR__ . '/../src/templates/header.php';
?>

<div class="container">

    <!-- Навигация (компактный таб-бар, MLP-256: 7 вкладок, «Настройки» первой) -->
    <div class="nav-grid">
        <div class="nav-tile active" data-target="#tab-settings">
            <div class="icon">⚙️</div>
            <div class="label">Настройки</div>
        </div>
        <div class="nav-tile" data-target="#tab-bot">
            <div class="icon">🤖</div>
            <div class="label">Бот</div>
        </div>
        <div class="nav-tile" data-target="#tab-episodes">
            <div class="icon">🎬</div>
            <div class="label">Эпизоды</div>
        </div>
        <div class="nav-tile" data-target="#tab-users">
            <div class="icon">👥</div>
            <div class="label">Пользователи</div>
        </div>
        <div class="nav-tile" data-target="#tab-events">
            <div class="icon">📅</div>
            <div class="label">Календарь</div>
        </div>
        <div class="nav-tile" data-target="#tab-stickers">
            <div class="icon">😊</div>
            <div class="label">Стикеры</div>
        </div>
        <div class="nav-tile" data-target="#tab-database">
            <div class="icon">💾</div>
            <div class="label">База Данных</div>
        </div>
    </div>

    <!-- Вкладка 1: Настройки (системные, чат, соцавторизация, SMTP, плеер) -->
    <div id="tab-settings" class="tab-content active">
        <?php $app->includeComponent('AdminSettings', 'general'); ?>
    </div>

    <!-- Вкладка 2: Бот (ИИ-настройки + команды) -->
    <div id="tab-bot" class="tab-content">
        <?php $app->includeComponent('AdminSettings', 'ai'); ?>
        <?php $app->includeComponent('AdminBotCommands'); ?>
    </div>

    <!-- Вкладка 3: Эпизоды (плейлист + библиотека + история + инструменты) -->
    <div id="tab-episodes" class="tab-content">
        <?php $app->includeComponent('AdminPlaylist'); ?>
        <?php $app->includeComponent('AdminSettings', 'episode-tools'); ?>
        <?php $app->includeComponent('AdminLibrary'); ?>
        <?php $app->includeComponent('AdminHistory'); ?>
    </div>

    <!-- Вкладка 4: Пользователи (список + модерация) -->
    <div id="tab-users" class="tab-content">
        <?php $app->includeComponent('AdminUsers'); ?>
        <?php $app->includeComponent('AdminModeration'); ?>
    </div>

    <!-- Вкладка 5: Календарь -->
    <div id="tab-events" class="tab-content">
        <?php $app->includeComponent('AdminEvents'); ?>
    </div>

    <!-- Вкладка 6: Стикеры -->
    <div id="tab-stickers" class="tab-content">
        <?php $app->includeComponent('AdminStickers'); ?>
    </div>

    <!-- Вкладка 7: База Данных (id сохранён — серверные deep-links DbAdmin) -->
    <div id="tab-database" class="tab-content">
        <?php $app->includeComponent('DbAdmin'); ?>
    </div>

</div>

<?php require_once __DIR__ . '/../src/templates/footer.php'; ?>
