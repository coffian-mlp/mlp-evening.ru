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
 * Legacy-fallback на config.php удалён (MLP-266): .env — единственный источник.
 */
class Env {
    private static ?array $vars = null;

    public static function get(string $key, ?string $default = null): ?string {
        if (self::$vars === null) {
            self::$vars = self::load();
        }
        return self::$vars[$key] ?? $default;
    }

    /** Есть ли конфигурация (.env). */
    public static function available(): bool {
        return is_file(dirname(__DIR__, 2) . '/.env');
    }

    private static function load(): array {
        $envFile = dirname(__DIR__, 2) . '/.env';
        return is_file($envFile) ? self::parse((string)file_get_contents($envFile)) : [];
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
