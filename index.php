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


<!-- Auth Modal -->
<div id="login-modal" class="modal-overlay" style="display: none;">
    <div class="modal-content" style="max-width: 400px;">
        <span class="close-modal">&times;</span>
        
        <div class="auth-tabs" style="display: flex; justify-content: center; gap: 20px; margin-bottom: 20px; border-bottom: 1px solid #eee; padding-bottom: 10px;">
            <a href="#" class="auth-tab-link active" data-target="#login-form-wrapper" style="text-decoration: none; color: #6d2f8e; font-weight: bold; border-bottom: 2px solid #6d2f8e;">Вход</a>
            <a href="#" class="auth-tab-link" data-target="#register-form-wrapper" style="text-decoration: none; color: #999;">Регистрация</a>
        </div>

        <!-- LOGIN -->
        <div id="login-form-wrapper">
            <h3>🔐 Вход в библиотеку</h3>
            <form id="ajax-login-form">
                <div class="form-group">
                    <input type="text" name="username" class="form-input" placeholder="Логин" required>
                </div>
                <div class="form-group">
                    <input type="password" name="password" class="form-input" placeholder="Пароль" required>
                </div>
                <button type="submit" class="btn-primary btn-block">Войти</button>
                <div id="login-error" class="error-msg" style="display:none; color: red; margin-top: 10px;"></div>
            </form>
        </div>

        <!-- REGISTER -->
        <div id="register-form-wrapper" style="display: none;">
            <h3>✨ Новый читатель</h3>
            <form id="ajax-register-form">
                <input type="hidden" name="action" value="register">
                
                <div class="form-group" style="margin-bottom: 10px;">
                    <input type="text" name="login" class="form-input" placeholder="Логин (для входа)*" required minlength="3">
                </div>
                
                <div class="form-group" style="margin-bottom: 10px;">
                    <input type="text" name="nickname" class="form-input" placeholder="Никнейм (для чата)">
                </div>

                <div class="form-group" style="margin-bottom: 10px;">
                    <input type="password" name="password" id="reg_pass" class="form-input" placeholder="Пароль (мин. 6)*" required minlength="6">
                </div>
                
                <div class="form-group" style="margin-bottom: 10px;">
                    <input type="password" name="password_confirm" id="reg_pass_conf" class="form-input" placeholder="Повторите пароль*" required>
                </div>

                <div class="form-group" style="margin-bottom: 15px;">
                    <label style="font-size: 0.85em; color: #666; display: block; margin-bottom: 3px;">Как зовут дракончика-помощника?*</label>
                    <input type="text" name="captcha" class="form-input" placeholder="Ответ..." required>
                </div>

                <button type="submit" class="btn-primary btn-block">Зарегистрироваться</button>
                <div id="register-error" class="error-msg" style="display:none; color: red; margin-top: 10px;"></div>
            </form>
        </div>

    </div>
</div>

<?php require_once __DIR__ . '/src/templates/footer.php'; ?>