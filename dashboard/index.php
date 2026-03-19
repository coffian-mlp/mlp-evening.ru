<?php

require_once __DIR__ . '/../init.php';

// 🔒 ЗАЩИТА: Только для авторизованных
Auth::requireAdmin();

// --- DB API ACTIONS (Must be before output) ---
if (isset($_GET['db_action']) || (isset($_POST['db_action']) && $_POST['db_action'] === 'update_row')) {
    require_once __DIR__ . '/../src/Components/DbAdmin/class.php';
    $dbAdmin = new \Components\DbAdmin\DbAdminComponent('DbAdmin', 'default', []);
    
    // Handle Export (GET)
    if (isset($_GET['db_action']) && $_GET['db_action'] === 'export' && isset($_GET['table'])) {
        $dbAdmin->exportCsv($_GET['table'], $_GET);
        exit;
    }
    
    // Handle Get Row (GET)
    if (isset($_GET['db_action']) && $_GET['db_action'] === 'get_row' && isset($_GET['table'])) {
        $dbAdmin->executeComponent(); // This handles get_row internally and exits
        exit;
    }

    // Handle Update Row (POST)
    if (isset($_POST['db_action']) && $_POST['db_action'] === 'update_row' && isset($_POST['table'])) {
        // We need to route this correctly. The current DbAdminComponent::executeComponent handles $_GET['db_action'].
        // Let's hack it slightly by merging POST into GET for the component logic, or refactor.
        // Better: let's invoke a specific method if public, but executeComponent checks Auth and logic.
        // Let's rely on executeComponent handling it, but we need to ensure it sees the action.
        $_GET['db_action'] = 'update_row'; // Force action for component
        $_GET['table'] = $_POST['table'];
        $dbAdmin->executeComponent();
        exit;
    }
}

$app->setTitle('Dashboard - MLP Evening');
$app->addCss('/assets/css/dashboard.css');
$app->addJs('/assets/js/dashboard.js');

$bodyClass = 'dashboard-layout';
$showChatBro = false; 
$showPageHeader = true; // Включаем общий хедер

require_once __DIR__ . '/../src/templates/header.php';
?>

<div class="container">

    <!-- Навигация (Плитки) -->
    <div class="nav-grid">
        <div class="nav-tile active" data-target="#tab-playlist">
            <div class="icon">🌙</div>
            <div class="label">Вечерний плейлист</div>
        </div>
        <div class="nav-tile" data-target="#tab-library">
            <div class="icon">📚</div>
            <div class="label">Библиотека серий</div>
        </div>
        <div class="nav-tile" data-target="#tab-history">
            <div class="icon">📜</div>
            <div class="label">История просмотров</div>
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
        <div class="nav-tile" data-target="#tab-moderation">
            <div class="icon">🛡️</div>
            <div class="label">Модерация</div>
        </div>
        <div class="nav-tile" data-target="#tab-controls">
            <div class="icon">⚙️</div>
            <div class="label">Управление</div>
        </div>
        <div class="nav-tile" data-target="#tab-database">
            <div class="icon">💾</div>
            <div class="label">База Данных</div>
        </div>
    </div>

    <!-- Вкладка 1: Плейлист -->
    <div id="tab-playlist" class="tab-content active">
        <?php $app->includeComponent('AdminPlaylist'); ?>
    </div>

    <!-- Вкладка 2: Библиотека -->
    <div id="tab-library" class="tab-content">
        <?php $app->includeComponent('AdminLibrary'); ?>
    </div>

    <!-- Вкладка 3: История -->
    <div id="tab-history" class="tab-content">
        <?php $app->includeComponent('AdminHistory'); ?>
    </div>

    <!-- Вкладка 3.5: Пользователи -->
    <div id="tab-users" class="tab-content">
        <?php $app->includeComponent('AdminUsers'); ?>
    </div>

    <!-- Вкладка 3.6: Календарь -->
    <div id="tab-events" class="tab-content">
        <?php $app->includeComponent('AdminEvents'); ?>
    </div>

    <!-- Вкладка 4: Стикеры -->
    <div id="tab-stickers" class="tab-content">
        <?php $app->includeComponent('AdminStickers'); ?>
    </div>

    <!-- Вкладка 5: Модерация -->
    <div id="tab-moderation" class="tab-content">
        <?php $app->includeComponent('AdminModeration'); ?>
    </div>

    <!-- Вкладка 4: Управление -->
    <div id="tab-controls" class="tab-content">
        <?php $app->includeComponent('AdminSettings'); ?>
    </div>
    
    <!-- Вкладка 6: База Данных -->
    <div id="tab-database" class="tab-content">
        <?php $app->includeComponent('DbAdmin'); ?>
    </div>

</div>

<?php require_once __DIR__ . '/../src/templates/footer.php'; ?>