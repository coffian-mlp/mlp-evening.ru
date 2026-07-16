<?php
/**
 * Юнит-тест раннера миграций — R7 (MLP-233).
 * Проверяет pure-функцию выбора непринятых миграций (порядок + фильтрация).
 * require migrate.php безопасен: main запускается только при прямом CLI-вызове.
 *
 * Запуск: php tests/test_migrate_runner.php
 */

require_once __DIR__ . '/../migrate.php';

$fail = 0;
function eq($got, $want, $label) {
    global $fail;
    if ($got === $want) { echo "  [OK] $label\n"; }
    else { echo "  [FAIL] $label\n        want: " . var_export($want, true) . "\n        got:  " . var_export($got, true) . "\n"; $fail++; }
}

echo "== migration_pending: только непринятые, по порядку ==\n";
$all = ['2026_07_auth_tokens.sql', '2026_07_bot_queue.sql', '2026_08_new.sql'];

eq(
    migration_pending($all, ['2026_07_auth_tokens.sql', '2026_07_bot_queue.sql']),
    ['2026_08_new.sql'],
    'применённые отфильтрованы, остаётся только новая'
);
eq(
    migration_pending($all, []),
    ['2026_07_auth_tokens.sql', '2026_07_bot_queue.sql', '2026_08_new.sql'],
    'ничего не применено → все, в лексикографическом порядке'
);
eq(
    migration_pending($all, $all),
    [],
    'всё применено → пусто'
);
eq(
    migration_pending(['b.sql', 'a.sql', 'c.sql'], ['b.sql']),
    ['a.sql', 'c.sql'],
    'результат отсортирован независимо от порядка входа'
);

echo "\n" . ($fail === 0 ? "ALL PASS\n" : "FAILURES: $fail\n");
exit($fail === 0 ? 0 : 1);
