<?php
/**
 * Единый раннер тестов (MLP-247, T-07, AC-4).
 *
 * Прогоняет tests/test_*.php (юниты) и tests/integration_*.php (интеграция,
 * кроме integration_helpers.php) — каждый отдельным php-процессом.
 *
 * Классификация по протоколу проекта:
 *   exit != 0                                  → FAIL
 *   exit 0, первая строка начинается с "SKIP:" → SKIP (не тестовый контур / БД недоступна)
 *   exit 0 и в выводе есть "ALL PASS"          → OK
 *   иначе                                      → FAIL (die()/exit без вердикта — exit code 0
 *                                                не доказательство успеха, см. ревью MLP-247)
 *
 * Exit code раннера: 0 — ни одного FAIL; 1 — есть FAIL.
 * Зависший тест убивается по таймауту (RUN_ALL_TIMEOUT сек, дефолт 120) → FAIL.
 *
 * Запуск:
 *   локально (юниты + SKIP интеграции): php tests/run_all.php
 *   полный контур:                      docker compose exec php php tests/run_all.php
 *   с деталями упавших:                 php tests/run_all.php -v
 */

$verbose = in_array('-v', $argv ?? [], true);
$dir = __DIR__;

$files = array_merge(
    glob($dir . '/test_*.php') ?: [],
    array_filter(glob($dir . '/integration_*.php') ?: [], function ($f) {
        return basename($f) !== 'integration_helpers.php';
    })
);
sort($files);

if (empty($files)) {
    echo "Тесты не найдены в {$dir}\n";
    exit(1);
}

$php = PHP_BINARY;
$timeoutSec = max(5, (int)(getenv('RUN_ALL_TIMEOUT') ?: 120));
$results = ['OK' => [], 'FAIL' => [], 'SKIP' => []];
$startedAt = microtime(true);

/** Запуск теста отдельным процессом с таймаутом: [exitCode|null, output, timedOut]. */
function run_test(string $php, string $file, int $timeoutSec): array {
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($file) . ' 2>&1';
    $proc = proc_open($cmd, [1 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        return [1, "не удалось запустить процесс", false];
    }
    stream_set_blocking($pipes[1], false);
    $deadline = microtime(true) + $timeoutSec;
    $out = '';
    do {
        $out .= (string)stream_get_contents($pipes[1]);
        $status = proc_get_status($proc);
        if (!$status['running']) {
            $out .= (string)stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            proc_close($proc);
            return [$status['exitcode'], $out, false];
        }
        usleep(50000);
    } while (microtime(true) < $deadline);

    proc_terminate($proc, 9);
    fclose($pipes[1]);
    proc_close($proc);
    return [null, $out, true];
}

foreach ($files as $file) {
    $name = basename($file);
    $t0 = microtime(true);
    [$code, $text, $timedOut] = run_test($php, $file, $timeoutSec);
    $ms = (int)round((microtime(true) - $t0) * 1000);

    $firstLine = trim(strtok($text, "\n") ?: '');
    $note = '';
    if ($timedOut) {
        $verdict = 'FAIL';
        $note = " (таймаут {$timeoutSec}с, процесс убит)";
    } elseif ($code !== 0) {
        $verdict = 'FAIL';
    } elseif (strpos($firstLine, 'SKIP:') === 0) {
        $verdict = 'SKIP';
    } elseif (strpos($text, 'ALL PASS') !== false) {
        $verdict = 'OK';
    } else {
        // exit 0 без "ALL PASS": die()/exit посреди теста — не успех.
        $verdict = 'FAIL';
        $note = ' (exit 0 без ALL PASS — вердикт теста отсутствует)';
    }
    $results[$verdict][] = $name;

    $mark = ['OK' => '✓', 'FAIL' => '✗', 'SKIP' => '−'][$verdict];
    printf("  %s %-45s %5d ms  %s%s\n", $mark, $name, $ms, $verdict, $note);

    if ($verdict === 'FAIL' || ($verbose && $verdict !== 'OK')) {
        foreach (explode("\n", rtrim($text)) as $line) {
            echo "      | $line\n";
        }
    }
}

$total = count($files);
$sec = round(microtime(true) - $startedAt, 1);
printf(
    "\nИтого: %d тестов за %.1f с — OK: %d, SKIP: %d, FAIL: %d\n",
    $total,
    $sec,
    count($results['OK']),
    count($results['SKIP']),
    count($results['FAIL'])
);

if (!empty($results['FAIL'])) {
    echo "Упали: " . implode(', ', $results['FAIL']) . "\n";
    exit(1);
}
exit(0);
