<?php

namespace Infra;

/**
 * Конфигурация окружения из .env (MLP-252, AR5-1). Без Composer (ADR-1).
 *
 * Единый источник секретов для PHP и docker-compose (тот читает .env нативно).
 * Формат: KEY=VALUE построчно, '#' — комментарий, кавычки вокруг значения
 * снимаются. Ключи: DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_CHARSET,
 * CHAT_DRIVER, CENTRIFUGO_API_URL, CENTRIFUGO_API_KEY, CENTRIFUGO_SECRET.
 *
 * Переходный fallback (убрать в v4.9): если .env отсутствует, а config.php
 * есть — значения берутся из него (миграция прода без окна поломки).
 */
class Env {
    private static ?array $vars = null;

    public static function get(string $key, ?string $default = null): ?string {
        if (self::$vars === null) {
            self::$vars = self::load();
        }
        return self::$vars[$key] ?? $default;
    }

    /** Есть ли конфигурация вообще (.env или legacy config.php). */
    public static function available(): bool {
        $root = dirname(__DIR__, 2);
        return is_file($root . '/.env') || is_file($root . '/config.php');
    }

    private static function load(): array {
        $root = dirname(__DIR__, 2);

        $envFile = $root . '/.env';
        if (is_file($envFile)) {
            return self::parse((string)file_get_contents($envFile));
        }

        // Legacy fallback: config.php (return-массив) → плоские ключи.
        $legacy = $root . '/config.php';
        if (is_file($legacy)) {
            $cfg = require $legacy;
            $db = $cfg['db'] ?? [];
            $chat = $cfg['chat'] ?? [];
            return [
                'DB_HOST'             => $db['host'] ?? null,
                'DB_NAME'             => $db['name'] ?? null,
                'DB_USER'             => $db['user'] ?? null,
                'DB_PASS'             => $db['pass'] ?? null,
                'DB_CHARSET'          => $db['charset'] ?? 'utf8mb4',
                'CHAT_DRIVER'         => $chat['driver'] ?? 'sse',
                'CENTRIFUGO_API_URL'  => $chat['centrifugo_api_url'] ?? null,
                'CENTRIFUGO_API_KEY'  => $chat['centrifugo_api_key'] ?? null,
                'CENTRIFUGO_SECRET'   => $chat['centrifugo_secret'] ?? null,
            ];
        }

        return [];
    }

    /** Pure: парсинг содержимого .env в массив (тестируется отдельно). */
    public static function parse(string $content): array {
        $vars = [];
        foreach (preg_split('/\R/', $content) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if ($value !== '' && ($value[0] === '"' || $value[0] === "'") && substr($value, -1) === $value[0]) {
                $value = substr($value, 1, -1);
            }
            if ($key !== '') {
                $vars[$key] = $value;
            }
        }
        return $vars;
    }
}
