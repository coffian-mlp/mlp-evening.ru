<?php
namespace Domain;

/** Validation of optional public attribution; URLs are links, never fetched. */
final class StickerPackAuthor {
    public static function normalize(?string $name, ?string $url): array {
        $name = $name === null ? null : trim($name);
        $url = $url === null ? null : trim($url);
        if ($name !== null && (mb_strlen($name) > 120 || preg_match('/[\x00-\x1F\x7F]/u', $name))) {
            throw new \Core\UserError('Имя автора должно быть не длиннее 120 символов и без переносов строк.');
        }
        if ($url !== null && $url !== '') {
            $parts = parse_url($url);
            if (strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL) || !$parts
                || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
                || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
                || preg_match('/[\x00-\x20\x7F]/', $url)) {
                throw new \Core\UserError('Укажи полную ссылку на автора, начинающуюся с https:// или http://.');
            }
        }
        return [$name, $url];
    }
}
