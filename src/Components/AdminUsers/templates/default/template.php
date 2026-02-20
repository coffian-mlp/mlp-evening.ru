<?php
/**
 * @var array $arResult
 */
?>
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h3 class="dashboard-title">👥 Управление Пользователями</h3>
        <button class="btn-primary" onclick="openUserModal()">➕ Добавить пони</button>
    </div>
    
    <div class="search-bar">
        <input type='text' id='userSearchInput' placeholder='🔍 Поиск пони...' class="search-input">
    </div>

    <table class="dashboard-table" id="users-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Логин</th>
                <th>Никнейм</th>
                <th>Роль</th>
                <th>Статус</th>
                <th>Дата регистрации</th>
                <th style="text-align: right;">Действия</th>
            </tr>
        </thead>
        <tbody>
            <tr><td colspan="7" style="text-align:center;">Загрузка...</td></tr>
        </tbody>
    </table>
</div>

<!-- Modals are shared or need to be included here if they are component-specific. 
     Currently they are in dashboard/index.php globally. 
     We should probably move them here or keep them global. 
     For now, let's assume they stay global or we move them to a separate template part later.
     Actually, let's include the modals structure here to be self-contained if possible, 
     but the JS logic in dashboard.js relies on them being present.
     Since dashboard.js is global, let's keep modals in the main layout OR duplicate them here.
     Ideally, they should be part of this component. Let's move user-related modals here.
-->

<!-- User Modal -->
<div id="user-modal" class="modal-overlay">
    <div class="modal-content">
        <span class="close-modal" onclick="closeUserModal()">&times;</span>
        <h3 id="user-modal-title">Пользователь</h3>
        <form id="user-form" action="/api.php" method="post">
            <input type="hidden" name="action" value="save_user">
            <input type="hidden" name="user_id" id="user_id">
            
            <div class="form-group">
                <label class="form-label">Логин (для входа)</label>
                <input type="text" name="login" id="user_login" class="form-input" required>
            </div>

            <div class="form-group">
                <label class="form-label">Никнейм (в чате)</label>
                <input type="text" name="nickname" id="user_nickname" class="form-input" placeholder="Если пусто, будет как логин">
            </div>
            
            <div class="form-group">
                <label class="form-label">Роль</label>
                <select name="role" id="user_role" class="form-input">
                    <option value="user">Пользователь</option>
                    <option value="moderator">Модератор</option>
                    <option value="admin">Администратор</option>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label">Аватар</label>
                <input type="file" name="avatar_file" id="user_avatar_file" class="form-input" accept="image/jpeg,image/png,image/gif,image/webp">
                <input type="text" name="avatar_url" id="user_avatar_url" class="form-input" placeholder="Или ссылка..." style="margin-top: 5px;">
            </div>

            <div class="form-group">
                <label class="form-label">Цвет ника</label>
                <div class="color-picker-ui">
                    <input type="hidden" name="chat_color" id="user_chat_color" value="#6d2f8e">
                    <div class="manual-input-wrapper">
                        <span style="font-size: 0.9em; color: #666;">HEX:</span>
                        <input type="text" class="color-manual-input" placeholder="#HEX..." maxlength="7">
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Шрифт интерфейса</label>
                <select name="font_preference" id="user_font_preference" class="form-input">
                    <option value="open_sans">Open Sans (Стандартный)</option>
                    <option value="fira">Fira Sans (Четкий)</option>
                    <option value="pt">PT Sans (Строгий)</option>
                    <option value="rubik">Rubik (Мягкий)</option>
                    <option value="inter">Inter (Современный)</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Пароль</label>
                <div class="password-wrapper">
                    <input type="password" name="password" id="user_password" class="form-input" placeholder="Пусто = не менять">
                    <button type="button" class="password-toggle-btn">👁️</button>
                </div>
                <small style="color: #777;">Заполните только если хотите сменить.</small>
            </div>
            
            <button type="submit" class="btn-primary" style="width:100%">💾 Сохранить</button>
        </form>
    </div>
</div>

<!-- Ban Modal -->
<div id="ban-modal" class="modal-overlay">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal('#ban-modal')">&times;</span>
        <h3 style="color:#c0392b">🔨 Бан пользователя</h3>
        <form id="ban-form" action="/api.php" method="post">
            <input type="hidden" name="action" value="ban_user">
            <input type="hidden" name="user_id" id="ban_user_id">
            
            <p>Вы собираетесь забанить: <strong id="ban_username_display"></strong></p>
            <p style="font-size:0.9em; color:#666; margin-bottom:15px;">Пользователь потеряет доступ к сайту.</p>
            
            <div class="form-group">
                <label class="form-label">Причина</label>
                <input type="text" name="reason" class="form-input" placeholder="Например: Спам, Грубость..." required>
            </div>
            
            <button type="submit" class="btn-danger" style="width:100%">ЗАБАНИТЬ НАВСЕГДА</button>
        </form>
    </div>
</div>

<!-- Mute Modal -->
<div id="mute-modal" class="modal-overlay">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal('#mute-modal')">&times;</span>
        <h3 style="color:#f39c12">🤐 Мут пользователя</h3>
        <form id="mute-form" action="/api.php" method="post">
            <input type="hidden" name="action" value="mute_user">
            <input type="hidden" name="user_id" id="mute_user_id">
            
            <p>Вы собираетесь заглушить: <strong id="mute_username_display"></strong></p>
            <p style="font-size:0.9em; color:#666; margin-bottom:15px;">Пользователь не сможет писать в чат.</p>
            
            <div class="form-group">
                <label class="form-label">Длительность</label>
                <select name="minutes" class="form-input">
                    <option value="15">15 минут</option>
                    <option value="60">1 час</option>
                    <option value="180">3 часа</option>
                    <option value="1440">24 часа (Сутки)</option>
                    <option value="10080">7 дней (Неделя)</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Причина (опционально)</label>
                <input type="text" name="reason" class="form-input" placeholder="Например: Флуд...">
            </div>
            
            <button type="submit" class="btn-warning" style="width:100%">Заглушить</button>
        </form>
    </div>
</div>
