<?php
$pageTitle = '404 - Страница не найдена';
$bodyClass = 'error-page';
$extraCss = '<link rel="stylesheet" href="/assets/css/error.css">';
require_once __DIR__ . '/src/templates/header.php';
?>

<div class="error-container">
    <div class="error-content">
        <img src="/assets/img/404.png" alt="Derpy Hooves 404" class="error-image">
        <h1 class="logo-font">Упс! Страница потерялась...</h1>
        <p>Похоже, Дерпи случайно уронила это письмо где-то по дороге.</p>
        <p>Не расстраивайся! Давай лучше посмотрим что-нибудь интересное?</p>
        <a href="/" class="btn-return">Вернуться в библиотеку</a>
    </div>
</div>

<?php require_once __DIR__ . '/src/templates/footer.php'; ?>

