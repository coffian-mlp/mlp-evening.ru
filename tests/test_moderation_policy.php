<?php
use Domain\ModerationPolicy;
/**
 * Юнит-тест иерархии санкций (MLP-350): одна политика для контекстного меню и команд /бан, /мут.
 * Тексты отказов — те же, что были в ModerationController до выноса. Pure, без БД.
 *
 * Запуск: php tests/test_moderation_policy.php
 */
require_once __DIR__ . '/../autoload.php';

$fail = 0;
function ok($cond, $label) {
    global $fail;
    echo ($cond ? "  [OK] " : "  [FAIL] ") . $label . "\n";
    if (!$cond) $fail++;
}

echo "== Кто кого может наказать ==\n";
ok(ModerationPolicy::check(1, 'admin', 11, 'user') === true, 'админ → участник: можно');
ok(ModerationPolicy::check(1, 'admin', 3, 'moderator') === true, 'админ → модератор: можно');
ok(ModerationPolicy::check(1, 'admin', 7, 'admin') === 'Администратор неприкосновенен!', 'админ → админ: нельзя');
ok(ModerationPolicy::check(3, 'moderator', 11, 'user') === true, 'модератор → участник: можно');
ok(ModerationPolicy::check(3, 'moderator', 4, 'moderator') === 'Модераторы не могут трогать своих коллег.', 'модератор → модератор: нельзя');
ok(ModerationPolicy::check(3, 'moderator', 1, 'admin') === 'Это Администратор. Не шали!', 'модератор → админ: нельзя');
ok(ModerationPolicy::check(11, 'user', 12, 'user') === 'У вас нет прав модератора.', 'участник → кто угодно: нельзя');

echo "\n== Края ==\n";
ok(ModerationPolicy::check(1, 'admin', 1, 'admin') === 'Нельзя применять санкции к самому себе!', 'сам себя: нельзя, проверка до ролей');
ok(ModerationPolicy::check(1, 'admin', 99, null) === 'Пользователь не найден.', 'цель не найдена');
ok(ModerationPolicy::check(1, 'admin', 1, null) === 'Нельзя применять санкции к самому себе!', 'сам себя важнее «не найден» (порядок как раньше)');

echo "\n== Команда модерации ==\n";
ok(ModerationPolicy::isStaff('admin') && ModerationPolicy::isStaff('moderator'), 'админ и модератор — команда модерации');
ok(!ModerationPolicy::isStaff('user') && !ModerationPolicy::isStaff('') && !ModerationPolicy::isStaff(null), 'участник, пусто и null — нет');

echo "\n== Срок бана и тексты (MLP-352) ==\n";
$now = strtotime('2026-09-26 19:30:00 UTC'); // 22:30 МСК
ok(ModerationPolicy::banUntil(null, $now) === null && ModerationPolicy::banUntil(0, $now) === null, 'без срока — бессрочно (NULL)');
ok(ModerationPolicy::banUntil(1, $now) === '2026-09-26 19:31:00', 'срок в UTC для users.ban_until');
foreach ([[1, '1 мин'], [60, '1 ч'], [90, '1 ч 30 мин'], [1440, '1 день'], [1500, '1 день 1 ч'], [2880, '2 дня'], [7200, '5 дней'], [30240, '21 день'], [15840, '11 дней']] as [$m, $want]) {
    ok(ModerationPolicy::durationLabel($m) === $want, "длительность {$m} мин → «{$want}»");
}
ok(ModerationPolicy::bannedNotice(null, null, $now) === '🚫 Ты в бане — писать в чат нельзя. Причина: нарушение правил.', 'бессрочный бан без причины');
ok(ModerationPolicy::bannedNotice('флуд', '2026-09-26 19:31:00', $now) === '🚫 Ты в бане до 22:31 МСК (ещё 1 мин) — писать в чат нельзя. Причина: флуд.', 'временный бан: время МСК и остаток');
ok(mb_strpos(ModerationPolicy::bannedNotice('спам', '2026-09-28 10:00:00', $now), 'до 28.09 13:00 МСК (ещё 1 день 14 ч)') !== false, 'дольше суток — с датой');
ok(ModerationPolicy::bannedNotice('x', '2026-09-26 19:00:00', $now) === '🚫 Ты в бане — писать в чат нельзя. Причина: x.', 'срок в прошлом — без времени (в чтении такой бан уже не действует)');
ok(ModerationPolicy::mutedNotice(610, 'капс') === '🤐 Ты в муте — писать можно будет через 11 мин. Причина: капс.', 'мут: остаток и причина');
ok(ModerationPolicy::mutedNotice(30, '') === '🤐 Ты в муте — писать можно будет через 1 мин.', 'мут без причины');

echo "\n";
if ($fail > 0) { echo "FAIL: $fail\n"; exit(1); }
echo "ALL PASS\n";
