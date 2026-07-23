<?php
/**
 * @var array $arResult
 */
$config = $arResult['config']; // Helper
?>
<!-- MLP-256: вариант 'ai' — секция ИИ-бота (вкладка «Бот», рядом с командами) -->
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

        <div class="form-group" style="display: flex; gap: 10px; flex-wrap: wrap;">
            <div style="flex: 1; min-width: 130px;">
                <label class="form-label">Тишина после приветствия, сек</label>
                <input type="number" name="ai_greeting_cooldown" value="<?= htmlspecialchars($config->getOption('ai_greeting_cooldown', 600)) ?>" class="form-input">
                <p style="font-size: 0.8em; color: #666; margin-top: 3px;">Повторный вход пони в этом окне — без приветствия (0 = здороваться всегда).</p>
            </div>
            <div style="flex: 1; min-width: 130px;">
                <label class="form-label">Контекст, сообщений</label>
                <input type="number" name="ai_context_messages" min="4" max="100" value="<?= htmlspecialchars($config->getOption('ai_context_messages', 24)) ?>" class="form-input">
                <p style="font-size: 0.8em; color: #666; margin-top: 3px;">Сколько последних сообщений чата видит модель (4–100). Мощным моделям можно больше.</p>
            </div>
        </div>

        <div class="form-group" style="margin-top: 10px;">
            <label style="display: flex; align-items: center; cursor: pointer;">
                <input type="hidden" name="ai_send_images" value="0">
                <input type="checkbox" name="ai_send_images" value="1" <?= $config->getOption('ai_send_images', 1) ? 'checked' : '' ?> style="width: auto; margin-right: 10px;">
                Показывать боту картинки из чата (vision)
            </label>
            <p style="font-size: 0.85em; color: #666; margin-top: 4px;">Главный выключатель. Vision-модель получает картинку напрямую (image_url); не-vision — текстовое описание от помощника (настройка ниже).</p>
        </div>

        <div class="form-group">
            <label class="form-label">Публичный базовый URL (для картинок)</label>
            <input type="text" name="ai_public_base_url" value="<?= htmlspecialchars($config->getOption('ai_public_base_url', 'https://mlp-evening.ru')) ?>" class="form-input" placeholder="https://mlp-evening.ru">
        </div>

        <!-- MLP-274: Лира-художница -->
        <div style="display: flex; gap: 15px;">
            <div class="form-group" style="flex: 2;">
                <label class="form-label">Модель генерации картинок (/нарисуй, RouterAI)</label>
                <input type="text" name="ai_image_model" value="<?= htmlspecialchars($config->getOption('ai_image_model', 'black-forest-labs/flux.2-klein-4b')) ?>" class="form-input" placeholder="black-forest-labs/flux.2-klein-4b">
                <p style="font-size: 0.8em; color: #666; margin-top: 3px;">Стиль — в поле ниже.</p>
            </div>
            <div class="form-group" style="flex: 1;">
                <label class="form-label">Лимит рисунков в день</label>
                <input type="number" name="ai_image_daily_limit" value="<?= (int)$config->getOption('ai_image_daily_limit', 20) ?>" class="form-input" min="0">
                <p style="font-size: 0.8em; color: #666; margin-top: 3px;">0 = без лимита (не советую — тролли).</p>
            </div>
        </div>

        <div class="form-group" style="margin-top: 10px;">
            <label style="display: flex; align-items: center; cursor: pointer;">
                <input type="hidden" name="ai_image_llm_caption" value="0">
                <input type="checkbox" name="ai_image_llm_caption" value="1" <?= $config->getOption('ai_image_llm_caption', 1) ? 'checked' : '' ?> style="width: auto; margin-right: 10px;">
                Живой комментарий к рисунку (Лира смотрит на результат)
            </label>
            <p style="font-size: 0.85em; color: #666; margin-top: 4px;">Vision описывает готовый рисунок, основная LLM комментирует в характере (с контекстом чата). Выключено или сбой — фиксированные подписи.</p>
        </div>

        <div class="form-group">
            <label class="form-label">Стиль-промпт художницы (пусто = встроенный «детский рисунок»)</label>
            <textarea name="ai_image_style_prompt" class="form-input" rows="2" placeholder="A naive child's crayon drawing… Subject:"><?= htmlspecialchars($config->getOption('ai_image_style_prompt', '')) ?></textarea>
            <p style="font-size: 0.8em; color: #666; margin-top: 3px;">Приставка к сюжету пользователя. Заканчивай словом «Subject:» — дальше подставится запрос.</p>
        </div>

        <!-- MLP-268: вспомогательная vision-модель, когда основная картинки не понимает -->
        <div class="form-group" style="margin-top: 10px;">
            <label style="display: flex; align-items: center; cursor: pointer;">
                <input type="hidden" name="ai_main_is_vision" value="0">
                <input type="checkbox" name="ai_main_is_vision" value="1" <?= $config->getOption('ai_main_is_vision', 1) ? 'checked' : '' ?> style="width: auto; margin-right: 10px;">
                Основная модель понимает картинки (vision)
            </label>
            <p style="font-size: 0.85em; color: #666; margin-top: 4px;">Если выключено — картинки описывает вспомогательная vision-модель (ниже), и Лира получает текстовое описание вместо изображения. Описания кешируются на 7 дней.</p>
        </div>

        <div style="display: flex; gap: 15px;">
            <div class="form-group" style="flex: 1;">
                <label class="form-label">Провайдер vision-помощника</label>
                <?php $vp = $config->getOption('ai_vision_provider', 'routerai'); ?>
                <select name="ai_vision_provider" class="form-input">
                    <option value="routerai" <?= $vp === 'routerai' ? 'selected' : '' ?>>RouterAI</option>
                    <option value="openrouter" <?= $vp === 'openrouter' ? 'selected' : '' ?>>OpenRouter</option>
                    <option value="openai" <?= $vp === 'openai' ? 'selected' : '' ?>>OpenAI-совместимый</option>
                </select>
                <p style="font-size: 0.8em; color: #666; margin-top: 3px;">Ключ берётся из настроек провайдера выше.</p>
            </div>
            <div class="form-group" style="flex: 1;">
                <label class="form-label">Модель vision-помощника</label>
                <input type="text" name="ai_vision_model" value="<?= htmlspecialchars($config->getOption('ai_vision_model', 'google/gemma-3-27b-it')) ?>" class="form-input" placeholder="google/gemma-3-27b-it">
                <p style="font-size: 0.8em; color: #666; margin-top: 3px;">Быстрая и дешёвая: gemma-3-27b-it, gemini-2.5-flash-lite, glm-4.6v.</p>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Промпт vision-помощника (пусто = встроенный)</label>
            <textarea name="ai_vision_prompt" class="form-input" rows="2" placeholder="Опиши изображение подробно и по делу…"><?= htmlspecialchars($config->getOption('ai_vision_prompt', '')) ?></textarea>
            <p style="font-size: 0.8em; color: #666; margin-top: 3px;">Простой, без характера Лиры — это техническое описание, не реплика.</p>
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
