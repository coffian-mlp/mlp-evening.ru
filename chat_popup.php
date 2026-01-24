<?php
// chat_popup.php - Standalone Chat Window

require_once __DIR__ . '/src/EpisodeManager.php';
require_once __DIR__ . '/src/ConfigManager.php';
require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/UserManager.php';

Auth::check(); 

$currentUser = null;
$userOptions = [];
if (Auth::check()) {
    $userManager = new UserManager();
    $currentUser = $userManager->getUserById($_SESSION['user_id']);
    $userOptions = $userManager->getUserOptions($_SESSION['user_id']);
}

require_once __DIR__ . '/src/StickerManager.php';
$stickerManager = new StickerManager();
$packs = $stickerManager->getAllPacks();
$allStickers = $stickerManager->getAllStickers(true); 
$stickersByPack = [];
foreach ($allStickers as $s) {
    $stickersByPack[$s['pack_id']][] = $s;
}
$stickerMap = $stickerManager->getStickerMap(); 
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
        $sub = Auth::check() ? (string)$_SESSION['user_id'] : "guest_" . substr(session_id(), 0, 10);
        
        // Token valid for 24 hours
        $centrifugoToken = $centrifugoService->generateToken($sub, time() + 86400);
    }

    $config = ConfigManager::getInstance();
$telegramAuthEnabled = (bool)$config->getOption('telegram_auth_enabled', 0);
$telegramBotUsername = $config->getOption('telegram_bot_username', '');

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Чат - Поняшный вечерок</title>
    <link rel="stylesheet" href="/assets/css/main.css?v=<?= time() ?>">
    <link rel="stylesheet" href="/assets/css/chat.css?v=<?= time() ?>">
    <link rel="stylesheet" href="/assets/css/fonts.css">
    <style>
        /* Сбрасываем отступы, чтобы чат был на все окно */
        body, html { 
            height: 100%; 
            margin: 0; 
            padding: 0; 
            overflow: hidden; 
            /* Фон наследуется из main.css (bg.jpg), не переопределяем его здесь */
        }
        
        .chat-container { 
            height: 100vh !important; 
            width: 100vw !important; 
            border: none !important; 
            border-radius: 0 !important; 
            margin: 0 !important;
            box-shadow: none !important;
            /* Убедимся, что фон контейнера соответствует дизайну */
            background: var(--bg-darker) !important; 
            backdrop-filter: blur(2px) !important;
        }

        /* Скрываем кнопку разворачивания поп-апа внутри самого поп-апа */
        .popout-btn { display: none !important; }

        /* === Принудительная ДЕСКТОПНАЯ ВЕРСИЯ === */
        /* Возвращаем стандартное поле ввода */
        .chat-input-area { 
            display: block !important; 
            position: relative !important;
            bottom: 0 !important;
            background: rgba(30, 20, 50, 0.4) !important;
        }

        /* Скрываем мобильную кнопку (FAB) и мобильное окно ввода */
        .chat-mobile-fab, 
        #chat-mobile-input-overlay { 
            display: none !important; 
        }

        /* Возвращаем нормальный паддинг для списка сообщений */
        .chat-messages {
            padding-bottom: 10px !important; 
        }

        /* Стикеры в десктопном режиме */
        .sticker-picker {
            position: absolute !important;
            bottom: 100% !important;
            left: 0 !important;
            width: 100% !important;
            height: 250px !important;
            border-radius: 6px 6px 0 0 !important;
        }
        
        /* Кнопка закрытия стикеров (обычно скрыта на десктопе, но в попапе пусть будет, если места мало) */
        .sticker-close-btn {
            display: block !important; 
        }

    </style>
</head>
<body>

<!-- Chat Container -->
<div class="chat-container" id="chat">
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
            
            <div id="chat-input-mute-opts" style="display:none; margin-bottom:10px;">
                <select id="chat-mute-time" class="form-input" style="width:100%; margin-bottom:5px;">
                    <option value="15">15 минут</option>
                    <option value="60">1 час</option>
                    <option value="180">3 часа</option>
                    <option value="1440">24 часа</option>
                    <option value="10080">7 дней</option>
                </select>
            </div>

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
        <div class="chat-user-menu">
            <?php if (Auth::check()): ?>
                <div class="user-controls">
                    <a href="#" class="profile-link" title="Вы вошли как <?= htmlspecialchars($_SESSION['username']) ?>">
                        <span class="avatar-mini">
                            <img src="<?= htmlspecialchars($currentUser['avatar_url'] ?: '/assets/img/default-avatar.png') ?>" alt="">
                        </span>
                        <span class="username" style="color: <?= htmlspecialchars($currentUser['chat_color'] ?? '#ce93d8') ?>">
                            <?= htmlspecialchars($_SESSION['username']) ?>
                        </span>
                    </a>
                </div>
            <?php else: ?>
                <span class="login-btn-chat">Режим просмотра</span>
            <?php endif; ?>
        </div>

        <div class="chat-settings">
            <span id="online-counter" class="online-badge" title="Онлайн">(0)</span>
            <!-- Кнопка здесь не нужна, скрыта стилями, но на всякий случай -->
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
                <a href="/" target="_blank">Войди на главной</a>, чтобы общаться.
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

<!-- Sticker Zoom Overlay -->
<div id="sticker-zoom-preview" style="display: none;">
    <img src="" alt="Sticker Preview">
</div>

<!-- Scripts -->
<script src="/assets/js/jquery.min.js"></script>
<script src="/assets/js/main.js?v=<?= time() ?>"></script>
<script>
    window.serverTime = <?= time() ?>;
    window.chatConfig = {
        driver: <?= json_encode($chatDriver) ?>,
        centrifugo: {
            url: <?= json_encode($centrifugoUrl) ?>,
            token: <?= json_encode($centrifugoToken) ?>
        }
    };
    </script>
    <?php if (isset($_SESSION['user_id'])): ?>
        <meta name="csrf-token" content="<?= Auth::generateCsrfToken() ?>">
    <?php endif; ?>
<?php if (isset($_SESSION['user_id'])): ?>
    <script>
        // Global User Info for JS
        window.currentUserId = <?= json_encode($_SESSION['user_id']) ?>;
        window.currentUserRole = <?= json_encode($_SESSION['role'] ?? 'user') ?>;
        window.isModerator = <?= json_encode(Auth::isModerator()) ?>;
        window.currentUsername = <?= json_encode($_SESSION['username']) ?>;
        window.currentUserNickname = <?= json_encode($currentUser['nickname'] ?? $_SESSION['username']) ?>;
        window.currentUserFont = <?= json_encode($userOptions['font_preference'] ?? 'open_sans') ?>;
        window.csrfToken = <?= json_encode(Auth::generateCsrfToken()) ?>;
        window.telegramBotUsername = <?= json_encode($telegramBotUsername) ?>;
        window.userOptions = <?= json_encode($userOptions) ?>;
        window.stickerMap = <?= json_encode($stickerMap) ?>;
        window.stickerData = <?= json_encode($frontendStickerData) ?>;
        window.chatConfig = {
            driver: <?= json_encode($chatDriver) ?>,
            centrifugo: {
                url: <?= json_encode($centrifugoUrl) ?>,
                token: <?= json_encode($centrifugoToken) ?>
            }
        };
    </script>
<?php endif; ?>
<!-- Centrifuge JS (only if needed) -->
<?php if (($chatDriver ?? '') === 'centrifugo'): ?>
    <script src="https://unpkg.com/centrifuge@5.0.1/dist/centrifuge.js"></script>
<?php endif; ?>
<script src="/assets/js/local-chat.js?v=<?= time() ?>"></script>

</body>
</html>