<?php
if (!defined("B_PROLOG_INCLUDED") && !defined("bg.jpg")) { 
    // Защита от прямого вызова, если бы мы были в Битриксе. 
    // Но у нас свой путь. 
}

/** 
 * @var array $arResult 
 * @var array $arParams
 */

$enableLocalChat = true; // Для совместимости с логикой шаблона
$currentUser = $arResult['user'];
$userOptions = $arResult['user_options'];
$telegramBotUsername = $arResult['telegram_bot_username'];
$telegramAuthEnabled = $arResult['telegram_auth_enabled'];

// CSS чата подключается автоматически через Component->includeTemplate() -> style.css
// JS чата подключается автоматически через Component->includeTemplate() -> script.js
?>

<!-- Chat Container -->
<div class="chat-container" id="chat" style="<?= isset($arParams['HEIGHT']) ? 'height:'.$arParams['HEIGHT'] : '' ?>">
    
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
            <?php if ($arResult['mode'] !== 'popup'): ?>
                <button id="popout-chat" class="icon-btn popout-btn" title="Открыть в отдельном окне" onclick="window.open('/chat_popup.php', 'ChatWindow', 'width=450,height=700'); return false;" style="margin-right: 5px;">❐</button>
            <?php endif; ?>
            <button id="toggle-title-alert" class="icon-btn" title="Моргание вкладки">🔔</button>
        </div>
    </div>
    
    <div class="chat-messages" id="chat-messages">
        <div class="chat-welcome">Добро пожаловать в Поняшный чат! 🦄<br>Не стесняйся, пиши!</div>
    </div>
    
    <!-- Compact Mode Trigger (Mobile) -->
    <?php if (isset($_SESSION['user_id'])): ?>
        <button id="chat-mobile-fab" class="chat-mobile-fab" title="Написать" style="position: absolute; right: 20px; bottom: 20px; z-index: 90;">✎</button>
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

<!-- Auth Modal (Если он нужен прямо здесь, или он в футере?) -->
<!-- Он в index.php был, но так как он используется чатом, его лучше иметь глобально. 
     Пока оставим его в index.php, но кнопки Login ведут на JS функции. -->

<script>
    // Inject PHP Variables into JS
    window.chatConfig = {
        driver: <?= json_encode($arResult['chat_driver']) ?>,
        centrifugo: {
            url: <?= json_encode($arResult['centrifugo_url']) ?>,
            token: <?= json_encode($arResult['centrifugo_token']) ?>
        }
    };
    
    <?php if (isset($_SESSION['user_id'])): ?>
        window.currentUserId = <?= json_encode($_SESSION['user_id']) ?>;
        window.currentUserRole = <?= json_encode($_SESSION['role'] ?? 'user') ?>;
        window.isModerator = <?= json_encode(Auth::isModerator()) ?>;
        window.currentUsername = <?= json_encode($_SESSION['username']) ?>;
        window.currentUserNickname = <?= json_encode($currentUser['nickname'] ?? $_SESSION['username']) ?>;
        window.currentUserFont = <?= json_encode($userOptions['font_preference'] ?? 'open_sans') ?>;
        window.csrfToken = <?= json_encode(Auth::generateCsrfToken()) ?>;
        window.telegramBotUsername = <?= json_encode($telegramBotUsername) ?>;
        window.userOptions = <?= json_encode($userOptions) ?>;
        
        window.stickerMap = <?= json_encode($arResult['sticker_map']) ?>;
        window.stickerData = <?= json_encode($arResult['frontend_sticker_data']) ?>;
    <?php endif; ?>
</script>

<?php if (($arResult['chat_driver'] ?? '') === 'centrifugo'): ?>
    <script src="https://unpkg.com/centrifuge@5.0.1/dist/centrifuge.js"></script>
<?php endif; ?>

<!-- Sticker Zoom Overlay -->
<div id="sticker-zoom-preview" style="display: none;">
    <img src="" alt="Sticker Preview">
</div>
