<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? 'MLP-evening.ru - Поняшный вечерок' ?></title>
    <link rel="icon" href="/favicon.png">
    
    <!-- SEO & Social Media (Open Graph) -->
    <meta name="description" content="MLP-Evening - Поняшный вечерок. Стримы My Little Pony и ламповое общение.">
    <meta name="keywords" content="mlp, my little pony, stream, стрим, пони, поняшный вечерок">
    
    <meta property="og:title" content="<?= $pageTitle ?? 'MLP-evening.ru - Поняшный вечерок' ?>">
    <meta property="og:description" content="Заходи на огонек! Стримы любимых серий My Little Pony, ламповый чат и магия дружбы.">
    <meta property="og:image" content="https://mlp-evening.ru/assets/img/logo.png">
    <meta property="og:url" content="https://mlp-evening.ru">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="ru_RU">
    
    <!-- Fonts: Local Philosopher & Open Sans -->
    <link rel="stylesheet" href="/assets/css/fonts.css">

    <link rel="stylesheet" href="/assets/css/main.css">
    <link rel="stylesheet" href="/assets/css/quotes.css">
    <link rel="stylesheet" href="/assets/css/markdown.css">
    <link rel="stylesheet" href="/assets/css/dragdrop.css">
    <link rel="stylesheet" href="/assets/css/chat-media.css">
    <link rel="stylesheet" href="/assets/css/context-menu.css">
    <?php if (isset($extraCss)) echo $extraCss; ?>
    
    <?php if (isset($_SESSION['user_id'])): ?>
        <meta name="csrf-token" content="<?= Auth::generateCsrfToken() ?>">
    <?php endif; ?>

    <!-- jQuery (Local) -->
    <script src="/assets/js/jquery.min.js"></script>
    
    <script>
        // Pass PHP session data to JS
        window.currentUserId = <?= isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'null' ?>;
        window.currentUserRole = "<?= isset($_SESSION['role']) ? $_SESSION['role'] : '' ?>";
        // Pass server time (seconds) to calculate clock skew
        window.serverTime = <?= time() ?>;
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
            
            <?php if (isset($_SESSION['user_id'])): ?>
                <div class="user-area">
                    <span class="username">Привет, <?= htmlspecialchars($_SESSION['username']) ?>! 👋</span>
                    <form method="post" action="api.php" style="margin: 0;">
                        <input type="hidden" name="action" value="logout">
                        <button type="submit" class="btn-logout">🚪 Выйти</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </header>
<?php endif; ?>