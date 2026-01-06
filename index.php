<?php
$pageTitle = 'MLP-evening.ru - Поняшный вечерок';
$bodyClass = 'player-layout';
// Переносим стили чата в переменную, если нужно, или оставляем логику в футере
// Variables now set above from DB
// $showChatBro = false; 
// $enableLocalChat = true;

// Подключаем менеджер для получения ссылки из БД
require_once __DIR__ . '/src/EpisodeManager.php';
require_once __DIR__ . '/src/Auth.php';
Auth::check(); // Init session

$manager = new EpisodeManager();
// Получаем ссылку, или ставим дефолтную, если в базе пусто
$streamUrl = $manager->getOption('stream_url', 'https://goodgame.ru/player?161438#autoplay');
$chatMode = $manager->getOption('chat_mode', 'local');

// Конфигурируем флаги для шаблонов
$enableLocalChat = ($chatMode === 'local');
$showChatBro = ($chatMode === 'chatbro');

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
        <?php if ($enableLocalChat): ?>
            <!-- Local Chat UI -->
            <div class="chat-messages" id="chat-messages">
                <div class="chat-welcome">Добро пожаловать в Поняшный чат! 🦄<br>Не стесняйся, пиши!</div>
            </div>
            <div class="chat-input-area">
                 <?php if (isset($_SESSION['user_id'])): ?>
                    <form id="chat-form">
                        <input type="text" id="chat-input" placeholder="Напиши что-нибудь..." autocomplete="off">
                        <button type="submit">Отправить</button>
                    </form>
                 <?php else: ?>
                    <div class="chat-login-prompt">
                        <a href="#" id="login-link">Войди</a>, чтобы общаться.
                    </div>
                 <?php endif; ?>
            </div>
        <?php elseif ($showChatBro): ?>
            <!-- ChatBro Container (будет заполнен скриптом) -->
            <div id="chatbro-placeholder" style="padding: 20px; text-align: center; color: #666;">
                Загрузка ChatBro...
            </div>
        <?php else: ?>
            <div class="chat-disabled-placeholder" style="display: flex; justify-content: center; align-items: center; height: 100%; color: #888;">
                Чат отключен
            </div>
        <?php endif; ?>
    </div>
</div>


<!-- Login Modal -->
<div id="login-modal" class="modal-overlay" style="display: none;">
    <div class="modal-content">
        <span class="close-modal">&times;</span>
        <h3>🔐 Вход в библиотеку</h3>
        <form id="ajax-login-form">
            <div class="form-group">
                <input type="text" name="username" class="form-input" placeholder="Твое имя (Логин)" required>
            </div>
            <div class="form-group">
                <input type="password" name="password" class="form-input" placeholder="Секретное слово (Пароль)" required>
            </div>
            <button type="submit" class="btn-submit">Войти</button>
            <div id="login-error" class="error-msg" style="display:none; color: red; margin-top: 10px;"></div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/src/templates/footer.php'; ?>