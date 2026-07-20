<?php

namespace Infra;

/**
 * Превью изображений (MLP-258): уменьшенная webp-копия для тяжёлых списков
 * (пикер стикеров, админ-таблица). Генерация — при загрузке файла (не «на лету
 * по запросу»: публичный ресайз-эндпоинт — осознанно отложенная фича, бэклог).
 *
 * Graceful degradation: нет GD / битый файл / анимированный GIF → null,
 * потребитель показывает оригинал. Ошибки — в error_log, поток не прерывается.
 *
 * Паттерн GD — по образцу LLM\VisionFormatter (единственный прецедент в проекте).
 */
class Thumbnailer {

    private const THUMBS_DIR = '/upload/stickers/thumbs/';
    private const WEBP_QUALITY = 82;

    /**
     * Создать превью для файла по веб-пути (/upload/stickers/...).
     * Возвращает веб-путь превью или null (деградация на оригинал).
     */
    public static function createFor(string $webPath, int $maxSide = 128): ?string {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagewebp')) {
            return null; // GD недоступен — живём на оригиналах
        }

        // Только свои файлы: вход строго из /upload/stickers/ (анти-traversal)
        if (strpos($webPath, '/upload/stickers/') !== 0 || str_contains($webPath, '..')) {
            return null;
        }

        $root = dirname(__DIR__, 2);
        $srcPath = $root . $webPath;
        $real = realpath($srcPath);
        // Префикс с завершающим слэшем: /upload/stickers2 и symlink наружу не проходят
        if ($real === false || strpos($real, realpath($root . '/upload/stickers') . '/') !== 0) {
            return null;
        }

        $data = @file_get_contents($real);
        if ($data === false || $data === '') return null;

        // Анимированный GIF не ресайзим — GD оставит один кадр, анимация потеряется.
        if (self::isAnimatedGif($data)) return null;

        $src = @imagecreatefromstring($data);
        if ($src === false) {
            error_log('Thumbnailer: не удалось прочитать изображение ' . $webPath);
            return null;
        }

        $w = imagesx($src);
        $h = imagesy($src);
        if ($w < 1 || $h < 1) return null;

        // Маленькие изображения не растягиваем — превью не нужно, оригинал и так лёгкий.
        if ($w <= $maxSide && $h <= $maxSide) return null;

        $scale = $maxSide / max($w, $h);
        $tw = max(1, (int)round($w * $scale));
        $th = max(1, (int)round($h * $scale));

        $thumb = imagecreatetruecolor($tw, $th);
        // Прозрачность (png/webp-стикеры почти все с альфой)
        imagealphablending($thumb, false);
        imagesavealpha($thumb, true);
        imagefill($thumb, 0, 0, imagecolorallocatealpha($thumb, 0, 0, 0, 127));

        imagecopyresampled($thumb, $src, 0, 0, 0, 0, $tw, $th, $w, $h);

        $thumbsDir = $root . self::THUMBS_DIR;
        if (!is_dir($thumbsDir) && !@mkdir($thumbsDir, 0775, true)) {
            error_log('Thumbnailer: не удалось создать ' . self::THUMBS_DIR);
            return null;
        }

        // Расширение в имени — a.png и a.gif не делят одно превью (легаси-файлы)
        $ext = strtolower(pathinfo($webPath, PATHINFO_EXTENSION));
        $name = pathinfo($webPath, PATHINFO_FILENAME) . '_' . $ext . '_thumb.webp';
        $ok = @imagewebp($thumb, $thumbsDir . $name, self::WEBP_QUALITY);
        imagedestroy($thumb);
        imagedestroy($src);

        if (!$ok) {
            error_log('Thumbnailer: не удалось записать превью для ' . $webPath);
            return null;
        }
        return self::THUMBS_DIR . $name;
    }

    /** Эвристика: в анимированном GIF больше одного Graphic Control Extension. */
    public static function isAnimatedGif(string $data): bool {
        if (substr($data, 0, 3) !== 'GIF') return false;
        return preg_match_all('/\x00\x21\xF9\x04/', $data) > 1;
    }
}
