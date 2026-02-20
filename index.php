<?php
require_once __DIR__ . '/init.php';

$app->setTitle('MLP-evening.ru - Поняшный вечерок');
$bodyClass = 'player-layout';

require_once __DIR__ . '/src/templates/header.php';
?>

<div class="player-container">
    <div class="video-container">
        <div class="header">
            <a title="MLP-evening.ru - Поняшный вечерок" href="/">
                <img src="/assets/img/logo.png" class="logo" alt="MLP Evening Logo" />
            </a>
        </div>
        <?php $app->includeComponent('VideoPlayer'); ?>
    </div>
    
    <?php
    // Подключаем компонент Чата
    $app->includeComponent('Chat', 'embedded', [
        'HEIGHT' => '100%',
        'mode' => 'local'
    ]);
    ?>
</div>

<?php 
// Подключаем модальные окна (Auth & Profile)
$app->includeComponent('Auth');
$app->includeComponent('Profile');
?>

<?php require_once __DIR__ . '/src/templates/footer.php'; ?>
