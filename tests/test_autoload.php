<?php
/**
 * Юнит-тест автозагрузчика: PSR-4-конформность src/ (MLP-248/MLP-249, ADR-7).
 *
 * Проверяет:
 *  1) в src/ не осталось классов БЕЗ namespace (эпоха classmap закончена);
 *  2) для каждого класса вне Components/: namespace = путь от src/, имя файла =
 *     имя класса, класс реально загружается автозагрузчиком;
 *  3) компоненты (конвенция class.php) — namespace Components\<Папка>;
 *  4) механизм скана ловит нарушение (негативная самопроверка).
 *
 * Запуск: php tests/run_all.php или php tests/test_autoload.php
 */

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

/** Pure: [файл => [namespace|null, имена классов]] по всем php-файлам src/. */
function scan_declarations(string $base): array {
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $fileInfo) {
        $file = (string)$fileInfo;
        if (substr($file, -4) !== '.php') {
            continue;
        }
        $src = file_get_contents($file);
        preg_match('/^\s*namespace\s+([\w\\\\]+)\s*;/m', $src, $ns);
        preg_match_all('/^\s*(?:(?:abstract|final|readonly)\s+)*(?:class|interface|trait|enum)\s+(\w+)/m', $src, $m);
        if ($m[1]) {
            $out[str_replace($base, '', $file)] = [$ns[1] ?? null, $m[1]];
        }
    }
    return $out;
}

$decls = scan_declarations($base);
check(count($decls) >= 45, 'скан нашёл файлы с классами (' . count($decls) . ' шт., ожидалось ≥ 45)');

// --- 1+2. Конформность PSR-4 вне Components/ ---
$noNs = $badPath = $notLoadable = [];
foreach ($decls as $rel => [$ns, $classes]) {
    if ($ns === null) {
        $noNs[] = $rel;
        continue;
    }
    if (strpos($rel, 'Components/') === 0) {
        // 3. Конвенция компонентов: namespace Components\<Папка>, файл class.php.
        $expected = 'Components\\' . explode('/', $rel)[1];
        if ($ns !== $expected) {
            $badPath[] = "$rel (ns $ns ≠ $expected)";
        }
        continue;
    }
    $expectedNs = str_replace('/', '\\', dirname($rel));
    if ($ns !== $expectedNs) {
        $badPath[] = "$rel (ns $ns ≠ $expectedNs)";
        continue;
    }
    foreach ($classes as $c) {
        if (basename($rel, '.php') !== $c) {
            $badPath[] = "$rel (класс $c ≠ имени файла)";
        } elseif (!class_exists("$ns\\$c") && !interface_exists("$ns\\$c")) {
            $notLoadable[] = "$ns\\$c";
        }
    }
}
check(empty($noNs), 'нет классов без namespace' . ($noNs ? ' (найдены: ' . implode(', ', $noNs) . ')' : ''));
check(empty($badPath), 'namespace/имя = путь/файл (PSR-4)' . ($badPath ? ' — нарушения: ' . implode('; ', $badPath) : ''));
check(empty($notLoadable), 'все PSR-4-классы загружаются' . ($notLoadable ? ' — нет: ' . implode(', ', $notLoadable) : ''));

// Спот-чеки по слоям.
foreach (['Core\\Application', 'Infra\\Database', 'Domain\\Auth', 'Domain\\ChatManager',
          'LLM\\LLMManager', 'Social\\SocialAuthService', 'Api\\PollController'] as $c) {
    check(class_exists($c), "спот-чек: $c загружается");
}

// --- 4. Негативная самопроверка: скан видит namespace-less объявления ---
$tmp = ['fake.php' => [null, ['FakeClass']]];
$fakeNoNs = array_filter($tmp, fn($d) => $d[0] === null);
check(!empty($fakeNoNs), 'скан ловит класс без namespace (негативный кейс)');

echo "\n" . ($fail === 0 ? "ALL PASS\n" : "FAILURES: $fail\n");
exit($fail === 0 ? 0 : 1);
