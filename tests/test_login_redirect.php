<?php
/**
 * Юнит-тест Auth::sanitizeLocalRedirect (MLP-256).
 *
 * Страница /login.php принимает ?redirect= — единственная защита от
 * open redirect и header injection. Пропускаются ТОЛЬКО локальные пути
 * (одиночный ведущий «/»), всё остальное → null.
 *
 * БД не нужна: метод — чистая статика.
 *
 * Запуск: php tests/test_login_redirect.php
 */

require_once __DIR__ . '/../autoload.php';

use Domain\Auth;

$fail = 0;
function ok($cond, $label) {
    global $fail;
    echo ($cond ? "  [OK] " : "  [FAIL] ") . $label . "\n";
    if (!$cond) $fail++;
}

echo "== Валидные локальные пути проходят как есть ==\n";
ok(Auth::sanitizeLocalRedirect('/dashboard/') === '/dashboard/', '/dashboard/');
ok(Auth::sanitizeLocalRedirect('/') === '/', 'корень /');
ok(Auth::sanitizeLocalRedirect('/dashboard/index.php#tab-users') === '/dashboard/index.php#tab-users', 'путь с хешем');
ok(Auth::sanitizeLocalRedirect('/a?b=c&d=e') === '/a?b=c&d=e', 'путь с query');

echo "\n== Open redirect отклоняется ==\n";
ok(Auth::sanitizeLocalRedirect('//evil.com/x') === null, '//host (protocol-relative)');
ok(Auth::sanitizeLocalRedirect('https://evil.com') === null, 'абсолютный URL со схемой');
ok(Auth::sanitizeLocalRedirect('http://evil.com') === null, 'http-URL');
ok(Auth::sanitizeLocalRedirect('/\\evil.com') === null, '/\\host (backslash-трюк)');
ok(Auth::sanitizeLocalRedirect('javascript:alert(1)') === null, 'javascript:-схема');
ok(Auth::sanitizeLocalRedirect('dashboard/') === null, 'относительный путь без /');

echo "\n== Header injection и мусор отклоняются ==\n";
ok(Auth::sanitizeLocalRedirect("/x\r\nSet-Cookie: a=b") === null, 'CR-LF внутри');
ok(Auth::sanitizeLocalRedirect("/x\0") === null, 'NUL-байт');
ok(Auth::sanitizeLocalRedirect('') === null, 'пустая строка');
ok(Auth::sanitizeLocalRedirect(null) === null, 'null');
ok(Auth::sanitizeLocalRedirect(['/a']) === null, 'массив (не строка)');
ok(Auth::sanitizeLocalRedirect('/a\\b') === null, 'обратный слэш в середине');

echo "\n" . ($fail === 0 ? "ALL PASS" : "FAILED: $fail") . "\n";
exit($fail === 0 ? 0 : 1);
