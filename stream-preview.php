<?php
require_once __DIR__.'/init.php';
\Domain\Auth::requireAdmin();
header('Cache-Control: no-store');
$app->setTitle('Предпросмотр эфира — MLP Evening');
$bodyClass='stream-preview-page';$showChatBro=false;$showPageHeader=true;
require __DIR__.'/src/templates/header.php';
?>
<main class="self-hosted-preview">
    <h1>Предпросмотр собственного эфира</h1>
    <p>Этот плеер показывает поток медиасервера. Выбранный для зрителей источник меняется отдельно в настройках.</p>
    <?php $app->includeComponent('VideoPlayer','default',['self_hosted_preview'=>true]); ?>
    <p><a href="/dashboard/#tab-settings">Вернуться к настройкам плеера</a></p>
</main>
<?php require __DIR__.'/src/templates/footer.php'; ?>
