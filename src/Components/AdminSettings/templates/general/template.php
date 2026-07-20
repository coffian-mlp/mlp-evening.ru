<?php
/**
 * @var array $arResult
 */
$config = $arResult['config']; // Helper
?>
<!-- MLP-256: вариант 'general' — системные, чат, соцавторизация, SMTP, плеер (вкладка «Настройки») -->
<div class="card">
    <h3 class="dashboard-title">🛠️ Системные Настройки</h3>
    <form method="post" action="/api.php">
        <input type="hidden" name="action" value="update_settings">
        
        <div class="form-group">
            <label style="display: flex; align-items: center; cursor: pointer;">
                <input type="hidden" name="debug_mode" value="0">
                <input type="checkbox" name="debug_mode" value="1" <?= $config->getOption('debug_mode', 0) ? 'checked' : '' ?> style="width: auto; margin-right: 10px;">
                <strong>Включить режим отладки (Display Errors)</strong>
            </label>
            <p style="font-size: 0.85em; color: #666; margin-left: 24px; margin-top: 4px;">
                Показывает ошибки PHP на экране. Полезно, если <code>api.php</code> отдает 500. Не забудьте выключить на боевом!
            </p>
        </div>

        <button type="submit" class="btn-primary">Сохранить настройки</button>
    </form>
</div>

<div class="card">
    <h3 class="dashboard-title">💬 Настройки Чата</h3>
    <form method="post" action="/api.php">
        <input type="hidden" name="action" value="update_settings">
        
        <div class="chat-options" style="display: flex; gap: 20px; margin-bottom: 15px;">
            <label style="cursor: pointer;">
                <input type="radio" name="chat_mode" value="local" <?= $arResult['currentChatMode'] === 'local' ? 'checked' : '' ?>>
                🦄 Локальный чат (Новый)
            </label>
            <label style="cursor: pointer;">
                <input type="radio" name="chat_mode" value="none" <?= $arResult['currentChatMode'] === 'none' ? 'checked' : '' ?>>
                🚫 Без чата
            </label>
        </div>
        
        <label for="chat_rate_limit" style="display: block; margin-bottom: 5px; font-weight: bold;">Анти-спам задержка (сек):</label>
        <input type="number" id="chat_rate_limit" name="chat_rate_limit" value="<?= $arResult['currentRateLimit'] ?>" min="0" max="60" style="width: 60px; padding: 5px;">
        <span style="color: #666; font-size: 0.9em;">(0 = отключено)</span>

        <br><br>
        <label for="polls_create_role" style="display: block; margin-bottom: 5px; font-weight: bold;">📊 Кто может создавать опросы:</label>
        <?php $pollsRole = $config->getOption('polls_create_role', 'moderator'); ?>
        <select id="polls_create_role" name="polls_create_role" style="padding: 6px;">
            <option value="admin" <?= $pollsRole === 'admin' ? 'selected' : '' ?>>Только админы</option>
            <option value="moderator" <?= $pollsRole === 'moderator' ? 'selected' : '' ?>>Модераторы и админы</option>
            <option value="all" <?= $pollsRole === 'all' ? 'selected' : '' ?>>Все залогиненные</option>
        </select>

        <br><br>
        <button type="submit" class="btn-primary">Сохранить режим</button>
    </form>
</div>

<div class="card">
    <h3 class="dashboard-title">🔗 Социальная Авторизация</h3>
    <form method="post" action="/api.php">
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
    <form method="post" action="/api.php">
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
    <form method="post" action="/api.php">
        <input type="hidden" name="action" value="update_settings">
        <label for="stream_url" style="display: block; margin-bottom: 8px; font-weight: bold;">Ссылка на стрим (iframe src):</label>
        <div style="display: flex; gap: 10px;">
            <input type="text" id="stream_url" name="stream_url" value="<?= htmlspecialchars($arResult['currentStreamUrl']) ?>" style="flex: 1;" required>
            <button type="submit" class="btn-primary">Сохранить</button>
        </div>
        <p style="color: #666; font-size: 0.9em; margin-top: 8px;">
            Например: <code>https://goodgame.ru/player?161438#autoplay</code> или <code>https://player.twitch.tv/?channel=...</code>
        </p>
    </form>
</div>
