<?php
$settings = \Domain\AnnouncementSettings::values();
$escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$firstDate = (int)$settings['announcements_not_before'] > 0
    ? (new \DateTimeImmutable('@'.$settings['announcements_not_before']))->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('Y-m-d') : '';
$events = array_filter((new \Domain\EventManager())->getAllRaw(), static fn($event) => !empty($event['use_playlist']) && !empty($event['is_recurring']) && ($event['recurrence_rule'] ?? '') === 'weekly');
$lastRun = $arResult['config']->getOption('announcements_heartbeat', '');
?>
<div class="card" id="telegram-announcements-settings">
    <h3 class="dashboard-title">Telegram-анонсы</h3>
    <p>Лира готовит два поста с картинками. Каждый пост публикуется только после твоего одобрения в личке бота.</p>
    <p>Черновики — в среду в 19:00 МСК. Основной анонс — в четверг в 19:00 МСК. Напоминание — за 3 часа до вечерка.</p>
    <form method="post" action="/api.php" id="announcement-settings-form" autocomplete="off">
        <input type="hidden" name="action" value="update_announcements">
        <div class="form-group">
            <label for="announcements_enabled"><input type="hidden" name="announcements_enabled" value="0"><input id="announcements_enabled" type="checkbox" name="announcements_enabled" value="1" <?= $settings['announcements_enabled']==='1' ? 'checked' : '' ?>> Включить Telegram-анонсы</label>
            <p>При включении проверим подключение, права в канале и личку владельца. Если проверка не пройдёт, настройки останутся прежними.</p>
        </div>
        <div class="form-group">
            <label class="form-label" for="announcements_token">Токен отдельного бота</label>
            <input id="announcements_token" type="password" name="announcements_token" class="form-input" value="" autocomplete="new-password" placeholder="<?= $settings['announcements_token']!=='' ? 'Токен сохранён' : 'Токен из BotFather' ?>">
            <p>Пустое поле сохраняет текущий токен. Бот авторизации используется отдельно.</p>
        </div>
        <div class="form-group">
            <label class="form-label" for="announcements_owner_id">Telegram ID для согласования</label>
            <input id="announcements_owner_id" type="text" inputmode="numeric" name="announcements_owner_id" class="form-input" value="<?= $escape($settings['announcements_owner_id']) ?>" pattern="[0-9]+" required>
            <p>Числовой ID твоего аккаунта, не @username. Сначала отправь /start боту в личку.</p>
        </div>
        <div class="form-group">
            <label class="form-label" for="announcements_channel">Канал для публикации</label>
            <input id="announcements_channel" type="text" name="announcements_channel" class="form-input" value="<?= $escape($settings['announcements_channel']) ?>" placeholder="@mlp_evening" required>
        </div>
        <div class="form-group">
            <label class="form-label" for="announcements_proxy_url">Прокси для Telegram</label>
            <input id="announcements_proxy_url" type="password" name="announcements_proxy_url" class="form-input" value="" autocomplete="new-password" placeholder="<?= $settings['announcements_proxy_url']!=='' ? 'Прокси сохранён' : 'socks5h://… или vless://…' ?>">
            <p>HTTP, HTTPS, SOCKS5 или VLESS. Только для Telegram; прокси запросов к LLM настраивается выше. Пустое поле сохраняет текущий прокси.</p>
            <label><input type="checkbox" name="announcements_clear_proxy" value="1"> Удалить прокси и подключаться напрямую</label>
        </div>
        <div class="form-group">
            <label class="form-label" for="announcements_event_id">Событие</label>
            <select id="announcements_event_id" name="announcements_event_id" class="form-input" required>
                <option value="0">Выбери событие</option>
                <?php foreach ($events as $event): ?>
                    <option value="<?= (int)$event['id'] ?>" <?= (int)$settings['announcements_event_id']===(int)$event['id'] ? 'selected' : '' ?>><?= $escape($event['title']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label" for="announcements_first_number">Стартовый номер вечерка</label>
            <input id="announcements_first_number" type="number" name="announcements_first_number" min="1" max="3999" class="form-input" value="<?= (int)$settings['announcements_first_number'] ?>" required>
            <p>Для первого автоматического анонса. После подготовки первого вечерка нумерация продолжается автоматически; изменение этого поля её не сбрасывает.</p>
        </div>
        <div class="form-group">
            <label class="form-label" for="announcements_first_date">Первый вечерок с автоматическим анонсом</label>
            <input id="announcements_first_date" type="date" name="announcements_first_date" class="form-input" value="<?= $escape($firstDate) ?>" required>
            <p>Более ранние вечерки пропускаются. Дата по Москве.</p>
        </div>
        <button type="submit" class="btn btn-primary">Сохранить настройки анонсов</button>
    </form>
    <p><?= $lastRun!=='' ? 'Последняя завершённая проверка: '.$escape($lastRun).' UTC.' : 'Воркер ещё не завершал проверку.' ?></p>
</div>
