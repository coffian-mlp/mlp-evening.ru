<?php
namespace Domain;

use Infra\ConfigManager;

/** Shared theme asset defaults and validation for administration and rendering. */
class SiteAppearance {
    public const DEFAULTS = [
        'theme_luna_background' => '/assets/img/themes/luna-background.webp',
        'theme_luna_logo' => '/assets/img/themes/luna-logo.webp',
        'theme_celestia_background' => '/assets/img/themes/celestia-background.webp',
        'theme_celestia_logo' => '/assets/img/themes/celestia-logo.webp',
    ];

    public static function normalizeImageUrl($value): string {
        if (!is_string($value)) throw new \InvalidArgumentException('Укажи путь к изображению или ссылку HTTP(S).');
        $value = trim($value);
        if ($value === '') return '';
        if (strlen($value) > 2048 || preg_match('~[\x00-\x20\x7f\x22\x27<>\\\\(){};]~', $value)) {
            throw new \InvalidArgumentException('Укажи путь к изображению или ссылку HTTP(S) без пробелов.');
        }
        if (str_starts_with($value, '/') && !str_starts_with($value, '//')) return $value;
        if (filter_var($value, FILTER_VALIDATE_URL) && in_array(strtolower(parse_url($value, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true)
            && parse_url($value, PHP_URL_USER) === null && parse_url($value, PHP_URL_PASS) === null) return $value;
        throw new \InvalidArgumentException('Укажи путь от корня сайта (/upload/…) или ссылку HTTP(S).');
    }

    public static function assets(): array {
        $config = ConfigManager::getInstance();
        $assets = [];
        foreach (self::DEFAULTS as $key => $default) {
            try { $value = self::normalizeImageUrl($config->getOption($key, '')); }
            catch (\InvalidArgumentException $e) { $value = ''; }
            $assets[$key] = $value !== '' ? $value : $default;
        }
        return $assets;
    }
}
