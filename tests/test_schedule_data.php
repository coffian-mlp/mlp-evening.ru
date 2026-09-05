<?php
use LLM\ScheduleData;
/**
 * Юнит-тест ScheduleData (MLP-322): снимок расписания и блок данных для промпта.
 *
 * Сценарий — вечер 05.09.2026: StarGate 19:00–23:00 МСК (weekly), Феникс Райт 00:00 МСК
 * (06.09, стык ровно через час после конца StarGate), следующий StarGate через неделю.
 * TZ намеренно America/New_York (как на проде) — результат от неё зависеть не должен.
 *
 * Запуск: php tests/test_schedule_data.php
 */
require_once __DIR__ . '/../autoload.php';
date_default_timezone_set('America/New_York');

$fail = 0;
function ok($cond, $label) {
    global $fail;
    echo ($cond ? "  [OK] " : "  [FAIL] ") . $label . "\n";
    if (!$cond) $fail++;
}
$u = fn(string $s) => strtotime($s . ' UTC');
$occ = fn(int $id, string $startUtc, int $dur, string $title, string $desc = '') => [
    'id' => $id, 'title' => $title, 'description' => $desc, 'duration_minutes' => $dur,
    'use_playlist' => 0, 'generate_new_playlist' => 0,
    'real_start_time' => $u($startUtc), 'run_id' => $id . '_' . $u($startUtc),
];
$stargate = $occ(18, '2026-09-05 16:00:00', 240, 'Поняшный вечерок - StarGate', 'Смотрим Звёздные Врата до упора.');
$phoenix  = $occ(19, '2026-09-05 21:00:00', 240, 'Поняшный вечерок с Фениксом Райтом', 'Ace Attorney, оригинальная трилогия.');
$nextWeek = $occ(18, '2026-09-12 16:00:00', 240, 'Поняшный вечерок - StarGate');
$all = [$stargate, $phoenix, $nextWeek];

echo "== /расписание во время StarGate (22:34 МСК) ==\n";
$now = $u('2026-09-05 19:34:00');
$snap = ScheduleData::snapshot($all, $now);
ok($snap['current'] === $stargate, 'current = идущий StarGate');
ok($snap['next'] === $phoenix, 'next = Феникс');
ok($snap['target'] === null, 'target без run_id пуст');
$block = ScheduleData::dataBlock($snap);
ok(str_contains($block, '- Сейчас: суббота, 05.09.2026, 22:34 (МСК).'), 'строка «сейчас» в МСК');
ok(str_contains($block, "- Идёт прямо сейчас: 'Поняшный вечерок - StarGate' — началось сегодня в 19:00 МСК, идёт уже 3 ч 34 мин, закончится около 23:00 (через 26 мин). Описание: Смотрим Звёздные Врата до упора."), 'идущее: началось / идёт уже / закончится около');
ok(str_contains($block, "- Следующее событие: 'Поняшный вечерок с Фениксом Райтом' — сегодня ночью в 00:00 МСК, до начала 1 ч 26 мин (длится около 4 ч). Описание: Ace Attorney, оригинальная трилогия."), 'следующее: полночь МСК — «сегодня ночью», интервал посчитан');
ok(!str_contains($block, 'Событие, о котором'), 'без target нет строки анонса');
ok(!str_contains($block, '12.09'), 'событие через неделю в блок не попало (только следующее)');
ok(str_contains(ScheduleData::taskLine($snap), 'не пиши «до начала осталось»'), 'задача: про идущее не «до начала»');

echo "\n== 15м-анонс Феникса при идущем StarGate (старое время Феникса 23:00, как 05.09) ==\n";
$phoenixOld = $occ(19, '2026-09-05 20:00:00', 240, 'Феникс (старое время)');
$now = $u('2026-09-05 19:46:00');
$snap = ScheduleData::snapshot([$stargate, $phoenixOld, $nextWeek], $now, $phoenixOld['run_id']);
ok($snap['target'] === $phoenixOld, 'target по run_id = Феникс');
ok($snap['current'] === $stargate && $snap['next'] === $phoenixOld, 'current = StarGate, next = Феникс');
$block = ScheduleData::dataBlock($snap);
$lines = explode("\n", $block);
ok(str_contains($lines[2], "- Событие, о котором тебя просят написать: 'Феникс (старое время)' — сегодня в 23:00 МСК, до начала 14 мин (длится около 4 ч)."), 'целевое событие — первым, с интервалом до старта');
ok(str_contains($lines[3], "- Идёт прямо сейчас: 'Поняшный вечерок - StarGate'") && str_contains($lines[3], 'закончится около 23:00 (через 14 мин)'), 'идущее событие — рядом, для контекста');
ok(count($lines) === 4, 'next = target → не дублируется (строк: ' . count($lines) . ')');

echo "\n== 15м-анонс Феникса после конца StarGate (23:46 МСК, исправленное время) ==\n";
$now = $u('2026-09-05 20:46:00');
$snap = ScheduleData::snapshot($all, $now, $phoenix['run_id']);
ok($snap['current'] === null, 'идущего нет (StarGate закончился)');
$block = ScheduleData::dataBlock($snap);
ok(str_contains($block, "'Поняшный вечерок с Фениксом Райтом' — сегодня ночью в 00:00 МСК, до начала 14 мин"), 'до начала 14 мин, полночь — «сегодня ночью»');
ok(!str_contains($block, 'Идёт прямо сейчас'), 'строки «идёт» нет');

echo "\n== finished-анонс StarGate (23:02 МСК) ==\n";
$now = $u('2026-09-05 20:02:00');
$snap = ScheduleData::snapshot($all, $now, $stargate['run_id']);
$block = ScheduleData::dataBlock($snap);
ok(str_contains($block, "- Событие, о котором тебя просят написать: 'Поняшный вечерок - StarGate' — уже закончилось: шло сегодня с 19:00 до 23:00 МСК, завершилось 2 мин назад. Описание: Смотрим Звёздные Врата до упора."), 'закончившееся: шло с/до, завершилось N назад');
ok(str_contains($block, "- Следующее событие: 'Поняшный вечерок с Фениксом Райтом' — сегодня ночью в 00:00 МСК, до начала 58 мин"), 'следующее — Феникс через 58 мин');
ok(ScheduleData::following($all, $stargate['run_id'], $now) === $phoenix, 'following: Феникс ровно через час после конца — стык (граница включительно)');
ok(ScheduleData::following($all, $stargate['run_id'], $now, 3599) === null, 'following: окно на секунду короче — не стык');
ok(ScheduleData::following($all, $phoenix['run_id'], $now) === null, 'following для Феникса: следующий StarGate через неделю — не стык');
ok(ScheduleData::following($all, 'nope_0', $now) === null, 'following: неизвестный run_id → null');

echo "\n== пустое расписание ==\n";
$snap = ScheduleData::snapshot([], $now);
ok(str_contains(ScheduleData::dataBlock($snap), 'Расписание пусто'), 'блок: расписание пусто');
ok(str_contains(ScheduleData::taskLine($snap), 'об отсутствии ближайших событий'), 'задача: об отсутствии событий');
$snap = ScheduleData::snapshot([$stargate], $u('2026-09-06 12:00:00'));
ok($snap['current'] === null && $snap['next'] === null && str_contains(ScheduleData::dataBlock($snap), 'Расписание пусто'), 'только прошедшие → пусто');

echo "\n== независимость от TZ сервера ==\n";
$now = $u('2026-09-05 19:34:00');
$ny = ScheduleData::dataBlock(ScheduleData::snapshot($all, $now));
date_default_timezone_set('UTC');
ok(ScheduleData::dataBlock(ScheduleData::snapshot($all, $now)) === $ny, 'блок одинаков под America/New_York и UTC');

echo "\n" . ($fail === 0 ? "ALL PASS\n" : "FAILURES: $fail\n");
exit($fail === 0 ? 0 : 1);
