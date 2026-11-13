<?php
use LLM\LyraArtist;
use Infra\ConfigManager;
/**
 * Юнит-тест подстановки техники рисования (MLP-309): плейсхолдер {technique}
 * в стиль-промпте заменяется случайной записью из ai_image_techniques.
 *
 * ConfigManager подменяется анонимной заглушкой — БД не нужна.
 *
 * Запуск: php tests/test_image_technique.php
 */

if (!file_exists(__DIR__ . '/../.env') && !file_exists(__DIR__ . '/../config.php')) {
    echo "SKIP: нет .env/config.php\n";
    exit(0);
}

require_once __DIR__ . '/../autoload.php';

$fail = 0;
function ok($cond, $label) {
    global $fail;
    echo ($cond ? "  [OK] " : "  [FAIL] ") . $label . "\n";
    if (!$cond) $fail++;
}

/** Заглушка настроек: отдаёт заданный список техник без похода в БД. */
function fakeConfig(string $techniques): ConfigManager {
    return new class($techniques) extends ConfigManager {
        private string $techniques;
        public function __construct(string $t) { $this->techniques = $t; }
        public function getOption($key, $default = null) {
            return $key === 'ai_image_techniques' ? $this->techniques : $default;
        }
    };
}

$style = '{technique}. Hand-made amateur artwork, lively and a little clumsy. Subject:';

echo "== Подстановка ==\n";
$out = LyraArtist::applyTechnique($style, fakeConfig('watercolour painting'));
ok(strpos($out, '{technique}') === false, 'плейсхолдер заменён');
ok(strpos($out, 'watercolour painting') !== false, 'техника подставлена');
ok(strpos($out, 'Subject:') !== false, 'остальной промпт сохранён');

echo "\n== Выбор из списка ==\n";
$list = 'aaa-технику|bbb-технику|ccc-технику';
$seen = [];
for ($i = 0; $i < 60; $i++) {
    $r = LyraArtist::applyTechnique($style, fakeConfig($list));
    foreach (['aaa-технику', 'bbb-технику', 'ccc-технику'] as $t) {
        if (strpos($r, $t) !== false) $seen[$t] = true;
    }
}
ok(count($seen) === 3, 'за 60 прогонов встретились все три техники (рандом работает)');

echo "\n== Границы ==\n";
$noPlaceholder = 'Hand-made amateur watercolour painting. Subject:';
ok(LyraArtist::applyTechnique($noPlaceholder, fakeConfig('crayon')) === $noPlaceholder,
    'без плейсхолдера промпт не меняется (обратная совместимость)');
$out = LyraArtist::applyTechnique($style, fakeConfig(''));
ok(strpos($out, '{technique}') === false, 'пустая опция — берётся встроенный набор');
$out = LyraArtist::applyTechnique($style, fakeConfig('|  |'));
ok(strpos($out, '{technique}') === false, 'мусорный список не оставляет плейсхолдер в промпте');

echo "\n== Утверждённые запасные значения MLP-462 ==\n";
$hasStyle = defined(LyraArtist::class . '::DEFAULT_STYLE_PROMPT');
ok($hasStyle && hash('sha256', LyraArtist::DEFAULT_STYLE_PROMPT) === '35cd422fe6dd740528bc8788484c8f36d04853697f2419dc087da1847f34a97d',
    'запасной стиль дословно совпадает с утверждённым значением');
ok(hash('sha256', LyraArtist::DEFAULT_TECHNIQUES) === 'dfeced056dd894bad3b4c6430408b3bf930065b0fc25b6950d331a2dc4d2fc13',
    'запасной список дословно совпадает с утверждённым значением');
$defaults = array_map('trim', explode('|', LyraArtist::DEFAULT_TECHNIQUES));
ok(count($defaults) === 11 && !in_array('', $defaults, true), '11 непустых запасных техник');
ok(count(array_filter($defaults, fn($t) => str_starts_with($t, 'grotesque image'))) === 2,
    'гротеск записан дважды');
if ($hasStyle) {
    $out = LyraArtist::applyTechnique(LyraArtist::DEFAULT_STYLE_PROMPT, fakeConfig(''));
    ok(!str_contains($out, '{technique}') && str_ends_with($out, 'Subject:'),
        'запасные стиль и техники образуют готовую приставку к сцене');
}

echo $fail ? "\nFAIL ($fail)\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
