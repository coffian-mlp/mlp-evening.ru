<?php
/**
 * Юнит-тест EventManager::expandOccurrences() — AR3-1 (вынос дублированного
 * recurrence-раскрытия из BotWorker и LLMManager в единую точку).
 *
 * Метод статический и pure ($now передаётся), но EventManager тянет Database.php →
 * config.php: на чистом клоне мягко SKIP.
 *
 * Запуск: php tests/test_event_occurrences.php
 */

if (!file_exists(__DIR__ . '/../config.php')) {
    echo "SKIP: config.php отсутствует (нужен для загрузки Database.php)\n";
    exit(0);
}

require_once __DIR__ . '/../src/EventManager.php';

$fail = 0;
function ok($cond, $label) {
    global $fail;
    echo ($cond ? "  [OK] " : "  [FAIL] ") . $label . "\n";
    if (!$cond) $fail++;
}

// Опорное "сейчас": 2030-01-10 00:00:00 UTC.
$now = strtotime('2030-01-10 00:00:00 UTC');
$ts = fn($s) => strtotime($s . ' UTC');

echo "== Разовое событие: одно вхождение, с real_start_time и run_id ==\n";
$one = EventManager::expandOccurrences([
    ['id' => 5, 'start_time' => '2030-01-12 20:00:00', 'is_recurring' => 0, 'recurrence_rule' => ''],
], 7, $now);
ok(count($one) === 1, 'разовое → 1 occurrence');
ok($one[0]['real_start_time'] === $ts('2030-01-12 20:00:00'), 'real_start_time = UTC ts');
ok($one[0]['run_id'] === '5_' . $ts('2030-01-12 20:00:00'), 'run_id = <id>_<ts>');

echo "\n== Daily-повтор в пределах горизонта 7д ==\n";
$daily = EventManager::expandOccurrences([
    ['id' => 1, 'start_time' => '2030-01-10 12:00:00', 'is_recurring' => 1, 'recurrence_rule' => 'daily'],
], 7, $now);
// база 10-е + повторы 11..16 (17-е уже вне now+7д=17-е 00:00) → 7 вхождений
ok(count($daily) === 7, 'daily: база + 6 повторов в горизонте (получено ' . count($daily) . ')');

echo "\n== Weekly-повтор ==\n";
$weekly = EventManager::expandOccurrences([
    ['id' => 2, 'start_time' => '2030-01-10 12:00:00', 'is_recurring' => 1, 'recurrence_rule' => 'weekly'],
], 7, $now);
// база 10-е + следующий 17-е (17-е 12:00 < 17-е 00:00? нет) → только база
ok(count($weekly) === 1, 'weekly: следующий за горизонтом → только база (получено ' . count($weekly) . ')');

echo "\n== Сортировка по времени ==\n";
$sorted = EventManager::expandOccurrences([
    ['id' => 1, 'start_time' => '2030-01-15 10:00:00', 'is_recurring' => 0, 'recurrence_rule' => ''],
    ['id' => 2, 'start_time' => '2030-01-11 10:00:00', 'is_recurring' => 0, 'recurrence_rule' => ''],
], 7, $now);
ok($sorted[0]['id'] === 2 && $sorted[1]['id'] === 1, 'результат отсортирован по real_start_time');

echo "\n== Базовое вхождение включается даже в прошлом (потребитель фильтрует) ==\n";
$past = EventManager::expandOccurrences([
    ['id' => 9, 'start_time' => '2030-01-01 10:00:00', 'is_recurring' => 0, 'recurrence_rule' => ''],
], 7, $now);
ok(count($past) === 1, 'прошедшее разовое всё равно в списке (для finished-анонсов)');

echo "\n== Пустой ввод / битая дата ==\n";
ok(EventManager::expandOccurrences([], 7, $now) === [], 'пустой ввод → пусто');
$bad = EventManager::expandOccurrences([['id' => 1, 'start_time' => '', 'is_recurring' => 0]], 7, $now);
ok($bad === [], 'битая дата пропущена');

echo "\n" . ($fail === 0 ? "ALL PASS\n" : "FAILURES: $fail\n");
exit($fail === 0 ? 0 : 1);
