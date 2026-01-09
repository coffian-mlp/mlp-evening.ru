<?php
$pageTitle = 'MLP-evening.ru - Поняшный вечерок';
$bodyClass = 'player-layout';
// Переносим стили чата в переменную, если нужно, или оставляем логику в футере
// Variables now set above from DB
// $showChatBro = false; 
// $enableLocalChat = true;

// Подключаем менеджер для получения ссылки из БД
require_once __DIR__ . '/src/EpisodeManager.php';
require_once __DIR__ . '/src/ConfigManager.php';
require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/UserManager.php'; // Добавляем UserManager

Auth::check(); // Init session

    // Получаем данные текущего пользователя для модалки профиля
    $currentUser = null;
    $userOptions = [];
    if (Auth::check()) {
        $userManager = new UserManager();
        $currentUser = $userManager->getUserById($_SESSION['user_id']);
        $userOptions = $userManager->getUserOptions($_SESSION['user_id']);
    }

    // Получаем стикеры для фронтенда
    require_once __DIR__ . '/src/StickerManager.php';
    $stickerManager = new StickerManager();
    
    // 1. Full list for Picker (Grouped by Pack)
    // We'll fetch flat list and group in PHP or JS. PHP is easier to keep JS clean.
    // Let's fetch packs and stickers separately.
    $packs = $stickerManager->getAllPacks();
    $allStickers = $stickerManager->getAllStickers(true); // Flat list
    
    // Group stickers by pack_id
    $stickersByPack = [];
    $stickerMap = []; // For fast lookup in chat
    
    foreach ($allStickers as $s) {
        $stickersByPack[$s['pack_id']][] = $s;
        $stickerMap[$s['code']] = $s['image_url'];
    }
    
    // Combine into a structure for Frontend
    $frontendStickerData = [
        'packs' => $packs,
        'stickers' => $stickersByPack
    ]; 

$config = ConfigManager::getInstance();
// Получаем ссылку, или ставим дефолтную, если в базе пусто
$streamUrl = $config->getOption('stream_url', 'https://goodgame.ru/player?161438#autoplay');
$chatMode = $config->getOption('chat_mode', 'local');

// Telegram Auth Config
$telegramAuthEnabled = (bool)$config->getOption('telegram_auth_enabled', 0);
$telegramBotUsername = $config->getOption('telegram_bot_username', '');

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
            
            <div class="menu" style="display: flex; gap: 15px; align-items: center;">
                <?php if (Auth::check()): ?>
                    <a href="#" onclick="openProfileModal(event)" title="Настройки профиля" style="color: white; text-decoration: none; font-weight: bold;">
                        👤 <?= htmlspecialchars($_SESSION['username']) ?>
                    </a>
                    <?php if (Auth::isAdmin()): ?>
                         <a href="/dashboard.php" title="Админка" style="color: #f1c40f; text-decoration: none;">🔧</a>
                    <?php endif; ?>
                    <form id="logout-form" method="post" action="api.php" style="margin: 0;">
                        <input type="hidden" name="action" value="logout">
                        <input type="hidden" name="csrf_token" value="<?= Auth::generateCsrfToken() ?>">
                        <button type="submit" style="background:none; border:none; color: rgba(255,255,255,0.7); cursor: pointer; padding: 0;">(Выйти)</button>
                    </form>
                <?php else: ?>
                    <a href="#" onclick="openLoginModal(event)" style="color: white; text-decoration: none; font-weight: bold;">Войти / Присоединиться</a>
                <?php endif; ?>
            </div>
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
            <div id="chat-notification-area"></div>
            
            <!-- Confirmation Overlay -->
            <div id="chat-confirmation-overlay" class="chat-overlay" style="display: none;">
                <div class="chat-confirm-box">
                    <p id="chat-confirm-text">Вы уверены?</p>
                    <div class="chat-confirm-buttons">
                        <button id="chat-confirm-yes" class="btn-primary btn-sm">Да</button>
                        <button id="chat-confirm-no" class="btn-danger btn-sm">Нет</button>
                    </div>
                </div>
            </div>

            <!-- Ban/Mute Input Overlay -->
            <div id="chat-input-overlay" class="chat-overlay" style="display: none;">
                <div class="chat-confirm-box" style="width: 300px;">
                    <h4 id="chat-input-title" style="margin-top:0; color:#6d2f8e;">Действие</h4>
                    <p id="chat-input-desc" style="font-size:0.9em; color:#666;"></p>
                    
                    <!-- Mute specific inputs -->
                    <div id="chat-input-mute-opts" style="display:none; margin-bottom:10px;">
                        <select id="chat-mute-time" class="form-input" style="width:100%; margin-bottom:5px;">
                            <option value="15">15 минут</option>
                            <option value="60">1 час</option>
                            <option value="180">3 часа</option>
                            <option value="1440">24 часа</option>
                            <option value="10080">7 дней</option>
                        </select>
                    </div>

                    <!-- Purge specific inputs -->
                    <div id="chat-input-purge-opts" style="display:none; margin-bottom:10px;">
                        <label style="font-size:0.9em; color:#666;">Количество:</label>
                        <input type="number" id="chat-purge-count" class="form-input" value="50" min="1" max="100" style="width:100%;">
                    </div>

                    <input type="text" id="chat-input-reason" class="form-input" placeholder="Причина..." style="width:100%; margin-bottom:15px;">
                    
                    <div class="chat-confirm-buttons">
                        <button id="chat-input-submit" class="btn-primary btn-sm">ОК</button>
                        <button id="chat-input-cancel" class="btn-danger btn-sm">Отмена</button>
                    </div>
                </div>
            </div>

            <div class="chat-top-bar">
                <span class="chat-title">Чат <small id="online-counter" style="font-size: 0.7em; color: #aaa; margin-left: 5px; cursor: help;" title="Кто здесь?">(0)</small></span>
                <div class="chat-settings">
                    <button id="toggle-title-alert" class="icon-btn" title="Моргание вкладки">🔔</button>
                </div>
            </div>
            <div class="chat-messages" id="chat-messages">
                <div class="chat-welcome">Добро пожаловать в Поняшный чат! 🦄<br>Не стесняйся, пиши!</div>
            </div>
            <div class="chat-input-area">
                 <?php if (isset($_SESSION['user_id'])): ?>
                    <div id="quote-preview-area" class="hidden"></div>
                    <!-- Toolbar -->
                    <div class="chat-toolbar">
                        <button type="button" class="chat-format-btn" data-format="bold" title="Жирный (**text**)">B</button>
                        <button type="button" class="chat-format-btn" data-format="italic" title="Курсив (*text*)">I</button>
                        <button type="button" class="chat-format-btn" data-format="strike" title="Зачеркнутый (~~text~~)">S</button>
                        <button type="button" class="chat-format-btn" data-format="quote" title="Цитата (> text)">❞</button>
                        <button type="button" class="chat-format-btn" data-format="code" title="Код (`text`)">&lt;/&gt;</button>
                        <button type="button" class="chat-format-btn" data-format="spoiler" title="Спойлер (||text||)">👁</button>
                        <div class="toolbar-separator"></div>
                        <button type="button" class="chat-format-btn" id="sticker-btn" title="Стикеры">😊</button>
                        <button type="button" class="chat-format-btn" id="chat-upload-btn" title="Загрузить файл (Картинка/Док)">📎</button>
                    </div>
                    <!-- Sticker Picker Container -->
                    <div id="sticker-picker" class="sticker-picker" style="display: none;">
                        <div class="sticker-tabs" id="sticker-tabs"></div>
                        <div class="sticker-grid" id="sticker-grid"></div>
                    </div>
                    <form id="chat-form">
                        <input type="file" id="chat-file-input" hidden>
                        <textarea id="chat-input" placeholder="Напиши что-нибудь..." rows="1"></textarea>
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

<!-- Global Config Scripts (Moved outside chat container to work always) -->
<script>
    window.serverTime = <?= time() ?>;
</script>
<?php if (isset($_SESSION['user_id'])): ?>
    <script>
        // Global User Info for JS
        window.currentUserId = <?= json_encode($_SESSION['user_id']) ?>;
        window.currentUserRole = <?= json_encode($_SESSION['role'] ?? 'user') ?>;
        window.isModerator = <?= json_encode(Auth::isModerator()) ?>;
        window.currentUsername = <?= json_encode($_SESSION['username']) ?>;
        window.currentUserNickname = <?= json_encode($currentUser['nickname'] ?? $_SESSION['username']) ?>;
        window.csrfToken = <?= json_encode(Auth::generateCsrfToken()) ?>;
        window.telegramBotUsername = <?= json_encode($telegramBotUsername) ?>; // Для профиля
        // Inject DB Options
        window.userOptions = <?= json_encode($userOptions) ?>;
        // Inject Stickers
        window.stickerMap = <?= json_encode($stickerMap) ?>;
        window.stickerData = <?= json_encode($frontendStickerData) ?>;
    </script>
<?php endif; ?>


<!-- Auth Modal -->
<div id="login-modal" class="modal-overlay" style="display: none;">
    <div class="modal-content" style="max-width: 400px;">
        <span class="close-modal">&times;</span>
        
        <div class="auth-tabs" style="display: flex; justify-content: center; gap: 20px; margin-bottom: 20px; border-bottom: 1px solid #eee; padding-bottom: 10px;">
            <a href="#" class="auth-tab-link active" data-target="#login-form-wrapper" style="text-decoration: none; color: #6d2f8e; font-weight: bold; border-bottom: 2px solid #6d2f8e;">Зайти</a>
            <a href="#" class="auth-tab-link" data-target="#register-form-wrapper" style="text-decoration: none; color: #999;">Присоединиться</a>
        </div>

        <!-- LOGIN -->
        <div id="login-form-wrapper">
            <h3>🔐 Зайти на сайтик</h3>
            <form id="ajax-login-form">
                <div class="form-group">
                    <input type="text" name="username" class="form-input" placeholder="Логин" required>
                </div>
                <div class="form-group">
                    <input type="password" name="password" class="form-input" placeholder="Пароль" required>
                </div>
                <button type="submit" class="btn-primary btn-block">Зайти</button>
                <div id="login-error" class="error-msg" style="display:none; color: red; margin-top: 10px;"></div>
            </form>
        </div>

        <!-- REGISTER -->
        <div id="register-form-wrapper" style="display: none;">
            <h3>✨ Присоединиться</h3>
            
            <?php if ($telegramAuthEnabled && !empty($telegramBotUsername)): ?>
                <div style="text-align: center; margin-bottom: 20px;">
                    <script async src="https://telegram.org/js/telegram-widget.js?22" 
                            data-telegram-login="<?= htmlspecialchars($telegramBotUsername) ?>" 
                            data-size="large" 
                            data-radius="5" 
                            data-onauth="onTelegramAuth(user)" 
                            data-request-access="write"></script>
                    <div style="font-size: 0.8em; color: #999; margin: 10px 0;">— ИЛИ —</div>
                </div>
            <?php endif; ?>

            <form id="ajax-register-form">
                <input type="hidden" name="action" value="register">
                
                <div class="form-group" style="margin-bottom: 10px;">
                    <input type="text" name="login" class="form-input" placeholder="Твой логин*" required minlength="3">
                </div>
                
                <div class="form-group" style="margin-bottom: 10px;">
                    <input type="text" name="nickname" class="form-input" placeholder="Имя в чате">
                </div>

                <div class="form-group" style="margin-bottom: 10px;">
                    <input type="password" name="password" id="reg_pass" class="form-input" placeholder="Пароль (мин. 6)*" required minlength="6">
                </div>
                
                <div class="form-group" style="margin-bottom: 10px;">
                    <input type="password" name="password_confirm" id="reg_pass_conf" class="form-input" placeholder="Повтори пароль*" required>
                </div>

                <div class="form-group" style="margin-bottom: 15px;">
                    <label style="font-size: 0.85em; color: #666; display: block; margin-bottom: 3px;">Как зовут дракончика?*</label>
                    <input type="text" name="captcha" class="form-input" placeholder="Ответ..." required>
                </div>

                <button type="submit" class="btn-primary btn-block">Присоединиться</button>
                <div id="register-error" class="error-msg" style="display:none; color: red; margin-top: 10px;"></div>
            </form>
        </div>

    </div>
</div>

<!-- Profile Modal -->
<div id="profile-modal" class="modal-overlay" style="display: none;">
    <div class="modal-content" style="max-width: 450px; text-align: left;">
        <span class="close-modal-profile" style="position: absolute; top: 10px; right: 15px; font-size: 28px; cursor: pointer; color: #aaa;">&times;</span>
        
        <h3 style="text-align: center; color: #6d2f8e;">🦄 Твой Профиль</h3>
        
        <?php if ($currentUser): ?>
        <form id="ajax-profile-form">
            <input type="hidden" name="action" value="update_profile">
            <input type="hidden" name="csrf_token" value="<?= Auth::generateCsrfToken() ?>">

            <div class="form-group" style="margin-bottom: 15px;">
                <label class="form-label">Имя в чате</label>
                <input type="text" name="nickname" value="<?= htmlspecialchars($currentUser['nickname']) ?>" class="form-input" required>
            </div>

            <div class="form-group" style="margin-bottom: 15px;">
                <label class="form-label">Цвет имени</label>
                <div class="color-picker-ui">
                    <input type="hidden" name="chat_color" value="<?= htmlspecialchars($currentUser['chat_color'] ?? '#6d2f8e') ?>">
                    <div class="manual-input-wrapper">
                        <span style="font-size: 0.9em; color: #666;">Свой цвет:</span>
                        <input type="text" class="color-manual-input" placeholder="#HEX..." maxlength="7">
                    </div>
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 15px;">
                <label class="form-label">Аватарка</label>
                <input type="file" name="avatar_file" class="form-input" accept="image/jpeg,image/png,image/gif,image/webp" style="margin-bottom: 5px;">
                <div style="text-align: center; font-size: 0.8em; color: #999; margin-bottom: 5px;">— ИЛИ —</div>
                <input type="text" name="avatar_url" value="<?= htmlspecialchars($currentUser['avatar_url'] ?? '') ?>" class="form-input" placeholder="Ссылка (https://...)">
                <small style="color: #777;">Загрузи файл (до 5МБ) или вставь ссылку.</small>
            </div>

            <div class="form-group" style="margin-bottom: 15px;">
                 <label class="form-label">Сменить пароль (если хочешь)</label>
                 <input type="password" name="password" class="form-input" placeholder="Новый пароль">
            </div>

            <div class="form-group" style="margin-bottom: 15px;">
                <label class="form-label">Уведомления</label>
                <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                    <label style="display: flex; align-items: center; cursor: pointer;">
                        <input type="checkbox" id="profile-title-toggle" style="margin-right: 5px;"> Моргание вкладки
                    </label>
                </div>
            </div>

            <!-- Social Accounts Binding -->
            <?php if ($telegramAuthEnabled && !empty($telegramBotUsername)): ?>
            <div class="form-group" style="margin-bottom: 15px; border-top: 1px solid #eee; padding-top: 15px;">
                <label class="form-label">Социальные сети</label>
                
                <div id="profile-socials-list">
                    <!-- Telegram Item -->
                    <div class="social-item telegram-item" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                        <span style="display: flex; align-items: center; gap: 5px;">
                            <img src="https://telegram.org/favicon.ico" width="20"> Telegram
                        </span>
                        
                        <!-- Контейнер для статуса (Привязан/Отвязать) -->
                        <div id="telegram-status-container" style="display: none;"></div>

                        <!-- Контейнер для виджета (Скрываем JS-ом если привязан) -->
                        <div id="telegram-widget-container">
                             <!-- Сюда JS вставит виджет, когда модалка откроется -->
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <button type="submit" class="btn-primary btn-block">Сохранить</button>
            <div id="profile-error" class="error-msg" style="display:none; color: red; margin-top: 10px; text-align: center;"></div>
        </form>
        <?php else: ?>
            <p style="text-align: center;">Сначала нужно войти!</p>
        <?php endif; ?>
    </div>
</div>

<!-- Context Menu (Global) -->
<ul id="chat-context-menu" class="chat-context-menu" style="display: none;">
    <li data-action="reply">💬 Ответить</li>
    <li data-action="quote">❞ Цитата</li>
    <li data-action="edit" style="display:none;">✎ Редактировать</li>
    <li data-action="delete" class="danger" style="display:none;">🗑️ Удалить</li>
    <?php if (Auth::isModerator()): ?>
        <li class="separator mod-only"></li>
        <li data-action="purge" class="danger mod-only">🧹 Purge (50)</li>
        <li data-action="mute" class="warning mod-only">🤐 Мут (15м)</li>
        <li data-action="ban" class="danger mod-only">🔨 Бан (Навсегда)</li>
    <?php endif; ?>
</ul>

<?php require_once __DIR__ . '/src/templates/footer.php'; ?>