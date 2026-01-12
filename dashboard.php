<?php

require_once __DIR__ . '/src/EpisodeManager.php';
require_once __DIR__ . '/src/ConfigManager.php';
require_once __DIR__ . '/src/Auth.php';

// 🔒 ЗАЩИТА: Только для авторизованных
Auth::requireAdmin();

$manager = new EpisodeManager();
$config = ConfigManager::getInstance();

    // Получаем данные
    $eveningPlaylist = $manager->getEveningPlaylist();
    $allEpisodes = $manager->getAllEpisodes();
    $watchHistory = $manager->getWatchHistory();
    $currentStreamUrl = $config->getOption('stream_url', 'https://goodgame.ru/player?161438#autoplay');
    $currentChatMode = $config->getOption('chat_mode', 'local');
    $currentRateLimit = $config->getOption('chat_rate_limit', 0);

// Отделяем метаданные и эпизоды
$playlistMeta = $eveningPlaylist['_meta'] ?? null;
unset($eveningPlaylist['_meta']); 

// Подготовка списка ID для кнопки "Обновить TIMES_WATCHED"
$ids_string = '';
if (!empty($eveningPlaylist)) {
    $all_ids = [];
    foreach ($eveningPlaylist as $ep) {
        if (!empty($ep['ids'])) {
            $all_ids = array_merge($all_ids, $ep['ids']);
        }
    }
    $ids_string = implode(',', $all_ids);
}

// Фильтруем данные для таблицы двусерийников
$twoPartEpisodes = array_filter($allEpisodes, function($ep) {
    return $ep['LENGTH'] > 1;
});

$pageTitle = 'Dashboard - MLP Evening';
$bodyClass = 'dashboard-layout';
$extraCss = '<link rel="stylesheet" href="/assets/css/dashboard.css">';
$extraScripts = '<script src="/assets/js/dashboard.js"></script>';
$showChatBro = false; 
$showPageHeader = true; // Включаем общий хедер

require_once __DIR__ . '/src/templates/header.php';
?>

<div class="container">

    <!-- Навигация (Плитки) -->
    <div class="nav-grid">
        <div class="nav-tile active" data-target="#tab-playlist">
            <div class="icon">🌙</div>
            <div class="label">Вечерний плейлист</div>
        </div>
        <div class="nav-tile" data-target="#tab-library">
            <div class="icon">📚</div>
            <div class="label">Библиотека серий</div>
        </div>
        <div class="nav-tile" data-target="#tab-history">
            <div class="icon">📜</div>
            <div class="label">История просмотров</div>
        </div>
        <div class="nav-tile" data-target="#tab-users">
            <div class="icon">👥</div>
            <div class="label">Пользователи</div>
        </div>
        <div class="nav-tile" data-target="#tab-stickers">
            <div class="icon">😊</div>
            <div class="label">Стикеры</div>
        </div>
        <div class="nav-tile" data-target="#tab-moderation">
            <div class="icon">🛡️</div>
            <div class="label">Модерация</div>
        </div>
        <div class="nav-tile" data-target="#tab-controls">
            <div class="icon">⚙️</div>
            <div class="label">Управление</div>
        </div>
    </div>

    <!-- Вкладка 1: Плейлист -->
    <div id="tab-playlist" class="tab-content active">
        
        <!-- Информация о плейлисте -->
        <div class="playlist-info">
            <div>
                <?php if ($playlistMeta): ?>
                    <span class="playlist-date">📅 Сгенерирован: <strong><?= $playlistMeta['updated_at'] ?></strong></span>
                    <?php if ($playlistMeta['is_old']): ?>
                        <span class="status-badge old">⚠️ Устарел (> 7 дней)</span>
                    <?php else: ?>
                        <span class="status-badge fresh">✅ Актуален</span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            
            <form method="post" action="api.php">
                <input type="hidden" name="action" value="regenerate_playlist">
                <?php 
                    $confirmMsg = "Сгенерировать новый плейлист? Старый будет потерян.";
                    if (!$playlistMeta['is_old']) {
                        $confirmMsg .= "\n\nВНИМАНИЕ: Текущий плейлист еще свежий (менее 7 дней)!";
                    }
                ?>
                <button type="submit" onclick="return confirm('<?= $confirmMsg ?>')" class="btn-warning">🎲 Пересоздать плейлист</button>
            </form>
        </div>

        <div class="card">
            <h3 class="dashboard-title">✨ Случайная подборка на неделю</h3>
            <ol class="playlist-list">
            <?php foreach ($eveningPlaylist as $episode): ?>
                <li>
                    <strong><?= htmlspecialchars(implode(' / ', $episode['titles'])) ?></strong>
                    <span class="meta">(ID: <?= implode('/', $episode['ids']) ?>)</span>
                </li>
            <?php endforeach; ?>
            </ol>

            <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee;">
                <form method="post" action="api.php" style="display:inline;">
                    <input type="hidden" name="action" value="mark_watched">
                    <input type="hidden" name="ids" value="<?= htmlspecialchars($ids_string) ?>">
                    <button type="submit" class="btn-primary" onclick="return confirm('Отметить текущий плейлист как просмотренный?\n\nБудет сгенерирован НОВЫЙ плейлист, а текущий исчезнет.')">✅ Отметить просмотренным и обновить</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Вкладка 2: Библиотека -->
    <div id="tab-library" class="tab-content">
        
        <div class="card">
            <h3 class="dashboard-title">Полный список эпизодов</h3>
            
            <div class="search-bar">
                <input type='text' id='searchInput' placeholder='🔍 Поиск по названию...' class="search-input">
                <span class="search-hint">Нажмите на заголовок для сортировки</span>
            </div>

            <div style="overflow-x: auto;">
                <table id='fulltable' class="dashboard-table">
                    <thead>
                        <tr><th>ID</th><th>Title</th><th>Watched</th><th>Votes</th><th>2-Part</th><th>Len</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($allEpisodes as $row): ?>
                        <tr>
                            <td><?= $row['ID'] ?></td>
                            <td><?= htmlspecialchars($row['TITLE']) ?></td>
                            <td><?= $row['TIMES_WATCHED'] ?></td>
                            <td><?= $row['WANNA_WATCH'] ?></td>
                            <td><?= $row['TWOPART_ID'] ?></td>
                            <td><?= $row['LENGTH'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <h3 class="dashboard-title">Двусерийные эпизоды</h3>
            <table class="dashboard-table">
                <thead>
                    <tr><th>ID</th><th>Title</th><th>Len</th></tr>
                </thead>
                <tbody>
                <?php foreach ($twoPartEpisodes as $row): ?>
                    <tr>
                        <td><?= $row['ID'] ?></td>
                        <td><?= htmlspecialchars($row['TITLE']) ?></td>
                        <td><?= $row['LENGTH'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Вкладка 3: История -->
    <div id="tab-history" class="tab-content">
        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h3 class="dashboard-title">📜 История просмотров</h3>
                <form method="post" action="api.php">
                    <input type="hidden" name="action" value="clear_watching_log">
                    <button type="submit" class="btn-danger" onclick="return confirm('Очистить визуальный лог просмотров?')">🗑️ Очистить список</button>
                </form>
            </div>
            
            <table class="dashboard-table">
                <tr><th>Time ID</th><th>Ep ID</th><th>Title</th></tr>
                <?php foreach ($watchHistory as $row): ?>
                    <tr>
                        <td><?= $row['ID'] ?></td>
                        <td><?= $row['EPNUM'] ?></td>
                        <td><?= htmlspecialchars($row['TITLE']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>

    <!-- Вкладка 3.5: Пользователи -->
    <div id="tab-users" class="tab-content">
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
                        <th>Статус</th> <!-- New Column -->
                        <th>Дата регистрации</th>
                        <th style="text-align: right;">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td colspan="7" style="text-align:center;">Загрузка...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Вкладка 4: Стикеры -->
    <div id="tab-stickers" class="tab-content">
        
        <div style="display: flex; gap: 20px; align-items: flex-start;">
            
            <!-- Левая колонка: Управление Паками -->
            <div style="flex: 1; max-width: 300px;">
                <div class="card">
                    <h3 class="dashboard-title">📦 Паки</h3>
                    <form id="create-pack-form" action="api.php" method="post" enctype="multipart/form-data" style="margin-bottom: 15px;">
                        <input type="hidden" name="action" value="create_pack">
                        <input type="text" name="code" placeholder="Код (mane6)" class="form-input" style="margin-bottom: 5px; width: 100%;" required>
                        <input type="text" name="name" placeholder="Название (Mane 6)" class="form-input" style="margin-bottom: 5px; width: 100%;" required>
                        <div style="display:flex; align-items:center; gap:5px; margin-bottom:5px;">
                            <label style="font-size:0.8em; color:#666;">Иконка:</label>
                            <input type="file" name="icon_file" accept="image/*" class="form-input" style="padding:5px; font-size:0.8em;">
                        </div>
                        <button type="submit" class="btn-primary" style="width: 100%;">Создать Пак</button>
                    </form>
                    
                    <ul id="packs-list" style="list-style: none; padding: 0; margin: 0;">
                        <li>Загрузка...</li>
                    </ul>
                </div>

                <!-- ZIP Upload (Global context or per pack) -->
                <!-- We will show this inside the pack modal or context, but for now let's keep it simple here linked to selection -->
                <div class="card" id="zip-upload-card" style="display: none;">
                    <h4 style="margin-top: 0;">📥 Импорт ZIP</h4>
                    <p style="font-size: 0.8em; color: #666;">В выбранный пак: <strong id="zip-target-pack-name">...</strong></p>
                    <form id="zip-import-form" action="api.php" method="post" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="import_zip_stickers">
                        <input type="hidden" name="pack_id" id="zip-pack-id">
                        <input type="file" name="zip_file" class="form-input" accept=".zip" required>
                        <button type="submit" class="btn-warning" style="width: 100%; margin-top: 10px;">Загрузить ZIP</button>
                    </form>
                </div>
            </div>

            <!-- Правая колонка: Стикеры -->
            <div style="flex: 3;">
                <div class="card">
                    <h3 class="dashboard-title">✨ Стикеры <span id="current-pack-label" style="font-size: 0.7em; color: #aaa;">(Все)</span></h3>
                    
                    <form id="add-sticker-form" action="api.php" method="post" enctype="multipart/form-data" style="margin-bottom: 20px; padding: 15px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.1); background: rgba(0,0,0,0.2);">
                        <input type="hidden" name="action" value="add_sticker">
                        
                        <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end;">
                            <div style="flex: 1; min-width: 120px;">
                                <label class="form-label">Пак</label>
                                <select name="pack_id" id="sticker-pack-select" class="form-input" required>
                                    <option value="">Загрузка...</option>
                                </select>
                            </div>

                            <div style="flex: 1; min-width: 120px;">
                                <label class="form-label">Код</label>
                                <input type="text" name="code" class="form-input" placeholder="happy" required>
                            </div>
                            
                            <div style="flex: 2; min-width: 200px;">
                                <label class="form-label">Файл / Ссылка</label>
                                <input type="file" name="image_file" class="form-input" accept="image/*">
                            </div>

                            <button type="submit" class="btn-primary" style="height: 40px;">➕</button>
                        </div>
                    </form>

                    <div class="search-bar">
                        <input type='text' id='stickerSearchInput' placeholder='🔍 Поиск стикеров...' class="search-input">
                    </div>

                    <table class="dashboard-table" id="stickers-table">
                        <thead>
                            <tr>
                                <th width="60">Превью</th>
                                <th>Код</th>
                                <th>Пак</th>
                                <th style="text-align: right;">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td colspan="4" style="text-align:center;">Выберите пак слева или ждите загрузки...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Вкладка 5: Модерация -->
    <div id="tab-moderation" class="tab-content">
        <div class="card">
            <h3 class="dashboard-title">🚫 Список нарушителей (Ban/Mute)</h3>
             <table class="dashboard-table" id="punished-users-table">
                <thead>
                    <tr>
                        <th>Пользователь</th>
                        <th>Наказание</th>
                        <th>Причина</th>
                        <th>Истекает</th>
                        <th style="text-align: right;">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td colspan="5" style="text-align:center;">Загрузка...</td></tr>
                </tbody>
            </table>
        </div>

        <div class="card">
            <h3 class="dashboard-title">📜 Журнал действий (Audit Logs)</h3>
            <table class="dashboard-table" id="audit-logs-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Модератор</th>
                        <th>Действие</th>
                        <th>Цель (ID)</th>
                        <th>Детали</th>
                        <th>Время</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td colspan="6" style="text-align:center;">Загрузка...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pack Edit Modal -->
    <div id="pack-modal" class="modal-overlay">
        <div class="modal-content">
            <span class="close-modal" onclick="closeModal('#pack-modal')">&times;</span>
            <h3>📦 Редактировать Пак</h3>
            <form id="edit-pack-form" action="api.php" method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_pack">
                <input type="hidden" name="id" id="edit_pack_id">
                
                <div class="form-group">
                    <label class="form-label">Название</label>
                    <input type="text" name="name" id="edit_pack_name" class="form-input" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Код (системный)</label>
                    <input type="text" name="code" id="edit_pack_code" class="form-input" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Иконка (оставьте пустым, если не меняете)</label>
                    <input type="file" name="icon_file" class="form-input" accept="image/*">
                </div>
                
                <button type="submit" class="btn-primary" style="width:100%">Сохранить</button>
            </form>
        </div>
    </div>

    <!-- User Modal -->
    <div id="user-modal" class="modal-overlay">
        <div class="modal-content">
            <span class="close-modal" onclick="closeUserModal()">&times;</span>
            <h3 id="user-modal-title">Пользователь</h3>
            <form id="user-form" action="api.php" method="post">
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
            <form id="ban-form" action="api.php" method="post">
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
            <form id="mute-form" action="api.php" method="post">
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

    <!-- Вкладка 4: Управление -->
    <div id="tab-controls" class="tab-content">
        
        <div class="card">
            <h3 class="dashboard-title">💬 Настройки Чата</h3>
            <form method="post" action="api.php">
                <input type="hidden" name="action" value="update_settings">
                
                <div class="chat-options" style="display: flex; gap: 20px; margin-bottom: 15px;">
                    <label style="cursor: pointer;">
                        <input type="radio" name="chat_mode" value="local" <?= $currentChatMode === 'local' ? 'checked' : '' ?>>
                        🦄 Локальный чат (Новый)
                    </label>
                    <label style="cursor: pointer;">
                        <input type="radio" name="chat_mode" value="none" <?= $currentChatMode === 'none' ? 'checked' : '' ?>>
                        🚫 Без чата
                    </label>
                </div>
                
                <label for="chat_rate_limit" style="display: block; margin-bottom: 5px; font-weight: bold;">Анти-спам задержка (сек):</label>
                <input type="number" id="chat_rate_limit" name="chat_rate_limit" value="<?= $currentRateLimit ?>" min="0" max="60" style="width: 60px; padding: 5px;">
                <span style="color: #666; font-size: 0.9em;">(0 = отключено)</span>

                <br><br>
                <button type="submit" class="btn-primary">Сохранить режим</button>
            </form>
        </div>

        <div class="card">
            <h3 class="dashboard-title">🔗 Социальная Авторизация</h3>
            <form method="post" action="api.php">
                <input type="hidden" name="action" value="update_settings">
                
                <div class="form-group">
                    <label style="display: flex; align-items: center; cursor: pointer;">
                        <input type="hidden" name="telegram_auth_enabled" value="0">
                        <input type="checkbox" name="telegram_auth_enabled" value="1" <?= $config->getOption('telegram_auth_enabled', 0) ? 'checked' : '' ?> style="width: auto; margin-right: 10px;">
                        Включить вход через Telegram
                    </label>
                </div>

                <div class="form-group">
                    <label class="form-label">Telegram Bot Token (от @BotFather)</label>
                    <input type="password" name="telegram_bot_token" value="<?= htmlspecialchars($config->getOption('telegram_bot_token', '')) ?>" class="form-input" placeholder="123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Telegram Bot Username (без @)</label>
                    <input type="text" name="telegram_bot_username" value="<?= htmlspecialchars($config->getOption('telegram_bot_username', '')) ?>" class="form-input" placeholder="MyPonyBot">
                </div>

                <button type="submit" class="btn-primary">Сохранить ключи</button>
            </form>
        </div>

        <div class="card">
            <h3 class="dashboard-title">📧 Настройки Почты (SMTP)</h3>
            <p style="font-size: 0.9em; color: #666; margin-bottom: 15px;">
                Если SMTP выключен, используется стандартная функция <code>mail()</code> (или запись в лог при отладке).
            </p>
            <form method="post" action="api.php">
                <input type="hidden" name="action" value="update_settings">

                <div class="form-group">
                    <label style="display: flex; align-items: center; cursor: pointer;">
                        <input type="hidden" name="smtp_enabled" value="0">
                        <input type="checkbox" name="smtp_enabled" value="1" <?= $config->getOption('smtp_enabled', 0) ? 'checked' : '' ?> style="width: auto; margin-right: 10px;">
                        <strong>Включить отправку через SMTP</strong>
                    </label>
                </div>

                <div class="form-group">
                    <label class="form-label">SMTP Хост</label>
                    <input type="text" name="smtp_host" value="<?= htmlspecialchars($config->getOption('smtp_host', 'smtp.yandex.ru')) ?>" class="form-input" placeholder="smtp.yandex.ru">
                </div>

                <div style="display: flex; gap: 10px;">
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">SMTP Порт</label>
                        <input type="number" name="smtp_port" value="<?= htmlspecialchars($config->getOption('smtp_port', '465')) ?>" class="form-input" placeholder="465 (SSL) / 587 (TLS)">
                    </div>
                    <div class="form-group" style="flex: 2;">
                        <label class="form-label">Имя отправителя</label>
                        <input type="text" name="smtp_from_name" value="<?= htmlspecialchars($config->getOption('smtp_from_name', 'MLP Evening')) ?>" class="form-input" placeholder="MLP Evening">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">SMTP Логин (Email)</label>
                    <input type="text" name="smtp_user" value="<?= htmlspecialchars($config->getOption('smtp_user', '')) ?>" class="form-input" placeholder="noreply@mlp-evening.ru">
                </div>

                <div class="form-group">
                    <label class="form-label">SMTP Пароль (Пароль приложения)</label>
                    <div class="password-wrapper">
                        <input type="password" name="smtp_pass" value="<?= htmlspecialchars($config->getOption('smtp_pass', '')) ?>" class="form-input" placeholder="••••••••">
                        <button type="button" class="password-toggle-btn">👁️</button>
                    </div>
                </div>

                <button type="submit" class="btn-primary">Сохранить SMTP</button>
            </form>
        </div>

        <div class="card">
            <h3 class="dashboard-title">📺 Настройки Плеера</h3>
            <form method="post" action="api.php">
                <input type="hidden" name="action" value="update_settings">
                <label for="stream_url" style="display: block; margin-bottom: 8px; font-weight: bold;">Ссылка на стрим (iframe src):</label>
                <div style="display: flex; gap: 10px;">
                    <input type="text" id="stream_url" name="stream_url" value="<?= htmlspecialchars($currentStreamUrl) ?>" style="flex: 1;" required>
                    <button type="submit" class="btn-primary">Сохранить</button>
                </div>
                <p style="color: #666; font-size: 0.9em; margin-top: 8px;">
                    Например: <code>https://goodgame.ru/player?161438#autoplay</code> или <code>https://player.twitch.tv/?channel=...</code>
                </p>
            </form>
        </div>

        <div class="card">
            <h3 class="dashboard-title">🗳️ Голосование (Ручной режим)</h3>
            <p>Если нужно добавить голос за конкретную серию вручную:</p>
            
            <form method="post" action="api.php" style="margin-top: 15px;">
                <input type="hidden" name="action" value="vote">
                <label for="episode_id">ID Эпизода:</label>
                <input type="number" id="episode_id" name="episode_id" min="1" max="221" required placeholder="1-221" style="width: 100px;">
                <button type="submit" class="btn-primary">Добавить голос (+1 Wanna Watch)</button>
            </form>
        </div>

        <div class="card danger-zone">
            <h3 class="dashboard-title" style="color: #c0392b;">⚠️ Опасная зона</h3>
            <p>Глобальный сброс параметров. Будьте осторожны.</p>
            
            <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                <form method="post" action="api.php">
                    <input type="hidden" name="action" value="clear_votes">
                    <button type="submit" class="btn-danger" onclick="return confirm('Точно сбросить все голоса (WANNA_WATCH)?')">🗑️ Сбросить голоса</button>
                </form>

                <form method="post" action="api.php">
                    <input type="hidden" name="action" value="reset_times_watched">
                    <button type="submit" class="btn-danger" onclick="return confirm('Точно сбросить счетчики просмотров? Все серии снова станут непросмотренными!')">🔄 Сбросить просмотры</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/src/templates/footer.php'; ?>