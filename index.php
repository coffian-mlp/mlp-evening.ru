<?php
require_once __DIR__ . '/init.php';

$app->setTitle('MLP-evening.ru - Поняшный вечерок');
$bodyClass = 'player-layout';

// Получаем ссылку на стрим (пока старым способом, через конфиг, который уже подключен в init.php)
$config = ConfigManager::getInstance();
$streamUrl = $config->getOption('stream_url', 'https://goodgame.ru/player?161438#autoplay');

// Конфиг для Auth Modal (пока оставим его тут или вынесем в компонент Auth позже)
$telegramAuthEnabled = (bool)$config->getOption('telegram_auth_enabled', 0);
$telegramBotUsername = $config->getOption('telegram_bot_username', '');

require_once __DIR__ . '/src/templates/header.php';
?>

<div class="player-container">
    <div class="video-container">
        <div class="header">
            <a title="MLP-evening.ru - Поняшный вечерок" href="/">
                <img src="/assets/img/logo.png" class="logo" alt="MLP Evening Logo" />
            </a>
        </div>
        <div class="video-content">
            <iframe 
                src="<?= htmlspecialchars($streamUrl) ?>" 
                allowfullscreen 
                allow="autoplay">
            </iframe>
        </div>
    </div>
    
    <?php
    // Подключаем компонент Чата
    $app->includeComponent('Chat', 'embedded', [
        'HEIGHT' => '100%',
        'mode' => 'local'
    ]);
    ?>
</div>

<!-- Auth Modal (Legacy code, pending refactoring to Auth Component) -->
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

<!-- Profile Modal (Legacy code) -->
<div id="profile-modal" class="modal-overlay" style="display: none;">
    <div class="modal-content" style="max-width: 450px; text-align: left;">
        <span class="close-modal-profile" style="position: absolute; top: 10px; right: 15px; font-size: 28px; cursor: pointer; color: #aaa;">&times;</span>
        
        <h3 style="text-align: center; color: #6d2f8e; margin-bottom: 15px;">🦄 Твой Профиль</h3>
        
        <?php if (Auth::check()): 
            // Получаем данные юзера заново для модалки, если нужно, или используем сессию
            // Лучше переделать на AJAX загрузку профиля, но пока оставим как есть
            $userManager = new UserManager();
            $currentUser = $userManager->getUserById($_SESSION['user_id']);
            $userOptions = $userManager->getUserOptions($_SESSION['user_id']);
        ?>
        
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
                    <label class="form-label">Шрифт интерфейса</label>
                    <select name="font_preference" class="form-input">
                        <option value="open_sans" <?= ($userOptions['font_preference'] ?? '') === 'open_sans' ? 'selected' : '' ?>>Open Sans (Стандартный)</option>
                        <option value="fira" <?= ($userOptions['font_preference'] ?? '') === 'fira' ? 'selected' : '' ?>>Fira Sans (Четкий)</option>
                        <option value="pt" <?= ($userOptions['font_preference'] ?? '') === 'pt' ? 'selected' : '' ?>>PT Sans (Строгий)</option>
                        <option value="rubik" <?= ($userOptions['font_preference'] ?? '') === 'rubik' ? 'selected' : '' ?>>Rubik (Мягкий)</option>
                        <option value="inter" <?= ($userOptions['font_preference'] ?? '') === 'inter' ? 'selected' : '' ?>>Inter (Современный)</option>
                    </select>
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

<?php require_once __DIR__ . '/src/templates/footer.php'; ?>
