<?php
$pageTitle = 'MLP-evening.ru - Поняшный вечерок';
$bodyClass = 'player-layout';
// Переносим стили чата в переменную, если нужно, или оставляем логику в футере
$showChatBro = true; 

// Подключаем менеджер для получения ссылки из БД
require_once __DIR__ . '/src/EpisodeManager.php';
$manager = new EpisodeManager();
// Получаем ссылку, или ставим дефолтную, если в базе пусто
$streamUrl = $manager->getOption('stream_url', 'https://goodgame.ru/player?161438#autoplay');
  
require_once __DIR__ . '/src/templates/header.php';
?>

<div class="player-container">
    <div class="video-container">
        <div class="header">
            <a title="MLP-evening.ru - Поняшный вечерок" href="/">
                <img src="/assets/img/logo.png" class="logo" alt="MLP Evening Logo" />
            </a>
            <?php
            // TODO: Реализовать меню навигации в будущем
            /*
            <div class="menu">
                <a href="#">Меню 1</a>
                <a href="#">Меню 2</a>
                <a href="#">Меню 3</a>
            </div>
            */
            ?>
        </div>
        <div class="video-content">

        <iframe 
            src="<?= htmlspecialchars($streamUrl) ?>" 
            allowfullscreen 
            allow="autoplay">
        </iframe>
        </div>
    </div>
    <div class="chat-container" id="chat">
    </div>
</div>

<?php require_once __DIR__ . '/src/templates/footer.php'; ?>