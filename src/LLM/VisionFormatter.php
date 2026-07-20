<?php

namespace LLM;

use ConfigManager;

/**
 * Преобразует картинки из сообщений чата в мультимодальный формат OpenAI-совместимого API
 * (content-массив с частями type=text / type=image_url), чтобы vision-модели «видели» вложения.
 *
 * Картинки в чате хранятся как markdown: ![alt](/upload/chat/xxx.png).
 * Локальные файлы УМЕНЬШАЮТСЯ (GD) до превью и встраиваются как base64 data-URI:
 *  - модель НЕ фетчит удалённые URL (RouterAI/gemini принимает только инлайн-данные);
 *  - превью весит ~100–200 КБ независимо от размера оригинала (оригиналы бывают в десятки МБ);
 *  - vision-модели всё равно даунскейлят вход, потому потери качества для распознавания нет.
 * Применять только в OpenAI-совместимых провайдерах; Yandex/GigaChat используют текст как есть.
 */
class VisionFormatter {

    const MAX_IMAGES     = 3;        // не больше N картинок на запрос
    const RECENT_MSGS    = 3;        // картинки берём только из последних N сообщений (свежие/триггер)
    const MAX_DIM        = 1024;     // макс. сторона превью, px
    const JPEG_QUALITY   = 80;
    const MAX_PIXELS     = 30000000; // защита памяти: не декодируем картинки крупнее ~30 Мпикс

    /** Развернуть картинки в messages, если включено ai_send_images. Иначе — вернуть как есть. */
    public static function maybeExpand(array $messages): array {
        $c = ConfigManager::getInstance();
        if (!$c->getOption('ai_send_images', 1)) {
            return $messages;
        }
        $base = (string)$c->getOption('ai_public_base_url', 'https://mlp-evening.ru');
        $webroot = realpath(__DIR__ . '/../..'); // корень сайта (где лежит /upload)
        return self::expand($messages, $base, $webroot ?: null);
    }

    /**
     * Разворачивает картинки ТОЛЬКО из последних RECENT_MSGS сообщений и от новых к старым —
     * т.е. присылаем модели картинку из сообщения, на которое бот отвечает (свежую/триггер),
     * а не весь скроллбэк (иначе старые картинки жгут бюджет в каждом ответе и вытесняют нужную).
     *
     * $webroot задан → локальные /upload картинки ужимаются в base64-превью;
     * иначе — отдаются абсолютным URL (используется в тестах чистой логики).
     */
    public static function expand(array $messages, string $baseUrl, ?string $webroot = null): array {
        $base = rtrim($baseUrl, '/');
        $budget = self::MAX_IMAGES;
        $n = count($messages);
        $from = max(0, $n - self::RECENT_MSGS);
        for ($i = $n - 1; $i >= $from && $budget > 0; $i--) {
            $messages[$i] = self::expandOne($messages[$i], $base, $webroot, $budget);
        }
        return $messages;
    }

    private static function expandOne(array $msg, string $base, ?string $webroot, int &$budget): array {
        $content = $msg['content'] ?? '';
        if (!is_string($content) || $content === '' || $budget <= 0) {
            return $msg;
        }
        if (!preg_match_all('/!\[[^\]]*\]\(([^)\s]+)\)/u', $content, $m)) {
            return $msg;
        }

        $parts = [];
        foreach ($m[1] as $raw) {
            if ($budget <= 0) break;
            $img = self::resolveImage(trim($raw), $base, $webroot);
            if ($img !== null) {
                $parts[] = ['type' => 'image_url', 'image_url' => ['url' => $img]];
                $budget--;
            }
        }
        if (!$parts) {
            return $msg;
        }

        $text = trim(preg_replace('/!\[[^\]]*\]\([^)\s]+\)/u', '', $content));
        $out = [];
        if ($text !== '') {
            $out[] = ['type' => 'text', 'text' => $text];
        }
        $msg['content'] = array_merge($out, $parts);
        return $msg;
    }

    /** data-URI уменьшенного превью (локальный файл) или абсолютный URL (внешний), либо null. */
    private static function resolveImage(string $url, string $base, ?string $webroot): ?string {
        if (self::imageMime($url) === null) {
            return null; // не картинка
        }
        // Локальный путь на сайте: /upload/... -> ресайз в превью + base64
        if ($url !== '' && $url[0] === '/' && $webroot !== null) {
            $dataUri = self::thumbnailDataUri($webroot . $url);
            if ($dataUri !== null) {
                return $dataUri;
            }
            return $base . $url; // не смогли обработать локально — запасной абсолютный URL
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url; // внешний URL как есть
        }
        if ($url !== '' && $url[0] === '/') {
            return $base . $url;
        }
        return null;
    }

    /** Читает локальный файл, ужимает через GD до MAX_DIM и возвращает data:image/jpeg;base64,... */
    private static function thumbnailDataUri(string $path): ?string {
        if (!is_file($path) || !function_exists('imagecreatefromstring')) {
            return null;
        }
        $info = @getimagesize($path);
        if (!$info) {
            return null;
        }
        [$w, $h] = $info;
        if ($w < 1 || $h < 1 || ($w * $h) > self::MAX_PIXELS) {
            return null; // битая или слишком большая по памяти — пропускаем
        }
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            return null;
        }
        $src = @imagecreatefromstring($bytes);
        if (!$src) {
            return null;
        }

        $scale = min(1.0, self::MAX_DIM / max($w, $h));
        $nw = max(1, (int)round($w * $scale));
        $nh = max(1, (int)round($h * $scale));

        $dst = imagecreatetruecolor($nw, $nh);
        // белый фон (на случай прозрачности PNG — JPEG альфу не хранит)
        imagefilledrectangle($dst, 0, 0, $nw, $nh, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        ob_start();
        imagejpeg($dst, null, self::JPEG_QUALITY);
        $jpeg = ob_get_clean();
        // imagedestroy не нужен: с PHP 8.0 ресурсы GD — объекты, освобождаются сборщиком

        if ($jpeg === false || $jpeg === '') {
            return null;
        }
        return 'data:image/jpeg;base64,' . base64_encode($jpeg);
    }

    private static function imageMime(string $url): ?string {
        return preg_match('#\.(png|jpe?g|gif|webp|bmp)(\?|$)#i', $url) ? 'image' : null;
    }
}
