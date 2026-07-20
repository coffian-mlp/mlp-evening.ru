<?php
use Domain\Auth;

require_once __DIR__ . '/init.php';

/**
 * Страница входа (MLP-256). Без регистрации — только логин/соцвход/восстановление.
 * Гейт до вывода: залогиненный админ → ?redirect= (только локальный путь) или
 * /dashboard/; залогиненный не-админ → на главную; гость видит форму.
 * После сабмита main.js перезагружает страницу — этот же гейт доводит редирект.
 */
$redirect = Auth::sanitizeLocalRedirect($_GET['redirect'] ?? null);

if (Auth::check()) {
    header('Location: ' . (Auth::isAdmin() ? ($redirect ?: '/dashboard/') : '/'));
    exit();
}

$app->setTitle('Вход - MLP Evening');
$app->addCss('/assets/css/login.css');

$bodyClass = 'login-page';
$showPageHeader = true;

require_once __DIR__ . '/src/templates/header.php';
?>

<div class="login-page-wrap">
    <?php $app->includeComponent('Auth', 'page'); ?>
</div>

<?php require_once __DIR__ . '/src/templates/footer.php'; ?>
