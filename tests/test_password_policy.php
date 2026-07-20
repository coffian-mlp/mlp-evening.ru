<?php
use Domain\Auth;
/**
 * Юнит-тест Auth::validatePasswordPolicy() — L5 (MLP-232).
 * Политика: min 8 символов, max 72 БАЙТА (лимит bcrypt), без обязательной сложности.
 *
 * Auth тянет Database.php → config.php: на чистом клоне без конфига мягко SKIP.
 *
 * Запуск: php tests/test_password_policy.php
 */

if (!file_exists(__DIR__ . '/../config.php')) {
    echo "SKIP: config.php отсутствует (нужен для загрузки Auth.php)\n";
    exit(0);
}

require_once __DIR__ . '/../autoload.php';

$fail = 0;
function ok($cond, $label) {
    global $fail;
    echo ($cond ? "  [OK] " : "  [FAIL] ") . $label . "\n";
    if (!$cond) $fail++;
}

echo "== Слишком короткие отклоняются ==\n";
ok(Auth::validatePasswordPolicy('') !== null,        'пустой пароль отклонён');
ok(Auth::validatePasswordPolicy('1234567') !== null, '7 символов отклонены (< 8)');

echo "\n== Валидные проходят (null) ==\n";
ok(Auth::validatePasswordPolicy('12345678') === null,     'ровно 8 символов проходит');
ok(Auth::validatePasswordPolicy('correct horse') === null, 'длинная фраза без спецсимволов проходит (сложность не требуется)');
ok(Auth::validatePasswordPolicy(str_repeat('a', 72)) === null, 'ровно 72 байта проходит');

echo "\n== Верхняя граница bcrypt (72 байта) ==\n";
ok(Auth::validatePasswordPolicy(str_repeat('a', 73)) !== null, '73 ASCII-байта отклонены');
// Кириллица: 8 символов = 16 байт (проходит по символам и по байтам)
ok(Auth::validatePasswordPolicy('пароль12') === null, '8 кириллических/смешанных символов проходят');
// 37 кириллических символов = 74 байта > 72 → отклонить по байтам, хотя символов < 72
ok(Auth::validatePasswordPolicy(str_repeat('я', 37)) !== null, '37 кириллиц (74 байта) отклонены по байтовому лимиту');

echo "\n== Тип-guard ==\n";
ok(Auth::validatePasswordPolicy(null) !== null, 'null отклонён');

echo "\n" . ($fail === 0 ? "ALL PASS\n" : "FAILURES: $fail\n");
exit($fail === 0 ? 0 : 1);
