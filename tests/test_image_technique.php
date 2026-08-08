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

$style = "A naive child's {technique}, charming and silly. Subject:";

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
$noPlaceholder = "A naive child's watercolor painting. Subject:";
ok(LyraArtist::applyTechnique($noPlaceholder, fakeConfig('crayon')) === $noPlaceholder,
    'без плейсхолдера промпт не меняется (обратная совместимость)');
$out = LyraArtist::applyTechnique($style, fakeConfig(''));
ok(strpos($out, '{technique}') === false, 'пустая опция — берётся встроенный набор');
$out = LyraArtist::applyTechnique($style, fakeConfig('|  |'));
ok(strpos($out, '{technique}') === false, 'мусорный список не оставляет плейсхолдер в промпте');

echo $fail ? "\nFAIL ($fail)\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
