<?php

namespace LLM;

use Core\FileCache;
use Infra\ConfigManager;

/**
 * Генерация картинок для Лиры-художницы (MLP-274): RouterAI images API
 * (OpenAI-совместимый, ответ b64_json). Результат пережимается GD в JPEG
 * (наивному стилю не вредит, канал бережёт) и сохраняется в /upload/lyra/.
 *
 * Дневной лимит — счётчик в cache/imagegen/<дата>.json: инкремент делает
 * ТОЛЬКО воркер (он один — гонок нет), генерация дороже текста.
 */
class ImageGenerator {

    const UPLOAD_DIR = '/upload/lyra/';
    const MAX_DIM = 1024;
    const JPEG_QUALITY = 88;
    const TIMEOUT = 90; // генерация 5–30с, берём с запасом

    /** Сегодняшний счётчик генераций (для лимита и статистики). */
    public static function todayCount(?FileCache $cache = null, ?string $day = null): int {
        $cache = $cache ?? new FileCache('imagegen');
        $day = $day ?? gmdate('Y-m-d');
        $row = $cache->get($day, 172800); // TTL 2 суток — вчерашний файл умирает сам
        return (int)($row['count'] ?? 0);
    }

    public static function bumpToday(?FileCache $cache = null, ?string $day = null): void {
        $cache = $cache ?? new FileCache('imagegen');
        $day = $day ?? gmdate('Y-m-d');
        $cache->set($day, ['count' => self::todayCount($cache, $day) + 1]);
    }

    /**
     * Сгенерировать и сохранить картинку. Возвращает web-путь /upload/lyra/...jpg
     * или null (детали сбоя — в error_log). $model переопределяет настройку
     * (используется дегустацией моделей).
     */
    public static function generate(string $prompt, ?string $model = null): ?string {
        $c = ConfigManager::getInstance();
        $key = (string)$c->getOption('ai_routerai_key', '');
        if ($key === '') {
            error_log('ImageGenerator: нет ai_routerai_key');
            return null;
        }
        $model = $model ?? (string)$c->getOption('ai_image_model', 'black-forest-labs/flux.2-klein-4b');

        $ch = curl_init('https://routerai.ru/api/v1/images/generations');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['model' => $model, 'prompt' => $prompt, 'n' => 1, 'size' => '1024x1024']),
            CURLOPT_TIMEOUT => self::TIMEOUT,
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err || $code >= 400 || !$res) {
            error_log("ImageGenerator [$model] HTTP $code: " . ($err ?: mb_substr((string)$res, 0, 200)));
            return null;
        }
        $data = json_decode($res, true);
        $b64 = $data['data'][0]['b64_json'] ?? null;
        if (!$b64) {
            error_log("ImageGenerator [$model]: нет b64_json в ответе");
            return null;
        }
        $bytes = base64_decode($b64);
        if ($bytes === false || $bytes === '') {
            error_log("ImageGenerator [$model]: b64_json не декодировался");
            return null;
        }
        return self::saveJpeg($bytes);
    }

    /** GD-пережатие в JPEG (max 1024) и сохранение в /upload/lyra/. */
    public static function saveJpeg(string $bytes, ?string $nameHint = null): ?string {
        $src = @imagecreatefromstring($bytes);
        if (!$src) {
            error_log('ImageGenerator: битые байты картинки');
            return null;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1.0, self::MAX_DIM / max($w, $h));
        $nw = max(1, (int)round($w * $scale));
        $nh = max(1, (int)round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagefilledrectangle($dst, 0, 0, $nw, $nh, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        $root = dirname(__DIR__, 2);
        $dir = $root . self::UPLOAD_DIR;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            error_log('ImageGenerator: не создать ' . $dir);
            return null;
        }
        $name = ($nameHint ?: uniqid('lyra_')) . '_' . bin2hex(random_bytes(4)) . '.jpg';
        if (!imagejpeg($dst, $dir . $name, self::JPEG_QUALITY)) {
            // Классика: каталог создан root-ом по ssh, воркер (bitrix) писать не может.
            error_log('ImageGenerator: не записать ' . $dir . $name . ' (права? владелец каталога?)');
            return null;
        }
        return self::UPLOAD_DIR . $name;
    }
}
