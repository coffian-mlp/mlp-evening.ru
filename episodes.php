<?php
require_once __DIR__ . '/init.php';
$app->setTitle('Эпизоды — MLP Evening');
$bodyClass = 'episode-catalogue-page';
$showChatBro = false;
$showPageHeader = true;
require_once __DIR__ . '/src/templates/header.php';
?>
<main class="episode-catalogue-container">
    <?php $app->includeComponent('EpisodeCatalogue'); ?>
</main>
<?php
if (!\Domain\Auth::userId()) $app->includeComponent('Auth');
require_once __DIR__ . '/src/templates/footer.php';
