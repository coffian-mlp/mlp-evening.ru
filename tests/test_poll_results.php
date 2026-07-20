<?php
use Domain\PollManager;
/**
 * Юнит-тест PollManager::computeResults() — MLP-237.
 * Подсчёт голосов и процентов (доля от числа уникальных проголосовавших).
 *
 * PollManager тянет Database.php → config.php: на чистом клоне мягко SKIP.
 *
 * Запуск: php tests/test_poll_results.php
 */

if (!file_exists(__DIR__ . '/../.env') && !file_exists(__DIR__ . '/../config.php')) {
    echo "SKIP: нет .env/config.php (нужен для загрузки Database.php)\n";
    exit(0);
}

require_once __DIR__ . '/../autoload.php';

$fail = 0;
function ok($cond, $label) {
    global $fail;
    echo ($cond ? "  [OK] " : "  [FAIL] ") . $label . "\n";
    if (!$cond) $fail++;
}

$options = [
    ['id' => 1, 'text' => 'Рарити'],
    ['id' => 2, 'text' => 'Эпплджек'],
    ['id' => 3, 'text' => 'Пинки'],
];

echo "== Single-choice: 4 голоса, проценты от проголосовавших ==\n";
// Рарити x2, Эпплджек x1, Пинки x1 → 4 уникальных юзера
$r = PollManager::computeResults($options, [
    ['option_id' => 1, 'user_id' => 10],
    ['option_id' => 1, 'user_id' => 11],
    ['option_id' => 2, 'user_id' => 12],
    ['option_id' => 3, 'user_id' => 13],
]);
ok($r['total_voters'] === 4, 'total_voters = 4');
ok($r['options'][0]['votes'] === 2 && $r['options'][0]['percent'] === 50, 'Рарити 2 (50%)');
ok($r['options'][1]['votes'] === 1 && $r['options'][1]['percent'] === 25, 'Эпплджек 1 (25%)');
ok($r['options'][2]['votes'] === 1 && $r['options'][2]['percent'] === 25, 'Пинки 1 (25%)');

echo "\n== Multi-choice: один юзер за двоих → сумма % может быть > 100 ==\n";
// user10: Рарити+Эпплджек, user11: Рарити → voters=2
$m = PollManager::computeResults($options, [
    ['option_id' => 1, 'user_id' => 10],
    ['option_id' => 2, 'user_id' => 10],
    ['option_id' => 1, 'user_id' => 11],
]);
ok($m['total_voters'] === 2, 'total_voters = 2 (уникальные)');
ok($m['options'][0]['votes'] === 2 && $m['options'][0]['percent'] === 100, 'Рарити 2/2 = 100%');
ok($m['options'][1]['percent'] === 50, 'Эпплджек 1/2 = 50% (сумма >100 ок для multi)');

echo "\n== Пусто и округление ==\n";
$e = PollManager::computeResults($options, []);
ok($e['total_voters'] === 0 && $e['options'][0]['percent'] === 0, 'нет голосов → 0/0%');
// 1 из 3 → 33%
$round = PollManager::computeResults($options, [
    ['option_id' => 1, 'user_id' => 1],
    ['option_id' => 2, 'user_id' => 2],
    ['option_id' => 3, 'user_id' => 3],
]);
ok($round['options'][0]['percent'] === 33, '1/3 округляется до 33%');

echo "\n== Вариант без голосов присутствует с нулём ==\n";
$z = PollManager::computeResults($options, [['option_id' => 1, 'user_id' => 1]]);
ok(count($z['options']) === 3 && $z['options'][2]['votes'] === 0, 'все варианты в выдаче, Пинки = 0');

echo "\n" . ($fail === 0 ? "ALL PASS\n" : "FAILURES: $fail\n");
exit($fail === 0 ? 0 : 1);
