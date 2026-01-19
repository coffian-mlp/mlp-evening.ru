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

    // --- Chat Driver Config (Centrifugo vs SSE) ---
    $appConfig = require __DIR__ . '/config.php';
    $chatConfig = $appConfig['chat'] ?? [];
    $chatDriver = $chatConfig['driver'] ?? 'sse'; 

    $centrifugoToken = '';
    $centrifugoUrl = '/connection/websocket'; // Relative path via Nginx proxy

    if ($chatDriver === 'centrifugo') {
        require_once __DIR__ . '/src/CentrifugoService.php';
        $centrifugoService = new CentrifugoService();
        
        // Subject: User ID or empty string for anonymous
        // For guests, we use session_id to identify them uniquely per session
        $sub = Auth::check() ? (string)$_SESSION['user_id'] : "guest_" . substr(session_id(), 0, 10);
        
        // Token valid for 24 hours
        $centrifugoToken = $centrifugoService->generateToken($sub, time() + 86400);
    }

$config = ConfigManager::getInstance();
// Получаем ссылку, или ставим дефолтную, если в базе пусто
$streamUrl = $config->getOption('stream_url', 'https://goodgame.ru/player?161438#autoplay');
$chatMode = $config->getOption('chat_mode', 'local');

// Telegram Auth Config
$telegramAuthEnabled = (bool)$config->getOption('telegram_auth_enabled', 0);
$telegramBotUsername = $config->getOption('telegram_bot_username', '');

// Конфигурируем флаги для шаблонов
$enableLocalChat = ($chatMode === 'local');

require_once __DIR__ . '/src/templates/header.php';
?>

<div class="player-container">
    <div class="video-container">
        <div class="header">
            <a title="MLP-evening.ru - Поняшный вечерок" href="/">
                <img src="/assets/img/logo.png" class="logo" alt="MLP Evening Logo" />
            </a>
            <!-- Меню перенесено в чат -->
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
                <!-- User Menu in Chat Header -->
                <div class="chat-user-menu">
                    <?php if (Auth::check()): ?>
                        <div class="user-controls">
                            <a href="#" onclick="openProfileModal(event)" class="profile-link" title="Настройки профиля">
                                <span class="avatar-mini">
                                    <img src="<?= htmlspecialchars($currentUser['avatar_url'] ?: '/assets/img/default-avatar.png') ?>" alt="">
                                </span>
                                <span class="username" style="color: <?= htmlspecialchars($currentUser['chat_color'] ?? '#ce93d8') ?>">
                                    <?= htmlspecialchars($_SESSION['username']) ?>
                                </span>
                            </a>
                        </div>
                    <?php else: ?>
                        <a href="#" onclick="openLoginModal(event)" class="login-btn-chat">Войти</a>
                    <?php endif; ?>
                </div>

                <div class="chat-settings">
                    <span id="online-counter" class="online-badge" title="Онлайн">(0)</span>
                    <button id="toggle-title-alert" class="icon-btn" title="Моргание вкладки">🔔</button>
                </div>
            </div>
            <div class="chat-messages" id="chat-messages">
                <div class="chat-welcome">Добро пожаловать в Поняшный чат! 🦄<br>Не стесняйся, пиши!</div>
            </div>
            <!-- Compact Mode Trigger (Mobile) -->
            <?php if (isset($_SESSION['user_id'])): ?>
                <button id="chat-mobile-fab" class="chat-mobile-fab" title="Написать">✎</button>
            <?php endif; ?>

            <!-- Compact Input Modal -->
            <div id="chat-mobile-input-overlay" class="chat-overlay" style="display: none; align-items: flex-end;">
                <div class="chat-mobile-input-box">
                    <div class="chat-mobile-header">
                        <span>Сообщение</span>
                        <button id="chat-mobile-close" class="chat-mobile-close">&times;</button>
                    </div>
                    <form id="chat-mobile-form">
                        <textarea id="chat-mobile-input" placeholder="Напиши что-нибудь..." rows="3"></textarea>
                        <div class="chat-mobile-actions" style="display: flex; align-items: center;">
                            <button type="button" id="mobile-sticker-btn" class="chat-format-btn" style="margin-right: 5px; font-size: 20px;">😊</button>
                            <button type="button" id="mobile-upload-btn" class="chat-format-btn" style="margin-right: auto; font-size: 20px;">📎</button>
                            <button type="submit" class="btn-primary" style="padding: 8px 20px;">➤</button>
                        </div>
                    </form>
                </div>
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
                <div class="sticker-header" style="display: flex; justify-content: flex-end; border-bottom: 1px solid rgba(255,255,255,0.1);">
                     <button type="button" class="sticker-close-btn">&times;</button>
                </div>
                <div class="sticker-tabs" id="sticker-tabs"></div>
                <div class="sticker-grid" id="sticker-grid"></div>
            </div>

            <form id="chat-form">
                        <input type="file" id="chat-file-input" hidden>
                        <textarea id="chat-input" placeholder="Напиши что-нибудь..." rows="1"></textarea>
                        <button type="submit">➤</button>
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
        
        <!-- 1. LOGIN SCREEN -->
        <div id="login-form-wrapper">
            <h3 class="modal-title">🔐 Вход</h3>
            <form id="ajax-login-form">
                <div class="form-group">
                    <input type="text" name="username" class="form-input" placeholder="Логин" required>
                </div>
            <div class="form-group">
                <div class="password-wrapper">
                    <input type="password" name="password" class="form-input" placeholder="Пароль" required>
                    <button type="button" class="password-toggle-btn">👁️</button>
                </div>
            </div>
                <div style="text-align: right; margin-bottom: 10px;">
                    <a href="#" onclick="showForgotForm(event)" class="forgot-link">Забыли пароль?</a>
                </div>
                <button type="submit" class="btn-primary btn-block">Войти</button>
                <div id="login-error" class="error-msg" style="display:none; color: #ff5252; margin-top: 10px;"></div>
            </form>

            <div class="auth-separator">
                <div class="auth-separator-text">— или —</div>
                
                <?php if ($telegramAuthEnabled && !empty($telegramBotUsername)): ?>
                    <button type="button" class="btn btn-outline-primary btn-block" onclick="showSocialAuth()">
                        🌐 Войти через соцсети
                    </button>
                <?php endif; ?>
                
                <a href="#" onclick="showRegisterForm(event)" class="auth-switch-link">
                    Нет аккаунта? Присоединиться
                </a>
            </div>
        </div>

        <!-- 2. SOCIAL AUTH SCREEN -->
        <div id="social-auth-wrapper" style="display: none;">
            <h3 class="modal-title">🌐 Быстрый вход</h3>
            <p class="modal-desc">
                Используй свой аккаунт для входа.<br>Если ты новенький, мы создадим профиль автоматически!
            </p>
            
            <div class="social-auth-buttons">
                <?php if ($telegramAuthEnabled && !empty($telegramBotUsername)): ?>
                    <div style="text-align: center;">
                        <script async src="https://telegram.org/js/telegram-widget.js?22" 
                                data-telegram-login="<?= htmlspecialchars($telegramBotUsername) ?>" 
                                data-size="large" 
                                data-radius="5" 
                                data-onauth="onTelegramAuth(user)" 
                                data-request-access="write"></script>
                    </div>
                <?php endif; ?>
                <!-- Место для Discord/VK -->
            </div>

            <div class="auth-separator">
                <a href="#" onclick="showLoginForm(event)" class="auth-switch-link secondary">← Вернуться к логину</a>
            </div>
        </div>

        <!-- 3. REGISTER SCREEN -->
        <div id="register-form-wrapper" style="display: none;">
            <h3 class="modal-title">✨ Присоединиться</h3>
            
            <form id="ajax-register-form">
                <input type="hidden" name="action" value="register">
                
                <div class="form-group" style="margin-bottom: 10px;">
                    <input type="text" name="login" class="form-input" placeholder="Твой логин*" required minlength="3">
                </div>
                
                <div class="form-group" style="margin-bottom: 10px;">
                    <input type="email" name="email" class="form-input" placeholder="Email (для восстановления пароля)">
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

                <button type="button" class="btn-primary btn-block" onclick="startCaptchaRegistration()">Далее →</button>
                <div id="register-error" class="error-msg" style="display:none; color: #ff5252; margin-top: 10px;"></div>
            </form>

            <div style="margin-top: 15px; text-align: center;">
                <a href="#" onclick="showLoginForm(event)" class="auth-switch-link">Уже есть аккаунт? Войти</a>
            </div>
        </div>

        <!-- 4. CAPTCHA SCREEN -->
        <div id="captcha-form-wrapper" style="display: none;">
            <h3 class="modal-title">🦄 Испытание Гармонии</h3>
            <p id="captcha-question-text" class="modal-subtitle">
                Загрузка вопроса...
            </p>
            
            <div id="captcha-image-container" style="text-align: center; margin-bottom: 20px; display: none;">
                <img id="captcha-image" src="" alt="Mystery Pony" style="max-height: 150px; border-radius: 10px; box-shadow: 0 4px 8px rgba(0,0,0,0.3);">
            </div>

            <div id="captcha-options-container" style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 20px;">
                <!-- Options will be injected here -->
            </div>

            <div id="captcha-error" class="error-msg" style="display:none; color: #ff5252; margin-top: 10px; text-align: center;"></div>
        </div>

        <!-- 5. FORGOT PASSWORD SCREEN -->
        <div id="forgot-form-wrapper" style="display: none;">
            <h3 class="modal-title">🆘 Восстановление</h3>
            <p class="modal-desc">
                Введи Email, который ты указывал при регистрации. Мы пришлем ссылку для сброса пароля.
            </p>
            
            <form id="ajax-forgot-form">
                <input type="hidden" name="action" value="forgot_password">
                <div class="form-group" style="margin-bottom: 15px;">
                    <input type="email" name="email" class="form-input" placeholder="Твой Email" required>
                </div>
                <button type="submit" class="btn-primary btn-block">Отправить письмо</button>
                <div id="forgot-msg" class="error-msg" style="display:none; margin-top: 10px; text-align: center;"></div>
            </form>

            <div class="auth-separator">
                <a href="#" onclick="showLoginForm(event)" class="auth-switch-link secondary">← Вернуться к логину</a>
            </div>
        </div>

    </div>
</div>

<!-- Profile Modal -->
<div id="profile-modal" class="modal-overlay" style="display: none;">
    <div class="modal-content" style="max-width: 450px; text-align: left;">
        <span class="close-modal-profile" style="position: absolute; top: 10px; right: 15px; font-size: 28px; cursor: pointer; color: #aaa;">&times;</span>
        
        <h3 style="text-align: center; color: #6d2f8e; margin-bottom: 15px;">🦄 Твой Профиль</h3>
        
        <?php if ($currentUser): ?>
        
        <!-- Profile Tabs Navigation -->
        <div class="profile-tabs">
            <button type="button" class="profile-tab-btn active" onclick="switchProfileTab('visual')">🎨 Внешность</button>
            <button type="button" class="profile-tab-btn" onclick="switchProfileTab('system')">⚙️ Настройки</button>
        </div>

        <form id="ajax-profile-form">
            <input type="hidden" name="action" value="update_profile">
            <input type="hidden" name="csrf_token" value="<?= Auth::generateCsrfToken() ?>">

            <!-- TAB 1: VISUAL (Внешность) -->
            <div id="tab-visual" class="profile-tab-content active">
                <div class="form-group" style="margin-bottom: 15px;">
                    <label class="form-label">Имя в чате</label>
                    <input type="text" name="nickname" value="<?= htmlspecialchars($currentUser['nickname']) ?>" class="form-input" required>
                </div>

                <div class="form-group" style="margin-bottom: 15px;">
                    <label class="form-label">Цвет имени</label>
                    <div class="color-picker-ui">
                        <input type="hidden" name="chat_color" value="<?= htmlspecialchars($currentUser['chat_color'] ?? '#6d2f8e') ?>">
                        <div class="manual-input-wrapper">
                            <span style="font-size: 0.9em; color: #666;">HEX:</span>
                            <input type="text" class="color-manual-input" placeholder="#..." maxlength="7">
                        </div>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 15px;">
                    <label class="form-label">Аватарка</label>
                    <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 10px;">
                        <img src="<?= htmlspecialchars($currentUser['avatar_url'] ?: '/assets/img/default-avatar.png') ?>" style="width: 50px; height: 50px; border-radius: 50%; object-fit: cover; border: 2px solid #ddd;" id="profile-avatar-preview">
                        <div style="flex: 1;">
                            <input type="file" name="avatar_file" class="form-input" accept="image/*" style="font-size: 0.9em;">
                        </div>
                    </div>
                    <input type="text" name="avatar_url" value="<?= htmlspecialchars($currentUser['avatar_url'] ?? '') ?>" class="form-input" placeholder="Или ссылка на картинку..." style="font-size: 0.9em;">
                </div>
            </div>

            <!-- TAB 2: SYSTEM (Система) -->
            <div id="tab-system" class="profile-tab-content" style="display: none;">
                <div class="form-group" style="margin-bottom: 15px;">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($currentUser['email'] ?? '') ?>" class="form-input" placeholder="mail@example.com">
                    <small style="color: #777; display: block; margin-top: 3px;">Для восстановления доступа.</small>
                </div>

                <div class="form-group" style="margin-bottom: 15px;">
                     <label class="form-label">Сменить пароль</label>
                     <div class="password-wrapper">
                         <input type="password" name="password" class="form-input" placeholder="Новый пароль (если нужно)">
                         <button type="button" class="password-toggle-btn">👁️</button>
                     </div>
                </div>

                <div class="form-group" style="margin-bottom: 15px;">
                    <label class="form-label">Уведомления</label>
                    <label style="display: flex; align-items: center; cursor: pointer;">
                        <input type="checkbox" id="profile-title-toggle" style="margin-right: 8px;"> 
                        Моргание вкладки при упоминании
                    </label>
                </div>

                <!-- Social Accounts Binding -->
                <?php if ($telegramAuthEnabled && !empty($telegramBotUsername)): ?>
                <div class="form-group" style="margin-bottom: 15px; border-top: 1px solid #eee; padding-top: 15px;">
                    <label class="form-label">Привязка соцсетей</label>
                    <div id="profile-socials-list">
                        <div class="social-item telegram-item" style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="display: flex; align-items: center; gap: 5px; font-weight: 500;">
                                <img src="https://telegram.org/favicon.ico" width="16"> Telegram
                            </span>
                            <div id="telegram-status-container"></div>
                            <div id="telegram-widget-container"></div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <button type="submit" class="btn-primary btn-block" style="margin-top: 20px;">Сохранить изменения</button>
            <div id="profile-error" class="error-msg" style="display:none; color: red; margin-top: 10px; text-align: center;"></div>
        </form>

        <!-- Profile Actions Footer -->
        <div class="profile-actions-footer">
            <form id="logout-form" method="post" action="api.php" style="margin: 0;">
                <input type="hidden" name="action" value="logout">
                <input type="hidden" name="csrf_token" value="<?= Auth::generateCsrfToken() ?>">
                <button type="submit" class="btn btn-outline-danger">
                    🚪 Выйти
                </button>
            </form>
            
            <?php if (Auth::isAdmin()): ?>
                 <a href="/dashboard.php" class="btn btn-outline-warning">
                    🔧 Админка
                 </a>
            <?php endif; ?>
        </div>
        
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

<!-- Sticker Zoom Overlay (Moved to root for Z-Index safety) -->
<div id="sticker-zoom-preview" style="display: none;">
    <img src="" alt="Sticker Preview">
</div>

<?php require_once __DIR__ . '/src/templates/footer.php'; ?>