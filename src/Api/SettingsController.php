<?php

namespace Api;

use Infra\ConfigManager;

/**
 * Обработчики API-действий для глобальных настроек (MLP-255) — перенос из
 * legacy-switch api.php в тонкий роутер. Ответы — Api\Response (MLP-262);
 * (определена в api.php); роль (admin) проверяет роутер ДО вызова.
 *
 * Контракт сохранён: частичное обновление — каждый ключ пишется только при
 * наличии в POST; whitelist-ы значений и касты — как в исходной ветке.
 */
class SettingsController {

    /** Сохранить глобальные настройки (admin). Бывший action update_settings. */
    public static function update(): void {
        $config = ConfigManager::getInstance();

        // --- System Settings ---
        if (isset($_POST['debug_mode'])) {
            $config->setOption('debug_mode', (int)$_POST['debug_mode']);
        }

        if (isset($_POST['stream_url'])) {
            $url = trim($_POST['stream_url']);
            // Простейшая валидация
            if (filter_var($url, FILTER_VALIDATE_URL)) {
                $config->setOption('stream_url', $url);
                // Не возвращаем сразу, вдруг еще настройки есть
            } else {
                Response::json(false, "❌ Некорректный формат ссылки.", 'error');
            }
        }

        if (isset($_POST['chat_mode'])) {
            $mode = $_POST['chat_mode'];
            $validModes = ['local', 'none'];
            if (in_array($mode, $validModes)) {
                $config->setOption('chat_mode', $mode);
            }
        }

        if (isset($_POST['chat_rate_limit'])) {
            $limit = (int)$_POST['chat_rate_limit'];
            if ($limit < 0) $limit = 0;
            $config->setOption('chat_rate_limit', $limit);
        }

        // Кто может создавать опросы (MLP-238)
        if (isset($_POST['polls_create_role'])) {
            $pr = $_POST['polls_create_role'];
            if (in_array($pr, ['admin', 'moderator', 'all'], true)) {
                $config->setOption('polls_create_role', $pr);
            }
        }

        // Telegram Settings
        // В форме есть hidden input, так что ключ всегда придет, если это форма Telegram.
        // Если сохраняем другую форму, ключа не будет, и настройку не трогаем.
        if (isset($_POST['telegram_auth_enabled'])) {
            $config->setOption('telegram_auth_enabled', (int)$_POST['telegram_auth_enabled']);
        }

        if (isset($_POST['telegram_bot_token'])) {
            $config->setOption('telegram_bot_token', trim($_POST['telegram_bot_token']));
        }
        if (isset($_POST['telegram_bot_username'])) {
            $config->setOption('telegram_bot_username', trim($_POST['telegram_bot_username']));
        }

        // AI Settings
        if (isset($_POST['ai_bot_user_id'])) {
            $config->setOption('ai_bot_user_id', (int)$_POST['ai_bot_user_id']);
        }
        if (isset($_POST['ai_enabled'])) {
            $config->setOption('ai_enabled', (int)$_POST['ai_enabled']);
        }
        if (isset($_POST['ai_system_prompt'])) {
            $config->setOption('ai_system_prompt', trim($_POST['ai_system_prompt']));
        }
        if (isset($_POST['ai_aliases'])) {
            $config->setOption('ai_aliases', trim($_POST['ai_aliases']));
        }
        if (isset($_POST['ai_proxy_url'])) {
            $config->setOption('ai_proxy_url', trim($_POST['ai_proxy_url']));
        }
        if (isset($_POST['ai_primary_provider'])) {
            $config->setOption('ai_primary_provider', trim($_POST['ai_primary_provider']));
        }

        // AI Providers
        if (isset($_POST['ai_openai_key'])) {
            $config->setOption('ai_openai_key', trim($_POST['ai_openai_key']));
        }
        if (isset($_POST['ai_openai_base_url'])) {
            $config->setOption('ai_openai_base_url', trim($_POST['ai_openai_base_url']));
        }
        if (isset($_POST['ai_openai_model'])) {
            $config->setOption('ai_openai_model', trim($_POST['ai_openai_model']));
        }

        if (isset($_POST['ai_openrouter_key'])) {
            $config->setOption('ai_openrouter_key', trim($_POST['ai_openrouter_key']));
        }
        if (isset($_POST['ai_openrouter_model'])) {
            $config->setOption('ai_openrouter_model', trim($_POST['ai_openrouter_model']));
        }

        if (isset($_POST['ai_routerai_key'])) {
            $config->setOption('ai_routerai_key', trim($_POST['ai_routerai_key']));
        }
        if (isset($_POST['ai_routerai_model'])) {
            $config->setOption('ai_routerai_model', trim($_POST['ai_routerai_model']));
        }

        if (isset($_POST['ai_yandex_key'])) {
            $config->setOption('ai_yandex_key', trim($_POST['ai_yandex_key']));
        }
        if (isset($_POST['ai_yandex_folder_id'])) {
            $config->setOption('ai_yandex_folder_id', trim($_POST['ai_yandex_folder_id']));
        }

        if (isset($_POST['ai_gigachat_key'])) {
            $config->setOption('ai_gigachat_key', trim($_POST['ai_gigachat_key']));
        }

        // --- Очередь и поведение бота (BOT-QUEUE) ---
        if (isset($_POST['ai_use_queue'])) {
            $config->setOption('ai_use_queue', (int)$_POST['ai_use_queue']);
        }
        if (isset($_POST['ai_worker_mode'])) {
            $mode = trim($_POST['ai_worker_mode']);
            if (in_array($mode, ['auto', 'cron', 'daemon', 'inline'], true)) {
                $config->setOption('ai_worker_mode', $mode);
            }
        }
        // MLP-260: + ai_greeting_cooldown (MLP-254) и ai_context_messages — теперь настраиваются из админки
        foreach (['ai_debounce_window', 'ai_delay_min', 'ai_delay_max', 'ai_spam_threshold', 'ai_reply_min_gap', 'ai_worker_poll', 'ai_proactive_interval', 'ai_greeting_cooldown', 'ai_context_messages'] as $k) {
            if (isset($_POST[$k])) {
                $config->setOption($k, max(0, (int)$_POST[$k]));
            }
        }
        if (isset($_POST['ai_send_images'])) {
            $config->setOption('ai_send_images', (int)$_POST['ai_send_images']);
        }
        if (isset($_POST['ai_public_base_url'])) {
            $config->setOption('ai_public_base_url', trim($_POST['ai_public_base_url']));
        }
        // MLP-268: вспомогательная vision-модель
        if (isset($_POST['ai_main_is_vision'])) {
            $config->setOption('ai_main_is_vision', (int)$_POST['ai_main_is_vision']);
        }
        if (isset($_POST['ai_vision_provider'])) {
            $vp = $_POST['ai_vision_provider'];
            if (in_array($vp, ['routerai', 'openrouter', 'openai'], true)) {
                $config->setOption('ai_vision_provider', $vp);
            }
        }
        if (isset($_POST['ai_vision_model'])) {
            $config->setOption('ai_vision_model', trim($_POST['ai_vision_model']));
        }
        // MLP-274: художница
        if (isset($_POST['ai_image_model'])) {
            $config->setOption('ai_image_model', trim($_POST['ai_image_model']));
        }
        if (isset($_POST['ai_image_daily_limit'])) {
            $config->setOption('ai_image_daily_limit', max(0, (int)$_POST['ai_image_daily_limit']));
        }
        // MLP-275: промпты vision-помощника и художницы — из настроек
        if (isset($_POST['ai_vision_prompt'])) {
            $config->setOption('ai_vision_prompt', trim($_POST['ai_vision_prompt']));
        }
        if (isset($_POST['ai_image_style_prompt'])) {
            $config->setOption('ai_image_style_prompt', trim($_POST['ai_image_style_prompt']));
        }
        if (isset($_POST['ai_reactions'])) {
            $config->setOption('ai_reactions', (int)$_POST['ai_reactions']);
        }

        // SMTP Settings
        if (isset($_POST['smtp_enabled'])) {
            $config->setOption('smtp_enabled', (int)$_POST['smtp_enabled']);
        }
        if (isset($_POST['smtp_host'])) {
            $config->setOption('smtp_host', trim($_POST['smtp_host']));
        }
        if (isset($_POST['smtp_port'])) {
            $config->setOption('smtp_port', (int)$_POST['smtp_port']);
        }
        if (isset($_POST['smtp_user'])) {
            $config->setOption('smtp_user', trim($_POST['smtp_user']));
        }
        if (isset($_POST['smtp_pass'])) {
            $config->setOption('smtp_pass', trim($_POST['smtp_pass']));
        }
        if (isset($_POST['smtp_from_name'])) {
            $config->setOption('smtp_from_name', trim($_POST['smtp_from_name']));
        }

        Response::json(true, "✅ Настройки обновлены!");
    }
}
