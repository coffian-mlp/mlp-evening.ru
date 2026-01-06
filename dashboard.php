<?php

require_once __DIR__ . '/src/EpisodeManager.php';
require_once __DIR__ . '/src/Auth.php';

// 🔒 ЗАЩИТА: Только для авторизованных
Auth::requireAdmin();

$manager = new EpisodeManager();

    // Получаем данные
    $eveningPlaylist = $manager->getEveningPlaylist();
    $allEpisodes = $manager->getAllEpisodes();
    $watchHistory = $manager->getWatchHistory();
    $currentStreamUrl = $manager->getOption('stream_url', 'https://goodgame.ru/player?161438#autoplay');
    $currentChatMode = $manager->getOption('chat_mode', 'local');
    $currentRateLimit = $manager->getOption('chat_rate_limit', 0);

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
                        <input type="radio" name="chat_mode" value="chatbro" <?= $currentChatMode === 'chatbro' ? 'checked' : '' ?>>
                        🤖 ChatBro (Старый)
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