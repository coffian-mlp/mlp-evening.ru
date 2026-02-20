<?php
/**
 * @var array $arResult
 */
?>
<!-- Profile Modal -->
<div id="profile-modal" class="modal-overlay" style="display: none;">
    <div class="modal-content" style="max-width: 450px; text-align: left;">
        <span class="close-modal-profile" style="position: absolute; top: 10px; right: 15px; font-size: 28px; cursor: pointer; color: #aaa;">&times;</span>
        
        <h3 style="text-align: center; color: #6d2f8e; margin-bottom: 15px;">🦄 Твой Профиль</h3>
        
        <?php if ($arResult['user']): ?>
        
        <!-- Profile Tabs Navigation -->
        <div class="profile-tabs">
            <button type="button" class="profile-tab-btn active" onclick="switchProfileTab('visual')">🎨 Внешность</button>
            <button type="button" class="profile-tab-btn" onclick="switchProfileTab('system')">⚙️ Настройки</button>
        </div>

        <form id="ajax-profile-form">
            <input type="hidden" name="action" value="update_profile">
            <input type="hidden" name="csrf_token" value="<?= $arResult['csrf_token'] ?>">

            <!-- TAB 1: VISUAL (Внешность) -->
            <div id="tab-visual" class="profile-tab-content active">
                <div class="form-group" style="margin-bottom: 15px;">
                    <label class="form-label">Имя в чате</label>
                    <input type="text" name="nickname" value="<?= htmlspecialchars($arResult['user']['nickname']) ?>" class="form-input" required>
                </div>

                <div class="form-group" style="margin-bottom: 15px;">
                    <label class="form-label">Цвет имени</label>
                    <div class="color-picker-ui">
                        <input type="hidden" name="chat_color" value="<?= htmlspecialchars($arResult['user']['chat_color'] ?? '#6d2f8e') ?>">
                        <div class="manual-input-wrapper">
                            <span style="font-size: 0.9em; color: #666;">HEX:</span>
                            <input type="text" class="color-manual-input" placeholder="#..." maxlength="7">
                        </div>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 15px;">
                    <label class="form-label">Шрифт интерфейса</label>
                    <select name="font_preference" class="form-input">
                        <option value="open_sans" <?= ($arResult['options']['font_preference'] ?? '') === 'open_sans' ? 'selected' : '' ?>>Open Sans (Стандартный)</option>
                        <option value="fira" <?= ($arResult['options']['font_preference'] ?? '') === 'fira' ? 'selected' : '' ?>>Fira Sans (Четкий)</option>
                        <option value="pt" <?= ($arResult['options']['font_preference'] ?? '') === 'pt' ? 'selected' : '' ?>>PT Sans (Строгий)</option>
                        <option value="rubik" <?= ($arResult['options']['font_preference'] ?? '') === 'rubik' ? 'selected' : '' ?>>Rubik (Мягкий)</option>
                        <option value="inter" <?= ($arResult['options']['font_preference'] ?? '') === 'inter' ? 'selected' : '' ?>>Inter (Современный)</option>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom: 15px;">
                    <label class="form-label">Аватарка</label>
                    <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 10px;">
                        <img src="<?= htmlspecialchars($arResult['user']['avatar_url'] ?: '/assets/img/default-avatar.png') ?>" style="width: 50px; height: 50px; border-radius: 50%; object-fit: cover; border: 2px solid #ddd;" id="profile-avatar-preview">
                        <div style="flex: 1;">
                            <input type="file" name="avatar_file" class="form-input" accept="image/*" style="font-size: 0.9em;">
                        </div>
                    </div>
                    <input type="text" name="avatar_url" value="<?= htmlspecialchars($arResult['user']['avatar_url'] ?? '') ?>" class="form-input" placeholder="Или ссылка на картинку..." style="font-size: 0.9em;">
                </div>
            </div>

            <!-- TAB 2: SYSTEM (Система) -->
            <div id="tab-system" class="profile-tab-content" style="display: none;">
                <div class="form-group" style="margin-bottom: 15px;">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($arResult['user']['email'] ?? '') ?>" class="form-input" placeholder="mail@example.com">
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
                <?php if ($arResult['telegram_auth_enabled'] && !empty($arResult['telegram_bot_username'])): ?>
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
                <input type="hidden" name="csrf_token" value="<?= $arResult['csrf_token'] ?>">
                <button type="submit" class="btn btn-outline-danger">
                    🚪 Выйти
                </button>
            </form>
            
            <?php if ($arResult['is_admin']): ?>
                 <a href="/dashboard/index.php" class="btn btn-outline-warning">
                    🔧 Админка
                 </a>
            <?php endif; ?>
        </div>
        
        <?php else: ?>
            <p style="text-align: center;">Сначала нужно войти!</p>
        <?php endif; ?>
    </div>
</div>
