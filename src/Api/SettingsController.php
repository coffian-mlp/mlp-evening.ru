<?php

namespace Api;

use Infra\ConfigManager;

/**
 * Обработчики API-действий для глобальных настроек (MLP-255) — перенос из
 * legacy-switch api.php в тонкий роутер. Ответы — Api\Response (MLP-262);
 * роль (admin) проверяет роутер ДО вызова.
 *
 * MLP-285 (AR7-6): вместо ~50 одинаковых if-блоков — декларативная карта
 * KEYS «ключ → правило». Контракт сохранён: частичное обновление (ключ
 * пишется только при наличии в POST), невалидный enum молча пропускается,
 * невалидный URL — ошибка запроса (как в исходной ветке).
 *
 * Типы правил:
 *   'int'    — (int)-каст (флаги 0/1 и числа со знаком)
 *   'uint'   — max(0, (int)) (интервалы/лимиты)
 *   'string' — trim() (ключи, модели, промпты)
 *   'url'    — trim() + FILTER_VALIDATE_URL, при провале — fail-ответ
 *   ['enum', [...]] — значение строго из списка, иначе пропуск
 */
class SettingsController {

    private const KEYS = [
        // --- Система ---
        'debug_mode'         => 'int',
        'stream_url'         => 'url',
        'chat_mode'          => ['enum', ['local', 'none']],
        'chat_rate_limit'    => 'uint',
        'polls_create_role'  => ['enum', ['admin', 'moderator', 'all']], // MLP-238
        // --- Telegram (hidden input в форме — ключ приходит только со своей формой) ---
        'telegram_auth_enabled' => 'int',
        'telegram_bot_token'    => 'string',
        'telegram_bot_username' => 'string',
        // --- ИИ: общее ---
        'ai_bot_user_id'     => 'int',
        'ai_enabled'         => 'int',
        'ai_system_prompt'   => 'string',
        'ai_aliases'         => 'string',
        'ai_proxy_url'       => 'string',
        'ai_primary_provider' => 'string',
        // --- ИИ: провайдеры ---
        'ai_openai_key'      => 'string',
        'ai_openai_base_url' => 'string',
        'ai_openai_model'    => 'string',
        'ai_openrouter_key'  => 'string',
        'ai_openrouter_model' => 'string',
        'ai_routerai_key'    => 'string',
        'ai_routerai_model'  => 'string',
        'ai_yandex_key'      => 'string',
        'ai_yandex_folder_id' => 'string',
        'ai_gigachat_key'    => 'string',
        // --- Очередь и поведение бота (BOT-QUEUE, MLP-254/260) ---
        'ai_use_queue'       => 'int',
        'ai_worker_mode'     => ['enum', ['auto', 'cron', 'daemon', 'inline']],
        'ai_debounce_window' => 'uint',
        'ai_delay_min'       => 'uint',
        'ai_delay_max'       => 'uint',
        'ai_spam_threshold'  => 'uint',
        'ai_reply_min_gap'   => 'uint',
        'ai_worker_poll'     => 'uint',
        'ai_proactive_interval' => 'uint',
        'ai_greeting_cooldown'  => 'uint',
        'ai_context_messages'   => 'uint',
        'ai_send_images'     => 'int',
        'ai_public_base_url' => 'string',
        'ai_reactions'       => 'int',
        // --- ИИ: vision-помощник (MLP-268) и художница (MLP-274/275/276) ---
        'ai_main_is_vision'  => 'int',
        'ai_vision_provider' => ['enum', ['routerai', 'openrouter', 'openai']],
        'ai_vision_model'    => 'string',
        'ai_vision_prompt'   => 'string',
        'ai_image_model'     => 'string',
        'ai_image_daily_limit' => 'uint',
        'ai_image_style_prompt' => 'string',
        'ai_image_llm_caption'  => 'int',
        // --- SMTP ---
        'smtp_enabled'       => 'int',
        'smtp_host'          => 'string',
        'smtp_port'          => 'int',
        'smtp_user'          => 'string',
        'smtp_pass'          => 'string',
        'smtp_from_name'     => 'string',
    ];

    /** Сохранить глобальные настройки (admin). Бывший action update_settings. */
    public static function update(): void {
        $config = ConfigManager::getInstance();

        foreach (self::KEYS as $key => $rule) {
            if (!isset($_POST[$key])) {
                continue; // частичное обновление: трогаем только присланное
            }
            $raw = $_POST[$key];

            if (is_array($rule) && $rule[0] === 'enum') {
                if (in_array($raw, $rule[1], true)) {
                    $config->setOption($key, $raw);
                }
                continue; // не из списка — молча пропускаем (как раньше)
            }

            switch ($rule) {
                case 'int':
                    $config->setOption($key, (int)$raw);
                    break;
                case 'uint':
                    $config->setOption($key, max(0, (int)$raw));
                    break;
                case 'string':
                    $config->setOption($key, trim((string)$raw));
                    break;
                case 'url':
                    $url = trim((string)$raw);
                    if (!filter_var($url, FILTER_VALIDATE_URL)) {
                        Response::json(false, "❌ Некорректный формат ссылки.", 'error');
                    }
                    $config->setOption($key, $url);
                    break;
            }
        }

        Response::json(true, "✅ Настройки обновлены!");
    }
}
