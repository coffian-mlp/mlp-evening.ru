<?php

/**
 * Преобразует картинки из сообщений чата в мультимодальный формат OpenAI-совместимого API
 * (content-массив с частями type=text / type=image_url), чтобы vision-модели «видели» вложения.
 *
 * Картинки в чате хранятся как markdown: ![alt](/upload/chat/xxx.png).
 * Относительные пути превращаются в абсолютные URL (ai_public_base_url).
 * Применять только в OpenAI-совместимых провайдерах; Yandex/GigaChat используют текст как есть.
 */
class VisionFormatter {

    /** Развернуть картинки в messages, если включено ai_send_images. Иначе — вернуть как есть. */
    public static function maybeExpand(array $messages): array {
        require_once __DIR__ . '/../ConfigManager.php';
        $c = ConfigManager::getInstance();
        if (!$c->getOption('ai_send_images', 1)) {
            return $messages;
        }
        $base = (string)$c->getOption('ai_public_base_url', 'https://mlp-evening.ru');
        return self::expand($messages, $base);
    }

    /** Чистое преобразование (без конфига) — покрыто юнит-тестами. */
    public static function expand(array $messages, string $baseUrl): array {
        $base = rtrim($baseUrl, '/');
        foreach ($messages as &$msg) {
            $msg = self::expandOne($msg, $base);
        }
        unset($msg);
        return $messages;
    }

    private static function expandOne(array $msg, string $base): array {
        $content = $msg['content'] ?? '';
        if (!is_string($content) || $content === '') {
            return $msg;
        }

        // markdown-картинки ![alt](url)
        if (!preg_match_all('/!\[[^\]]*\]\(([^)\s]+)\)/u', $content, $m)) {
            return $msg;
        }

        $urls = [];
        foreach ($m[1] as $u) {
            $u = trim($u);
            if ($u === '') continue;
            if ($u[0] === '/') {
                $u = $base . $u; // относительный /upload/... -> абсолютный
            }
            if (!preg_match('#^https?://#i', $u)) continue;              // только http(s)
            if (!preg_match('#\.(png|jpe?g|gif|webp|bmp)(\?|$)#i', $u)) continue; // только картинки
            $urls[] = $u;
        }
        if (!$urls) {
            return $msg;
        }

        // текст без markdown-картинок (сохраняем префикс "[HH:MM] user:" и остальной текст)
        $text = trim(preg_replace('/!\[[^\]]*\]\([^)\s]+\)/u', '', $content));

        $parts = [];
        if ($text !== '') {
            $parts[] = ['type' => 'text', 'text' => $text];
        }
        foreach ($urls as $u) {
            $parts[] = ['type' => 'image_url', 'image_url' => ['url' => $u]];
        }

        $msg['content'] = $parts;
        return $msg;
    }
}
