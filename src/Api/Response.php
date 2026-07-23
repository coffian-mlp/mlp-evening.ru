<?php

namespace Api;

use Core\UserError;
use Throwable;

/**
 * Граница HTTP-ответа api.php (MLP-262, AR6-4): единый JSON-формат
 * {success, message, type, data} и политика текстов ошибок (MLP-261).
 *
 * payload()/classify() — чистые (юнит-тест tests/test_api_response.php);
 * json()/ok()/fail()/caught() — echo + exit (допустимо только на границе).
 * Глобальные sendResponse()/respondCaught() в api.php — тонкие делегаты сюда
 * (легаси-вызовы switch уходят со срезами AR5-6).
 */
final class Response {

    /** Тело ответа — формат байт-в-байт как у исторической sendResponse(). */
    public static function payload(bool $success, string $message, string $type = 'success', array $data = []): array {
        return [
            'success' => $success,
            'message' => $message,
            'type' => $type,
            'data' => $data,
        ];
    }

    /**
     * Классификация исключения (политика MLP-261):
     * Core\UserError → его текст наружу (log = null);
     * всё остальное → общий текст, детали в log-строку.
     */
    public static function classify(Throwable $e, string $prefix = ''): array {
        if ($e instanceof UserError) {
            return ['message' => $prefix . $e->getMessage(), 'log' => null];
        }
        $action = $_POST['action'] ?? '?';
        return [
            'message' => $prefix . 'Что-то пошло не так. Попробуй ещё раз позже.',
            'log' => "api.php [{$action}] " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(),
        ];
    }

    /** Сигнатура 1:1 с sendResponse() — для механической миграции вызовов. */
    public static function json($success, $message, $type = 'success', $data = []): never {
        echo json_encode(self::payload((bool)$success, (string)$message, (string)$type, (array)$data));
        exit();
    }

    public static function ok(string $message, array $data = []): never {
        self::json(true, $message, 'success', $data);
    }

    public static function fail(string $message, array $data = []): never {
        self::json(false, $message, 'error', $data);
    }

    /** Ответ на пойманное исключение: UserError → текст, прочее → error_log + общий текст. */
    public static function caught(Throwable $e, string $prefix = ''): never {
        $c = self::classify($e, $prefix);
        if ($c['log'] !== null) {
            error_log($c['log']);
        }
        self::json(false, $c['message'], 'error');
    }
}
