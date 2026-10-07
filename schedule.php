<?php

require_once __DIR__ . '/init.php';

$app->setTitle('Расписание - MLP Evening');

$bodyClass = 'calendar-layout';
$showChatBro = false; 
$showPageHeader = true;

require_once __DIR__ . '/src/templates/header.php';
?>

<main class="schedule-container">
    <?php $app->includeComponent('Calendar'); ?>
</main>

<?php require_once __DIR__ . '/src/templates/footer.php'; ?>
