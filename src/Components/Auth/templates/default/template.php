<?php
/**
 * @var array $arResult
 */
?>
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
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <label style="font-size: 0.9em; cursor: pointer; user-select: none;">
                        <input type="checkbox" name="remember" value="1" checked> Запомнить меня
                    </label>
                    <a href="#" onclick="showForgotForm(event)" class="forgot-link">Забыли пароль?</a>
                </div>
                <button type="submit" class="btn-primary btn-block">Войти</button>
                <div id="login-error" class="error-msg" style="display:none; color: #ff5252; margin-top: 10px;"></div>
            </form>

            <div class="auth-separator">
                <div class="auth-separator-text">— или —</div>
                
                <?php if ($arResult['telegram_auth_enabled'] && !empty($arResult['telegram_bot_username'])): ?>
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
                <?php if ($arResult['telegram_auth_enabled'] && !empty($arResult['telegram_bot_username'])): ?>
                    <div style="text-align: center;">
                        <script async src="https://telegram.org/js/telegram-widget.js?22" 
                                data-telegram-login="<?= htmlspecialchars($arResult['telegram_bot_username']) ?>" 
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
