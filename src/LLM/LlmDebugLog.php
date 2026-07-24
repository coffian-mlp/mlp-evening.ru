<?php

namespace LLM;

use Infra\ConfigManager;
use Infra\Database;

/**
 * Debug-журнал обращений к нейронкам: каждый запрос/ответ (чат, служебные
 * вызовы, vision, генерация картинок) пишется в таблицу llm_debug_log —
 * для отладки «что модель реально получила и что ответила». И любопытства.
 *
 * TTL 7 суток: чистка ленивая, при каждой вставке (индекс по created_at,
 * LIMIT против длинного лока). Выключатель — опция ai_debug_log (деф. вкл).
 * Журнал никогда не роняет бота: все сбои глотаются в error_log.
 */
class LlmDebugLog {

    const TTL_DAYS = 7;
    const MAX_FIELD = 200000; // потолок символов на request/response

    public static function enabled(): bool {
        try {
            return (bool)(int)ConfigManager::getInstance()->getOption('ai_debug_log', 1);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Записать обмен. $kind: chat | utility | vision | image.
     * $request — строка или массив (уйдёт в JSON); картинки-base64 вырезаются.
     */
    public static function log(string $kind, string $provider, string $model, $request, ?string $response, string $status, int $durationMs): void {
        if (!self::enabled()) {
            return;
        }
        try {
            $req = self::squash($request);
            $res = $response === null ? null : self::squash($response);
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare(
                "INSERT INTO llm_debug_log (kind, provider, model, status, duration_ms, request, response)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('ssssiss', $kind, $provider, $model, $status, $durationMs, $req, $res);
            $stmt->execute();
            $db->query("DELETE FROM llm_debug_log WHERE created_at < NOW() - INTERVAL " . self::TTL_DAYS . " DAY LIMIT 500");
        } catch (\Throwable $e) {
            error_log('LlmDebugLog: ' . get_class($e) . ': ' . $e->getMessage());
        }
    }

    /**
     * Pure: не-строку — в JSON; base64-картинки (data-URI и b64_json ответа
     * images API) заменяются маркером с размером; потолок длины MAX_FIELD.
     */
    public static function squash($data): string {
        $s = is_string($data)
            ? $data
            : (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $s = (string)preg_replace_callback(
            '/data:image\/[a-z+.\-]+;base64,[A-Za-z0-9+\/=\\\\]{50,}/i',
            fn($m) => '[b64-картинка, ' . strlen($m[0]) . ' симв.]',
            $s
        );
        $s = (string)preg_replace_callback(
            '/"b64_json"\s*:\s*"[^"]{50,}"/',
            fn($m) => '"b64_json":"[картинка, ' . strlen($m[0]) . ' симв.]"',
            $s
        );
        if (mb_strlen($s) > self::MAX_FIELD) {
            $s = mb_substr($s, 0, self::MAX_FIELD) . '…[обрезано]';
        }
        return $s;
    }

    /** Короткое имя провайдера для колонки provider (LLM\RouterAIProvider → RouterAI). */
    public static function providerName(object $provider): string {
        $short = basename(str_replace('\\', '/', get_class($provider)));
        return preg_replace('/Provider$/', '', $short) ?: $short;
    }

    /** Модель провайдера, если он умеет её отдавать (OpenAI-совместимые умеют). */
    public static function providerModel(object $provider): string {
        return method_exists($provider, 'getModel') ? (string)$provider->getModel() : '';
    }
}
