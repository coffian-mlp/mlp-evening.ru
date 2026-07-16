<?php
/**
 * @var array $arResult
 */
$config = $arResult['config']; // Helper
?>
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
    <h3 class="dashboard-title">🧠 Настройки ИИ Бота (Живая Пони)</h3>
    <form method="post" action="/api.php">
        <input type="hidden" name="action" value="update_settings">
        
        <div class="form-group">
            <label style="display: flex; align-items: center; cursor: pointer;">
                <input type="hidden" name="ai_enabled" value="0">
                <input type="checkbox" name="ai_enabled" value="1" <?= $config->getOption('ai_enabled', 0) ? 'checked' : '' ?> style="width: auto; margin-right: 10px;">
                <strong>Включить ИИ-бота в чате</strong>
            </label>
        </div>

        <div class="form-group">
            <label class="form-label">Системный Промпт (Характер)</label>
            <textarea name="ai_system_prompt" class="form-input" rows="4"><?= htmlspecialchars($config->getOption('ai_system_prompt', 'Ты — Лира Хартстрингс, мятная единорожка из Понивилля...')) ?></textarea>
        </div>

        <div class="form-group">
            <label class="form-label">Имена-упоминания (через запятую)</label>
            <input type="text" name="ai_aliases" value="<?= htmlspecialchars($config->getOption('ai_aliases', 'лира, lyra, хартстрингс, lyra heartstrings, лирочка')) ?>" class="form-input" placeholder="Например: лира, lyra, хартстрингс">
            <p style="font-size: 0.85em; color: #666; margin-top: 4px;">На эти слова бот будет откликаться без @. Работает без учета регистра.</p>
        </div>

        <div class="form-group">
            <label class="form-label">ID Пользователя Бота</label>
            <input type="number" name="ai_bot_user_id" value="<?= htmlspecialchars($config->getOption('ai_bot_user_id', '')) ?>" class="form-input" placeholder="ID пользователя (например, 2)">
            <p style="font-size: 0.85em; color: #666; margin-top: 4px;">От имени этого пользователя бот будет писать в чат.</p>
        </div>

        <div class="form-group">
            <label class="form-label">Прокси (socks5://... или vless://...)</label>
            <input type="text" name="ai_proxy_url" value="<?= htmlspecialchars($config->getOption('ai_proxy_url', '')) ?>" class="form-input" placeholder="Необязательно. Если vless - скачай xray в src/LLM/bin">
        </div>

        <hr style="margin: 15px 0; border: none; border-top: 1px solid #eee;">

        <h4>Провайдер ИИ</h4>
        <p style="font-size: 0.85em; color: #666; margin-bottom: 15px;">Выберите основного провайдера. Остальные (если у них заполнены ключи) будут работать как запасные, если основной не ответит.</p>
        
        <div class="form-group">
            <select name="ai_primary_provider" class="form-input" onchange="document.querySelectorAll('.ai-provider-group').forEach(el => el.style.display = 'none'); document.getElementById('ai_group_' + this.value).style.display = 'block';">
                <option value="openai" <?= $config->getOption('ai_primary_provider', 'openai') === 'openai' ? 'selected' : '' ?>>OpenAI / GitHub Models / Groq</option>
                <option value="openrouter" <?= $config->getOption('ai_primary_provider', 'openai') === 'openrouter' ? 'selected' : '' ?>>OpenRouter</option>
                <option value="routerai" <?= $config->getOption('ai_primary_provider', 'openai') === 'routerai' ? 'selected' : '' ?>>RouterAI (routerai.ru)</option>
                <option value="yandex" <?= $config->getOption('ai_primary_provider', 'openai') === 'yandex' ? 'selected' : '' ?>>YandexGPT</option>
                <option value="gigachat" <?= $config->getOption('ai_primary_provider', 'openai') === 'gigachat' ? 'selected' : '' ?>>GigaChat</option>
            </select>
        </div>

        <div id="ai_group_openai" class="ai-provider-group" <?= $config->getOption('ai_primary_provider', 'openai') === 'openai' ? '' : 'style="display:none;"' ?>>
            <div class="form-group">
                <label class="form-label">OpenAI API Key (или GitHub Models)</label>
                <input type="password" name="ai_openai_key" value="<?= htmlspecialchars($config->getOption('ai_openai_key', '')) ?>" class="form-input">
            </div>
            <div class="form-group" style="display: flex; gap: 10px;">
                <div style="flex: 1;">
                    <label class="form-label">OpenAI Base URL</label>
                    <input type="text" name="ai_openai_base_url" value="<?= htmlspecialchars($config->getOption('ai_openai_base_url', 'https://api.openai.com/v1/chat/completions')) ?>" class="form-input">
                </div>
                <div style="flex: 1;">
                    <label class="form-label">Модель OpenAI</label>
                    <input type="text" name="ai_openai_model" value="<?= htmlspecialchars($config->getOption('ai_openai_model', 'gpt-4o-mini')) ?>" class="form-input">
                </div>
            </div>
        </div>

        <div id="ai_group_openrouter" class="ai-provider-group" <?= $config->getOption('ai_primary_provider', 'openai') === 'openrouter' ? '' : 'style="display:none;"' ?>>
            <div class="form-group">
                <label class="form-label">OpenRouter API Key</label>
                <input type="password" name="ai_openrouter_key" value="<?= htmlspecialchars($config->getOption('ai_openrouter_key', '')) ?>" class="form-input">
            </div>
            <div class="form-group">
                <label class="form-label">OpenRouter Модель</label>
                <input type="text" name="ai_openrouter_model" value="<?= htmlspecialchars($config->getOption('ai_openrouter_model', 'qwen/qwen3-coder:free')) ?>" class="form-input">
            </div>
        </div>

        <div id="ai_group_routerai" class="ai-provider-group" <?= $config->getOption('ai_primary_provider', 'openai') === 'routerai' ? '' : 'style="display:none;"' ?>>
            <div class="form-group">
                <label class="form-label">RouterAI API Key</label>
                <input type="password" name="ai_routerai_key" value="<?= htmlspecialchars($config->getOption('ai_routerai_key', '')) ?>" class="form-input">
                <p style="font-size: 0.85em; color: #666; margin-top: 4px;">Российский агрегатор (routerai.ru), OpenAI-совместимый. Не блокируется по гео, прокси не нужен.</p>
            </div>
            <div class="form-group">
                <label class="form-label">RouterAI Модель</label>
                <input type="text" name="ai_routerai_model" value="<?= htmlspecialchars($config->getOption('ai_routerai_model', 'openai/gpt-4o-mini')) ?>" class="form-input">
            </div>
        </div>

        <div id="ai_group_yandex" class="ai-provider-group" <?= $config->getOption('ai_primary_provider', 'openai') === 'yandex' ? '' : 'style="display:none;"' ?>>
            <div class="form-group">
                <label class="form-label">YandexGPT API Key</label>
                <input type="password" name="ai_yandex_key" value="<?= htmlspecialchars($config->getOption('ai_yandex_key', '')) ?>" class="form-input">
                <label class="form-label" style="margin-top: 5px;">Yandex Folder ID или Model URI</label>
                <input type="text" name="ai_yandex_folder_id" value="<?= htmlspecialchars($config->getOption('ai_yandex_folder_id', '')) ?>" class="form-input" placeholder="b1g... или gpt://...">
                <p style="font-size: 0.85em; color: #666; margin-top: 4px;">Можно указать просто Folder ID (тогда будет использована модель по умолчанию) или полную ссылку на модель, например: <code>gpt://b1g.../deepseek-v32/latest</code></p>
            </div>
        </div>

        <div id="ai_group_gigachat" class="ai-provider-group" <?= $config->getOption('ai_primary_provider', 'openai') === 'gigachat' ? '' : 'style="display:none;"' ?>>
            <div class="form-group">
                <label class="form-label">GigaChat Auth Key</label>
                <input type="password" name="ai_gigachat_key" value="<?= htmlspecialchars($config->getOption('ai_gigachat_key', '')) ?>" class="form-input">
            </div>
        </div>

        <h4 style="margin-top: 25px;">Очередь и поведение бота</h4>
        <p style="font-size: 0.85em; color: #666; margin-bottom: 15px;">Ответы через фоновый воркер (не блокирует чат) + анти-спам. Если выключено — прежнее поведение (ответ прямо в запросе).</p>

        <div class="form-group">
            <label style="display: flex; align-items: center; cursor: pointer;">
                <input type="hidden" name="ai_use_queue" value="0">
                <input type="checkbox" name="ai_use_queue" value="1" <?= $config->getOption('ai_use_queue', 0) ? 'checked' : '' ?> style="width: auto; margin-right: 10px;">
                Использовать очередь и воркер
            </label>
        </div>

        <div class="form-group">
            <label class="form-label">Режим воркера</label>
            <?php $wm = $config->getOption('ai_worker_mode', 'auto'); ?>
            <select name="ai_worker_mode" class="form-input">
                <option value="auto"   <?= $wm === 'auto'   ? 'selected' : '' ?>>auto — очередь, inline-фоллбек если нет воркера</option>
                <option value="cron"   <?= $wm === 'cron'   ? 'selected' : '' ?>>cron — воркер по расписанию</option>
                <option value="daemon" <?= $wm === 'daemon' ? 'selected' : '' ?>>daemon — постоянный процесс</option>
                <option value="inline" <?= $wm === 'inline' ? 'selected' : '' ?>>inline — без очереди, в запросе</option>
            </select>
        </div>

        <div class="form-group" style="display: flex; gap: 10px; flex-wrap: wrap;">
            <div style="flex: 1; min-width: 130px;">
                <label class="form-label">Задержка ответа, сек (мин)</label>
                <input type="number" name="ai_delay_min" value="<?= htmlspecialchars($config->getOption('ai_delay_min', 4)) ?>" class="form-input">
            </div>
            <div style="flex: 1; min-width: 130px;">
                <label class="form-label">Задержка ответа, сек (макс)</label>
                <input type="number" name="ai_delay_max" value="<?= htmlspecialchars($config->getOption('ai_delay_max', 42)) ?>" class="form-input">
            </div>
            <div style="flex: 1; min-width: 130px;">
                <label class="form-label">Окно дебаунса, сек</label>
                <input type="number" name="ai_debounce_window" value="<?= htmlspecialchars($config->getOption('ai_debounce_window', 10)) ?>" class="form-input">
            </div>
        </div>

        <div class="form-group" style="display: flex; gap: 10px; flex-wrap: wrap;">
            <div style="flex: 1; min-width: 130px;">
                <label class="form-label">Порог спама (упоминаний)</label>
                <input type="number" name="ai_spam_threshold" value="<?= htmlspecialchars($config->getOption('ai_spam_threshold', 4)) ?>" class="form-input">
            </div>
            <div style="flex: 1; min-width: 130px;">
                <label class="form-label">Пауза между ответами, сек</label>
                <input type="number" name="ai_reply_min_gap" value="<?= htmlspecialchars($config->getOption('ai_reply_min_gap', 20)) ?>" class="form-input">
            </div>
            <div style="flex: 1; min-width: 130px;">
                <label class="form-label">Опрос воркера, сек</label>
                <input type="number" name="ai_worker_poll" value="<?= htmlspecialchars($config->getOption('ai_worker_poll', 3)) ?>" class="form-input">
            </div>
            <div style="flex: 1; min-width: 130px;">
                <label class="form-label">Интервал проактива, сек</label>
                <input type="number" name="ai_proactive_interval" value="<?= htmlspecialchars($config->getOption('ai_proactive_interval', 240)) ?>" class="form-input">
            </div>
        </div>

        <div class="form-group" style="margin-top: 10px;">
            <label style="display: flex; align-items: center; cursor: pointer;">
                <input type="hidden" name="ai_send_images" value="0">
                <input type="checkbox" name="ai_send_images" value="1" <?= $config->getOption('ai_send_images', 1) ? 'checked' : '' ?> style="width: auto; margin-right: 10px;">
                Показывать боту картинки из чата (vision)
            </label>
            <p style="font-size: 0.85em; color: #666; margin-top: 4px;">Ссылки на вложения-картинки отправляются модели как image_url. Работает только с vision-моделями (напр. gemini). Требует, чтобы картинки были доступны по публичному URL.</p>
        </div>

        <div class="form-group">
            <label class="form-label">Публичный базовый URL (для картинок)</label>
            <input type="text" name="ai_public_base_url" value="<?= htmlspecialchars($config->getOption('ai_public_base_url', 'https://mlp-evening.ru')) ?>" class="form-input" placeholder="https://mlp-evening.ru">
        </div>

        <div class="form-group" style="margin-top: 10px;">
            <label style="display: flex; align-items: center; cursor: pointer;">
                <input type="hidden" name="ai_reactions" value="0">
                <input type="checkbox" name="ai_reactions" value="1" <?= $config->getOption('ai_reactions', 1) ? 'checked' : '' ?> style="width: auto; margin-right: 10px;">
                Разрешить боту ставить реакции на сообщения
            </label>
            <p style="font-size: 0.85em; color: #666; margin-top: 4px;">Лира сможет реагировать (❤️ 😂 🔥 и др.) на сообщение, которому отвечает — вместо или вместе с текстом.</p>
        </div>

        <button type="submit" class="btn-primary">Сохранить ИИ настройки</button>
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

<div class="card">
    <h3 class="dashboard-title">🗳️ Голосование (Ручной режим)</h3>
    <p>Если нужно добавить голос за конкретную серию вручную:</p>
    
    <form method="post" action="/api.php" style="margin-top: 15px;">
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
        <form method="post" action="/api.php">
            <input type="hidden" name="action" value="clear_votes">
            <button type="submit" class="btn-danger" onclick="return confirm('Точно сбросить все голоса (WANNA_WATCH)?')">🗑️ Сбросить голоса</button>
        </form>

        <form method="post" action="/api.php">
            <input type="hidden" name="action" value="reset_times_watched">
            <button type="submit" class="btn-danger" onclick="return confirm('Точно сбросить счетчики просмотров? Все серии снова станут непросмотренными!')">🔄 Сбросить просмотры</button>
        </form>
    </div>
</div>
