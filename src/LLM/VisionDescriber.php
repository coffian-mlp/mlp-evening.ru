<?php

namespace LLM;

use Core\FileCache;
use Infra\ConfigManager;

/**
 * Вспомогательная vision-модель (MLP-268): когда ОСНОВНАЯ модель бота не
 * понимает картинки (ai_main_is_vision=0), изображения из последних сообщений
 * описываются отдельной быстрой vision-моделью, и в контекст основной LLM
 * вместо markdown-картинки подставляется текст «[Картинка: …]».
 *
 * Промпт помощника намеренно простой, без характера Лиры; ответ жёстко
 * обрезается. Описания кешируются (cache/vision/<md5(url)>.json, TTL 7 дней);
 * сбойные/пустые НЕ кешируются — попробуем в следующий раз.
 * Ошибка помощника не роняет ответ бота: картинка остаётся ссылкой, как раньше.
 */
class VisionDescriber {

    const MAX_IMAGES = VisionFormatter::MAX_IMAGES;   // тот же бюджет, что у мультимодального пути
    const RECENT_MSGS = VisionFormatter::RECENT_MSGS; // и то же окно свежести
    const CACHE_TTL = 604800; // 7 дней
    /** MLP-357: картинку, которую провайдер отверг (4xx, код 1210), час не пробуем снова. */
    const FAIL_TTL = 3600;
    const MAX_LEN = 600;      // жёсткий потолок длины описания, символов

    const PROMPT = 'Опиши изображение подробно и по делу: что изображено, какой текст виден (процитируй), обстановка. 2–4 предложения на русском. Без вступлений, оценок и вопросов.';

    /**
     * Заменяет markdown-картинки последних RECENT_MSGS сообщений текстовыми
     * описаниями (от новых к старым, бюджет MAX_IMAGES). Сообщения с
     * не-строковым content (мультимодальные) не трогаются — это же исключает
     * рекурсию при вызове самого помощника через общие провайдеры.
     */
    public static function maybeDescribe(array $messages, ?callable $model = null, ?FileCache $cache = null): array {
        $budget = self::MAX_IMAGES;
        $n = count($messages);
        $from = max(0, $n - self::RECENT_MSGS);
        for ($i = $n - 1; $i >= $from && $budget > 0; $i--) {
            $content = $messages[$i]['content'] ?? '';
            if (!is_string($content) || $content === '') {
                continue;
            }
            $messages[$i]['content'] = preg_replace_callback(
                '/!\[([^\]]*)\]\(([^)\s]+)\)/u',
                function ($m) use (&$budget, $model, $cache) {
                    if ($budget <= 0) {
                        return $m[0];
                    }
                    $desc = self::describe(trim($m[2]), $model, $cache);
                    if ($desc === null) {
                        return $m[0]; // не смогли — оставляем markdown как раньше
                    }
                    $budget--;
                    // MLP-292: alt «стикер …» приходит из expandStickers — метим отдельно,
                    // чтобы Лира отличала стикер от присланной картинки.
                    $label = (mb_stripos($m[1], 'стикер') === 0) ? 'Стикер' : 'Картинка';
                    return '[' . $label . ': ' . $desc . ']';
                },
                $content
            );
        }
        return $messages;
    }

    /** Описание картинки: кеш → vision-модель. null = не смогли (НЕ кешируется). */
    public static function describe(string $url, ?callable $model = null, ?FileCache $cache = null): ?string {
        if (!VisionFormatter::isImageUrl($url)) {
            return null; // не картинка — без обращений к кешу/конфигу (pure-гейт)
        }

        $cache = $cache ?? new FileCache('vision');
        $key = md5($url);

        $hit = $cache->get($key, self::CACHE_TTL);
        if ($hit !== null && isset($hit['d']) && $hit['d'] !== '') {
            return $hit['d'];
        }
        // MLP-357: недавно провайдер отверг эту картинку — не повторяем на каждой сборке контекста.
        if ($cache->get($key . '_fail', self::FAIL_TTL) !== null) {
            return null;
        }

        // Превью готовим только на живом пути (ConfigManager→БД); с инжектированной
        // моделью (тесты) отдаём URL как есть — resolveForModel там не нужен.
        $imagePart = ($model !== null) ? $url : VisionFormatter::resolveForModel($url);
        if ($imagePart === null) {
            return null; // не смогли подготовить превью
        }

        $model = $model ?? [self::class, 'callModel'];
        try {
            $desc = $model($imagePart);
        } catch (\Throwable $e) {
            error_log('VisionDescriber: ' . get_class($e) . ': ' . $e->getMessage());
            if (self::isPermanentFailure($e->getMessage())) {
                $cache->set($key . '_fail', ['fail' => time(), 'url' => $url, 'why' => mb_substr($e->getMessage(), 0, 200)]);
            }
            return null;
        }

        $desc = trim((string)$desc);
        if ($desc === '') {
            return null; // пустое не кешируем — попробуем в следующий раз
        }
        if (mb_strlen($desc) > self::MAX_LEN) {
            $desc = mb_substr($desc, 0, self::MAX_LEN - 1) . '…';
        }

        $cache->set($key, ['d' => $desc, 'url' => $url]);
        return $desc;
    }

    /** Живой вызов vision-модели через существующие OpenAI-совместимые провайдеры. */
    /**
     * Разовый vision-вызов с собственным промптом и без кеша (MLP-337: /яос с картинкой).
     * null — провайдер не настроен, картинка не разрешилась или сбой.
     */
    public static function describeWith(string $url, string $prompt): ?string {
        $imagePart = VisionFormatter::resolveForModel($url);
        if ($imagePart === null) return null;
        try {
            $out = self::callModel($imagePart, $prompt);
        } catch (\Throwable $e) {
            error_log('VisionDescriber::describeWith: ' . $e->getMessage());
            return null;
        }
        $out = trim((string)$out);
        return $out !== '' ? $out : null;
    }

    private static function callModel(string $imagePart, ?string $promptOverride = null): ?string {
        $c = ConfigManager::getInstance();
        $providerKey = (string)$c->getOption('ai_vision_provider', 'routerai');
        $modelName = (string)$c->getOption('ai_vision_model', 'google/gemma-3-27b-it');
        // MLP-275: промпт помощника настраивается из дашборда (пустой = дефолт). MLP-337: явный промпт вызова — приоритетнее.
        $prompt = $promptOverride ?? (trim((string)$c->getOption('ai_vision_prompt', '')) ?: self::PROMPT);

        // socks5-прокси поддерживаем; vless — нет (помощник по умолчанию на RouterAI, ему прокси не нужен).
        $proxy = (string)$c->getOption('ai_proxy_url', '');
        $proxy = (strpos($proxy, 'socks5') === 0) ? str_replace('socks5://', 'socks5h://', $proxy) : null;

        $provider = null;
        if ($providerKey === 'routerai' && ($k = $c->getOption('ai_routerai_key', ''))) {
            $provider = new RouterAIProvider($k, $modelName, null);
        } elseif ($providerKey === 'openrouter' && ($k = $c->getOption('ai_openrouter_key', ''))) {
            $provider = new OpenRouterProvider($k, $modelName, $proxy);
        } elseif ($providerKey === 'openai' && ($k = $c->getOption('ai_openai_key', ''))) {
            $provider = new OpenAIProvider($k, $modelName, $c->getOption('ai_openai_base_url', 'https://api.openai.com/v1/chat/completions'), $proxy);
        }
        if ($provider === null) {
            error_log("VisionDescriber: провайдер '{$providerKey}' не настроен (нет ключа)");
            return null;
        }

        // Массив-content провайдеры передают в API как есть (VisionFormatter его не трогает).
        $messages = [[
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => 'Что на картинке?'],
                ['type' => 'image_url', 'image_url' => ['url' => $imagePart]],
            ],
        ]];

        $t0 = microtime(true);
        try {
            $out = $provider->askChat($messages, $prompt);
        } catch (\Throwable $e) {
            $cut = $e instanceof TruncatedResponseException; // MLP-348: обрыв по потолку токенов
            LlmDebugLog::log('vision', $providerKey, $modelName, ['system' => $prompt, 'messages' => $messages],
                $cut ? $e->partial : $e->getMessage(), $cut ? 'truncated' : 'error', (int)round((microtime(true) - $t0) * 1000));
            throw $e; // describe() выше сам решает, что делать со сбоем
        }
        LlmDebugLog::log('vision', $providerKey, $modelName, ['system' => $prompt, 'messages' => $messages],
            (string)$out, 'ok', (int)round((microtime(true) - $t0) * 1000));
        return $out;
    }

    /**
     * Pure (MLP-357): провайдер отверг саму картинку — постоянная ошибка, её можно кешировать:
     * код провайдера 1210 («ошибка формата картинки») или клиентская 4xx, кроме 408 и 429.
     * Таймауты, 5xx и 429 — временные: такую картинку надо пробовать снова.
     */
    public static function isPermanentFailure(string $message): bool {
        if (preg_match('/\b1210\b/', $message)) {
            return true;
        }
        if (preg_match('/HTTP Error (4\d\d)\b/', $message, $m) || preg_match('/\bcode\W{0,4}(4\d\d)\b/', $message, $m)) {
            return !in_array((int)$m[1], [408, 429], true);
        }
        return false;
    }
}
