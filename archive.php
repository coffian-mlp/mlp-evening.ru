<?php
require_once __DIR__ . '/init.php';
$app->setTitle('Архив вечерков - MLP Evening');
$bodyClass = 'calendar-layout';
$showChatBro = false;
$showPageHeader = true;
require_once __DIR__ . '/src/templates/header.php';
?>
<main class="archive-container"><?php $app->includeComponent('EveningArchive'); ?></main>
<?php require_once __DIR__ . '/src/templates/footer.php'; ?>
