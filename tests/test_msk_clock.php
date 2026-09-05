<?php
use LLM\MskClock;
/**
 * Юнит-тест MskClock (MLP-322): форматирование времени в МСК не зависит от date.timezone
 * сервера. TZ намеренно America/New_York — так на проде, и так Лира «видела» 15:51 при 22:51 МСК.
 *
 * Запуск: php tests/test_msk_clock.php
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

echo "== format: UTC → МСК ==\n";
ok(MskClock::format($u('2026-09-05 19:51:00'), 'H:i') === '22:51', '19:51 UTC → 22:51 МСК (а не 15:51 по TZ сервера)');
ok(MskClock::format($u('2026-09-05 21:30:00'), 'Y-m-d') === '2026-09-06', 'после 21:00 UTC — уже следующая МСК-дата');
ok(MskClock::weekday($u('2026-09-05 19:51:00')) === 'суббота', 'день недели по-русски');
$line = MskClock::nowLine($u('2026-09-05 19:34:45'));
ok($line === 'суббота, 05.09.2026, 22:34 (МСК)', "строка «сейчас»: $line");

echo "\n== dayLabel по МСК-датам ==\n";
$now = $u('2026-09-05 19:34:00'); // 22:34 МСК
ok(MskClock::dayLabel($u('2026-09-05 20:00:00'), $now) === 'сегодня', 'тот же МСК-день → сегодня');
ok(MskClock::dayLabel($u('2026-09-05 21:00:00'), $now) === 'завтра', '00:00 МСК следующего дня → завтра (по UTC ещё 05.09)');
ok(MskClock::dayLabel($u('2026-09-06 16:00:00'), $u('2026-09-05 21:30:00')) === 'сегодня', 'после полуночи МСК вечернее событие — уже сегодня');
ok(MskClock::dayLabel($u('2026-09-04 16:00:00'), $now) === 'вчера', 'вчера');
ok(MskClock::dayLabel($u('2026-09-10 16:00:00'), $now) === 'четверг, 10.09', 'дальше — день недели и дата');

echo "\n== delta ==\n";
ok(MskClock::delta(30) === 'меньше минуты', '<60с → меньше минуты');
ok(MskClock::delta(26 * 60 + 15) === '26 мин', '26 мин (секунды отброшены)');
ok(MskClock::delta(3600) === '1 ч', 'ровно час без минут');
ok(MskClock::delta(3 * 3600 + 34 * 60) === '3 ч 34 мин', '3 ч 34 мин');
ok(MskClock::delta(86400) === '1 день', '1 день');
ok(MskClock::delta(2 * 86400 + 3600) === '2 дня 1 ч', '2 дня 1 ч');
ok(MskClock::delta(5 * 86400) === '5 дней', '5 дней');
ok(MskClock::delta(-90) === '1 мин', 'знак игнорируется');

echo "\n== независимость от TZ сервера ==\n";
$ny = MskClock::nowLine($now);
date_default_timezone_set('UTC');
ok(MskClock::nowLine($now) === $ny, 'один и тот же результат под America/New_York и UTC');

echo "\n" . ($fail === 0 ? "ALL PASS\n" : "FAILURES: $fail\n");
exit($fail === 0 ? 0 : 1);
