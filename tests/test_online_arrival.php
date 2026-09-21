<?php
use Domain\OnlineManager;
/**
 * Юнит-тест решения «появился после отсутствия» (MLP-319): OnlineManager::isArrival — pure.
 * Порог берётся из ai_greeting_absence_hours * 3600; gap = секунд с прошлой отметки users.last_seen.
 *
 * Запуск: php tests/test_online_arrival.php
 */
require_once __DIR__ . '/../autoload.php';

$fail = 0;
function ok($cond, $label) {
    global $fail;
    echo ($cond ? "  [OK] " : "  [FAIL] ") . $label . "\n";
    if (!$cond) $fail++;
}
$h4 = 4 * 3600;

echo "== порог ==\n";
ok(OnlineManager::isArrival(5 * 3600, $h4) === true, 'отсутствовал 5 ч при пороге 4 ч → приход');
ok(OnlineManager::isArrival($h4, $h4) === true, 'ровно порог → приход (>=)');
ok(OnlineManager::isArrival($h4 - 1, $h4) === false, 'на секунду меньше порога → не приход');
ok(OnlineManager::isArrival(42, $h4) === false, 'обычный heartbeat (42 с) → не приход');
ok(OnlineManager::isArrival(0, $h4) === false, 'нулевой зазор → не приход');

echo "\n== выключатель ==\n";
ok(OnlineManager::isArrival(10 * 3600, 0) === false, 'порог 0 (выкл) → никогда');
ok(OnlineManager::isArrival(10 * 3600, -5) === false, 'отрицательный порог → никогда');

echo "\n== первая отметка ==\n";
ok(OnlineManager::isArrival(null, $h4) === false, 'last_seen NULL → только штамп, без приветствия (анти-залп после деплоя)');

echo "\n";
if ($fail > 0) { echo "FAIL: $fail\n"; exit(1); }
echo "ALL PASS\n";
