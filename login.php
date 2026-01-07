<?php

require_once __DIR__ . '/src/Auth.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (Auth::login($username, $password)) {
        if (Auth::isAdmin()) {
            header("Location: /dashboard.php");
        } else {
            header("Location: /");
        }
        exit();
    } else {
        $error = 'Упс! Неверное имя или пароль. Попробуй еще раз!';
    }
}

// Если уже залогинен
if (Auth::check()) {
    if (Auth::isAdmin()) {
        header("Location: /dashboard.php");
    } else {
        header("Location: /");
    }
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
                <label for="username" class="form-label">Твое имя (Логин)</label>
                <input type="text" id="username" name="username" class="form-input" placeholder="Пинки Пай" required>
            </div>
            
            <div class="form-group">
                <label for="password" class="form-label">Секретное слово (Пароль)</label>
                <input type="password" id="password" name="password" class="form-input" placeholder="••••••••" required>
            </div>
            
            <button type="submit" class="btn-submit">Войти</button>
        </form>
        
        <div class="back-link-wrapper" style="display: flex; justify-content: space-between; align-items: center;">
            <a href="/" class="back-link">&larr; Вернуться к просмотру</a>
            <a href="/register.php" class="back-link" style="font-weight: bold;">Регистрация</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/src/templates/footer.php'; ?>