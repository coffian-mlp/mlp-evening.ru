<?php

require_once __DIR__ . '/src/Auth.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (Auth::login($username, $password)) {
        header("Location: /dashboard.php");
        exit();
    } else {
        $error = 'Неверный логин или пароль!';
    }
}

// Если уже залогинен - сразу в дашборд
if (Auth::check()) {
    header("Location: /dashboard.php");
    exit();
}

$pageTitle = 'Вход в систему - MLP Evening';
$bodyClass = 'dashboard-layout';
$extraCss = '<link rel="stylesheet" href="/assets/css/login.css">';

require_once __DIR__ . '/src/templates/header.php';
?>

<div class="login-wrapper">
    <div class="login-box">
        <h2 class="login-title">🔐 Вход в библиотеку</h2>
        
        <?php if ($error): ?>
            <div class="error-alert">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="post">
            <div class="form-group">
                <label for="username" class="form-label">Логин</label>
                <input type="text" id="username" name="username" class="form-input" placeholder="Введите логин..." required>
            </div>
            
            <div class="form-group">
                <label for="password" class="form-label">Пароль</label>
                <input type="password" id="password" name="password" class="form-input" placeholder="Введите пароль..." required>
            </div>
            
            <button type="submit" class="btn-submit">Войти</button>
        </form>
        
        <div class="back-link-wrapper">
            <a href="/" class="back-link">&larr; Вернуться к просмотру</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/src/templates/footer.php'; ?>