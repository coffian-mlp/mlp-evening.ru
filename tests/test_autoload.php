<?php
/**
 * Юнит-тест автозагрузчика (MLP-248, AC-1).
 *
 * Проверяет:
 *  1) каждый класс из src/classmap.php реально загружается автозагрузчиком;
 *  2) скан src/{,LLM,Social,Api} — каждый не-namespaced класс/интерфейс
 *     присутствует в classmap (защита от дрейфа при добавлении класса);
 *  3) PSR-4-ветка грузит namespaced-классы (Core\Application);
 *  4) сам механизм скана ловит пропуски (негативная самопроверка).
 *
 * Запуск: php tests/test_autoload.php
 */

// Классы (Auth, Database...) при загрузке тянут config.php — как и остальные юниты,
// мягко пропускаем на чистом клоне без конфига.
if (!file_exists(__DIR__ . '/../config.php')) {
    echo "SKIP: config.php отсутствует (классы требуют config при загрузке)\n";
    exit(0);
}

require_once __DIR__ . '/../autoload.php';

$fail = 0;
function check($cond, $label) {
    global $fail;
    if ($cond) { echo "  [OK] $label\n"; }
    else { echo "  [FAIL] $label\n"; $fail++; }
}

$base = realpath(__DIR__ . '/../src') . '/';
$map = require $base . 'classmap.php';

// --- 1. Каждый класс classmap загружается автозагрузчиком ---
$allLoaded = true;
foreach ($map as $class => $rel) {
    if (!is_file($base . $rel)) {
        check(false, "classmap: файл {$rel} существует");
        $allLoaded = false;
        continue;
    }
    if (!class_exists($class, true) && !interface_exists($class, true)) {
        check(false, "classmap: {$class} загружается");
        $allLoaded = false;
    }
}
check($allLoaded, 'все ' . count($map) . ' классов classmap загружаются автозагрузчиком');

// --- 2. Скан: не-namespaced классы src/ все в classmap ---
/**
 * Pure: имена глобальных классов/интерфейсов во всех php-файлах src/ (рекурсивно).
 * Ловит и enum/readonly (PHP 8.1/8.2); namespaced-файлы (Core, Components) отфильтровываются.
 */
function scan_global_classes(string $base): array {
    $found = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $fileInfo) {
        $file = (string)$fileInfo;
        if (substr($file, -4) !== '.php' || basename($file) === 'classmap.php') {
            continue;
        }
        $src = file_get_contents($file);
        if (preg_match('/^\s*namespace\s+[\w\\\\]+/m', $src)) {
            continue;
        }
        if (preg_match_all('/^\s*(?:(?:abstract|final|readonly)\s+)*(?:class|interface|trait|enum)\s+(\w+)/m', $src, $m)) {
            foreach ($m[1] as $name) {
                $found[$name] = str_replace($base, '', $file);
            }
        }
    }
    return $found;
}

$found = scan_global_classes($base);
$missing = array_diff_key($found, $map);
check(count($found) >= 30, 'скан нашёл классы (' . count($found) . ' шт., ожидалось ≥ 30)');
check(empty($missing), 'все найденные глобальные классы есть в classmap' . ($missing ? ' (нет: ' . implode(', ', array_keys($missing)) . ')' : ''));

$stale = array_diff_key($map, $found);
check(empty($stale), 'в classmap нет мёртвых записей' . ($stale ? ' (лишние: ' . implode(', ', array_keys($stale)) . ')' : ''));

// --- 3. PSR-4-ветка ---
check(class_exists('Core\\Application', true), 'PSR-4: Core\\Application загружается по namespace-пути');
check(class_exists('Core\\Component', true), 'PSR-4: Core\\Component загружается по namespace-пути');

// --- 4. Негативная самопроверка механизма скана ---
$mapWithHole = $map;
unset($mapWithHole['ChatManager']);
check(!empty(array_diff_key($found, $mapWithHole)), 'скан ловит пропуск класса в classmap (негативный кейс)');

echo "\n" . ($fail === 0 ? "ALL PASS\n" : "FAILURES: $fail\n");
exit($fail === 0 ? 0 : 1);
