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
    
    <!-- Google Fonts: Philosopher (для заголовков) и Open Sans (для текста) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600&family=Philosopher:ital,wght@0,400;0,700;1,400&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="/assets/css/main.css">
    <link rel="stylesheet" href="/assets/css/chat.css">
    <link rel="stylesheet" href="/assets/css/modal.css">
    <?php if (isset($extraCss)) echo $extraCss; ?>
    
    <?php if (isset($_SESSION['user_id'])): ?>
        <meta name="csrf-token" content="<?= Auth::generateCsrfToken() ?>">
    <?php endif; ?>

    <!-- jQuery нужен везде -->
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/2.2.0/jquery.min.js"></script>
    
    <?php if (isset($extraScripts)) echo $extraScripts; ?>
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