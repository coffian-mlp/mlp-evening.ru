<?php

require_once __DIR__ . '/init.php';

$app->setTitle('Календарь Событий - MLP Evening');

$bodyClass = 'calendar-layout';
$showChatBro = false; 
$showPageHeader = true;

// Подключаем стили дашборда для шапки, так как они делают ее красивой и размытой
$app->addCss('/assets/css/dashboard.css');

require_once __DIR__ . '/src/templates/header.php';
?>

<div class="container" style="max-width: 1200px; margin: 0 auto; padding: 20px;">
    <?php $app->includeComponent('Calendar'); ?>
</div>

<?php require_once __DIR__ . '/src/templates/footer.php'; ?>
