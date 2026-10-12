<?php
$settings = \Domain\AnnouncementSettings::values();
$escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$events = array_filter((new \Domain\EventManager())->getAllRaw(), static fn($event) => !empty($event['use_playlist']) && !empty($event['is_recurring']) && ($event['recurrence_rule'] ?? '') === 'weekly');
$lastRun = $arResult['config']->getOption('announcements_heartbeat', '');
?>
<div class="card" id="telegram-announcements-settings">
    <h3 class="dashboard-title">Настройки анонсов</h3>
    <p>Лира готовит два поста с картинками. Каждый пост публикуется только после твоего одобрения в личке бота.</p>
    <p>Одна еженедельная серия связана с событием календаря. Сроки рассчитываются заново для каждого показа.</p>
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
            <label class="form-label" for="announcements_next_number">Номер следующего вечерка</label>
            <input id="announcements_next_number" type="number" name="announcements_next_number" min="1" max="3999" class="form-input" value="<?= (int)$settings['announcements_next_number'] ?>" required>
            <p>Общий номер для основного поста и напоминания. После создания следующего вечерка увеличивается автоматически. Номера уже созданных постов не меняются.</p>
        </div>
        <details class="announcement-details" open>
            <summary>Расписание подготовки и публикации <span aria-hidden="true">▾</span></summary>
            <div class="form-group">
                <label class="form-label" for="announcements_timezone">Часовой пояс расписания анонсов</label>
                <select id="announcements_timezone" name="announcements_timezone" class="form-input">
                    <?php foreach (\DateTimeZone::listIdentifiers() as $zone): ?><option value="<?= $escape($zone) ?>" <?= $zone===$settings['announcements_timezone']?'selected':'' ?>><?= $escape($zone) ?></option><?php endforeach; ?>
                </select>
                <p>Календарь хранит конкретное время показа. Этот пояс определяет время подготовки и публикации; в тексте поста время показа указывается по Москве.</p>
            </div>
            <?php foreach (['prepare'=>'Черновики в дашборде и личке бота','main'=>'Основной пост в канале'] as $kind=>$label): ?>
                <fieldset class="announcement-schedule-row">
                    <legend><?= $label ?></legend>
                    <label class="form-label">За сколько дней до события<input type="number" class="form-input" min="0" max="6" required name="announcements_<?= $kind ?>_days" value="<?= $escape($settings['announcements_'.$kind.'_days']) ?>"></label>
                    <label class="form-label">Время<input type="time" class="form-input" required name="announcements_<?= $kind ?>_time" value="<?= $escape($settings['announcements_'.$kind.'_time']) ?>"></label>
                </fieldset>
            <?php endforeach; ?>
            <label class="form-label">Напоминание: за сколько минут до начала события<input type="number" class="form-input" required min="1" max="10080" name="announcements_reminder_minutes" value="<?= $escape($settings['announcements_reminder_minutes']) ?>"></label>
            <p>Для одной еженедельной серии выбирай сроки в пределах недели (0–6 дней). Черновики должны быть раньше основного поста, затем — напоминание, затем — событие. Изменение сроков сохраняет тексты и картинки в новой версии и снимает прежнее одобрение. Старые кнопки согласования перестают действовать.</p>
        </details>
        <details id="announcement-generation-settings" class="announcement-details">
            <summary>Промпты и контекст генерации — раскрыть настройки <span aria-hidden="true">▾</span></summary>
            <p>Сначала модель получает нейтральные резюме серий, затем Лира пишет пост со своим характером и памятью. Правки применятся при следующей генерации; готовые черновики сохраняются до переделки в боте.</p>
            <?php foreach (['main'=>'Основной анонс','reminder'=>'Напоминание'] as $kind=>$label): ?>
                <div class="form-group">
                    <label class="form-label" for="announcements_<?= $kind ?>_chars"><?= $label ?>: целевая длина текста</label>
                    <input class="form-input" id="announcements_<?= $kind ?>_chars" name="announcements_<?= $kind ?>_chars" type="number" min="200" max="900" required value="<?= $escape($settings['announcements_'.$kind.'_chars']) ?>">
                </div>
            <?php endforeach; ?>
            <p>Целевая длина задаёт ориентир модели. Подпись вместе с номером всегда проверяется на лимит Telegram.</p>
            <?php foreach (['synopsis'=>'Резюме серий: нейтральный первый этап','common'=>'Общие инструкции для текстов Лиры','main'=>'Основной анонс','reminder'=>'Напоминание','image'=>'Изображения для анонсов'] as $key=>$label): ?>
                <div class="form-group">
                    <label class="form-label" for="announcements_prompt_<?= $key ?>"><?= $label ?></label>
                    <p><?php $help=[
                        'synopsis'=>'Этап 1, нейтральный. Получает названия и ID серий, проверенные синопсисы или источники поиска и эту инструкцию. Общий системный промпт Лиры, её память и чат сюда не передаются. Результат — проверенные резюме для следующего этапа.',
                        'common'=>'Этап 2, общий для обоих постов. Дополняет основной системный промпт Лиры. Получает резюме, дату и время события, ночной показ, сообщения чата прошлого вечерка и общую память сообщества. Можно описать дебют по признаку is_first_publication; отдельного промпта первого поста нет.',
                        'main'=>'Этап 2, только основной пост. Используется вместе с общими инструкциями выше и характером Лиры. Задаёт структуру и подробность основного анонса.',
                        'reminder'=>'Этап 2, только напоминание. Тот же контекст, дополнительно reminder_minutes — срок до начала из настроек. Не фиксируй в промпте конкретные часы и даты.',
                        'image'=>'Изображения. Получает названия эпизодов, описание события и замечания владельца. Применяется общий художественный стиль Лиры. Системный промпт общения, память и чат сюда не передаются.'
                    ]; echo $escape($help[$key]); ?></p>
                    <textarea class="form-input" style="width:100%;box-sizing:border-box;resize:vertical" rows="5" maxlength="6000" required id="announcements_prompt_<?= $key ?>" name="announcements_prompt_<?= $key ?>"><?= $escape($settings['announcements_prompt_'.$key]) ?></textarea>
                </div>
            <?php endforeach; ?>
            <p>Дата, ссылка, номер, проверка источников и согласование определяются кодом. Для рисунков дополнительно применяется общий художественный стиль Лиры из настроек рисования.</p>
        </details>
        <button type="submit" class="btn btn-primary">Сохранить настройки анонсов</button>
    </form>
    <p><?= $lastRun!=='' ? 'Последняя завершённая проверка: '.$escape($lastRun).' UTC.' : 'Воркер ещё не завершал проверку.' ?></p>
</div>
