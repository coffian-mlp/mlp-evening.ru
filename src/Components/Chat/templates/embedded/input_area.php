<?php
// chat_input_area.php
?>
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
        <div class="chat-input-wrapper">
            <textarea id="chat-input" placeholder="Напиши что-нибудь..." rows="1"></textarea>
            <button type="submit">➤</button>
        </div>
    </form>
    <?php else: ?>
    <div class="chat-login-prompt">
        <a href="#" id="login-link" onclick="openLoginModal(event)">Войди</a>, чтобы общаться.
    </div>
    <?php endif; ?>
</div>
