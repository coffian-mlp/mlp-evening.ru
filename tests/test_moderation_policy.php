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

echo "\n";
if ($fail > 0) { echo "FAIL: $fail\n"; exit(1); }
echo "ALL PASS\n";
