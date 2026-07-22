<?php
use Domain\Auth;
use Domain\UserManager;
// src/templates/header.php
global $app;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $app->getTitle() ?></title>
    <link rel="icon" href="/favicon.png">
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#2b1f43">
    <!-- iOS: standalone-режим и иконка на домашний экран (MLP-261) -->
    <link rel="apple-touch-icon" href="/assets/img/icon-192.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="MLP Evening">
    
    <!-- SEO & Social Media (Open Graph) -->
    <meta name="description" content="MLP-Evening - Поняшный вечерок. Стримы My Little Pony и ламповое общение.">
    <meta name="keywords" content="mlp, my little pony, stream, стрим, пони, поняшный вечерок">
    
    <meta property="og:title" content="<?= $app->getTitle() ?>">
    <meta property="og:description" content="Заходи на огонек! Стримы любимых серий My Little Pony, ламповый чат и магия дружбы.">
    <meta property="og:image" content="https://mlp-evening.ru/assets/img/logo.png">
    <meta property="og:url" content="https://mlp-evening.ru">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="ru_RU">
    
    <!-- Fonts: Local Philosopher & Open Sans -->
    <link rel="stylesheet" href="/assets/css/fonts.css?v=<?= file_exists($_SERVER['DOCUMENT_ROOT'] . '/assets/css/fonts.css') ? filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/fonts.css') : time() ?>">

    <link rel="stylesheet" href="/assets/css/main.css?v=<?= file_exists($_SERVER['DOCUMENT_ROOT'] . '/assets/css/main.css') ? filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/main.css') : time() ?>">
    
    <!-- Application Assets (Component CSS) -->
    <?php $app->showHead(); ?>
    
    <?php if (isset($_SESSION['user_id'])): ?>
        <meta name="csrf-token" content="<?= Auth::generateCsrfToken() ?>">
    <?php endif; ?>

    <!-- jQuery (Local) -->
    <script src="/assets/js/jquery.min.js"></script>
    
    <script>
        // Pass PHP session data to JS
        window.currentUserId = <?= isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'null' ?>;
        window.currentUserRole = "<?= isset($_SESSION['role']) ? $_SESSION['role'] : '' ?>";
        // CSRF-токен глобально (L4/MLP-229): нужен формам дашборда (события и т.п.)
        window.csrfToken = <?= isset($_SESSION['user_id']) ? json_encode(Auth::generateCsrfToken()) : 'null' ?>;
        // Pass server time (seconds) to calculate clock skew
        window.serverTime = <?= time() ?>;
        
        <?php 
        // Pass User Options if logged in
        if (isset($_SESSION['user_id'])) {
            $uMgr = new UserManager();
            $uOpts = $uMgr->getUserOptions($_SESSION['user_id']);
            echo "window.userOptions = " . json_encode($uOpts) . ";\n";
            echo "window.currentUserFont = " . json_encode($uOpts['font_preference'] ?? 'open_sans') . ";\n";
            echo "window.currentUserFontScale = " . json_encode($uOpts['font_scale'] ?? 100) . ";\n";
        }
        ?>
    </script>
</head>
<body class="<?= $bodyClass ?? '' ?>">

<?php if (isset($showPageHeader) && $showPageHeader): ?>
    <header class="main-header">
        <div class="header-content">
            <div class="logo-area">
                <a href="/" title="MLP-evening.ru - Поняшный вечерок">
                    <img src="/assets/img/logo.png" class="logo" alt="MLP Evening Logo" />
                </a>
            </div>

            <?php $app->includeComponent('SiteMenu', 'header'); // MLP-259 ?>

            <?php if (isset($_SESSION['user_id'])): ?>
                <div class="user-area">
                    <span class="username">Привет, <?= htmlspecialchars($_SESSION['username']) ?>! 👋</span>
                    <form method="post" action="/api.php" style="margin: 0;">
                        <input type="hidden" name="action" value="logout">
                        <button type="submit" class="btn-logout">🚪 Выйти</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </header>
<?php endif; ?>
