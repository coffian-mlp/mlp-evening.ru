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
<link rel="stylesheet" href="/src/Components/Chat/templates/embedded/tooltip.css">

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
            
            <style>
                .chat-dropdown-content a:hover {
                    background-color: rgba(255,255,255,0.1);
                }
            </style>
            <div class="chat-dropdown" style="position: relative; display: inline-block;">
                <button class="icon-btn" id="chat-menu-btn" onclick="toggleChatMenu(event)" title="Меню чата">⚙️</button>
                <div id="chat-dropdown-menu" class="chat-dropdown-content" style="display:none; position:absolute; right:0; background: var(--bg-dark); border:1px solid rgba(255,255,255,0.1); border-radius:5px; box-shadow:0 15px 50px rgba(0,0,0,0.6); backdrop-filter: blur(5px); z-index:100; min-width: 180px; padding: 5px 0;">
                    <a href="#" onclick="window.open('/schedule.php', 'ScheduleWindow', 'width=800,height=700'); return false;" style="display:block; padding:8px 15px; color:#eee; text-decoration:none; white-space:nowrap;">📅 Расписание</a>
                    <a href="javascript:void(0)" id="chat-search-btn" style="display:block; padding:8px 15px; color:#eee; text-decoration:none; white-space:nowrap;">🔍 Поиск сообщений</a>
                    <a href="javascript:void(0)" id="toggle-title-alert" style="display:block; padding:8px 15px; color:#eee; text-decoration:none; white-space:nowrap;">🔔 Моргание вкладки</a>
                    <?php if ($arResult['mode'] !== 'popup'): ?>
                        <a href="#" id="popout-chat" onclick="window.open('/chat_popup.php', 'ChatWindow', 'width=450,height=700'); return false;" style="display:block; padding:8px 15px; color:#eee; text-decoration:none; white-space:nowrap;">❐ В отдельном окне</a>
                    <?php endif; ?>
                </div>
            </div>
            
            <script>
                function toggleChatMenu(e) {
                    e.stopPropagation();
                    const menu = document.getElementById('chat-dropdown-menu');
                    menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
                }
                document.addEventListener('click', function(e) {
                    const menu = document.getElementById('chat-dropdown-menu');
                    if (menu && menu.style.display === 'block') {
                        menu.style.display = 'none';
                    }
                });
            </script>
        </div>
    </div>
    
    <!-- Search Overlay -->
    <div id="chat-search-overlay" class="chat-search-overlay" style="display: none;">
        <div class="chat-search-header">
             <input type="text" id="chat-search-input" placeholder="Поиск сообщений..." autocomplete="off">
             <button id="chat-search-close" class="chat-search-close" title="Закрыть">&times;</button>
        </div>
        <div id="chat-search-results" class="chat-search-results"></div>
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
                <div class="chat-mobile-input-wrapper">
                    <textarea id="chat-mobile-input" placeholder="Напиши что-нибудь..." rows="3"></textarea>
                    <button type="submit" class="chat-mobile-send-btn" title="Отправить">➤</button>
                </div>
                <div class="chat-mobile-actions">
                    <div style="display: flex; gap: 10px;">
                        <button type="button" id="mobile-sticker-btn" class="chat-format-btn" style="font-size: 20px;" title="Стикеры">😊</button>
                        <button type="button" id="mobile-upload-btn" class="chat-format-btn" style="font-size: 20px;" title="Загрузить">📎</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php include __DIR__ . '/input_area.php'; ?>


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
        window.currentUserFontScale = <?= json_encode($userOptions['font_scale'] ?? 100) ?>;
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
