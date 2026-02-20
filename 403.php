<?php
require_once __DIR__ . '/init.php';

$app->setTitle('403 Forbidden - Доступ запрещен');
$app->addCss('/assets/css/error.css');

$bodyClass = 'error-page';

require_once __DIR__ . '/src/templates/header.php';
?>

<div class="error-container">
    <div class="error-content">
        <img src="/assets/img/403.jpg" alt="Pinkie Pie Access Denied" class="error-image">
        <h1 class="logo-font">403: Стой! Кто идет?</h1>
        <p>Прости, но этот раздел только для Аликорнов и верных помощников (Админов).</p>
        <p>Твоей магии пока недостаточно, чтобы пройти сюда. Может, вернемся к просмотру?</p>
        <a href="/" class="btn-return">Вернуться к друзьям</a>
    </div>
</div>

<?php require_once __DIR__ . '/src/templates/footer.php'; ?>
