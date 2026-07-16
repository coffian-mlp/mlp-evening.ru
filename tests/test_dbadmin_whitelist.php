<?php
/**
 * Юнит-тест DbAdmin identifier-whitelist — H3 (MLP-230).
 *
 * Имена таблиц/колонок в DbAdmin подставляются в SQL внутри backticks и не
 * параметризуемы. Единственная защита — сверка со схемой. Здесь проверяем
 * pure-хелперы решения (isKnown / keepKnownColumns), на которых стоит гейт.
 *
 * БД не нужна: статические методы не обращаются к Database/Auth.
 *
 * Запуск: php tests/test_dbadmin_whitelist.php
 */

require_once __DIR__ . '/../src/Core/Component.php';
require_once __DIR__ . '/../src/Components/DbAdmin/class.php';

use Components\DbAdmin\DbAdminComponent;

$fail = 0;
function ok($cond, $label) {
    global $fail;
    echo ($cond ? "  [OK] " : "  [FAIL] ") . $label . "\n";
    if (!$cond) $fail++;
}

$tables = ['users', 'chat_messages', 'events', 'bot_commands'];

echo "== isKnown: пропускает только точное имя из схемы ==\n";
ok(DbAdminComponent::isKnown('users', $tables) === true,  'реальная таблица проходит');
ok(DbAdminComponent::isKnown('events', $tables) === true, 'реальная таблица проходит (2)');
ok(DbAdminComponent::isKnown('USERS', $tables) === false, 'регистр важен — USERS отклонён');
ok(DbAdminComponent::isKnown('', $tables) === false,      'пустая строка отклонена');
ok(DbAdminComponent::isKnown('chat_%', $tables) === false, 'LIKE-wildcard отклонён');
ok(DbAdminComponent::isKnown('users`; DROP TABLE users;--', $tables) === false, 'injection-строка отклонена');
ok(DbAdminComponent::isKnown('nonexistent', $tables) === false, 'несуществующая таблица отклонена');
ok(DbAdminComponent::isKnown(null, $tables) === false,   'null отклонён');

echo "\n== keepKnownColumns: оставляет только реальные колонки ==\n";
$cols = ['id', 'nickname', 'email'];
$in = ['id' => 5, 'nickname' => 'Lyra', 'is_admin' => 1, '`evil`' => 'x'];
$out = DbAdminComponent::keepKnownColumns($in, $cols);
ok($out === ['id' => 5, 'nickname' => 'Lyra'], 'неизвестные ключи (is_admin, `evil`) отброшены');
ok(DbAdminComponent::keepKnownColumns(['id' => 1], []) === [], 'пустой whitelist → ничего не проходит');
ok(DbAdminComponent::keepKnownColumns([], $cols) === [], 'пустой ввод → пустой вывод');

echo "\n" . ($fail === 0 ? "ALL PASS\n" : "FAILURES: $fail\n");
exit($fail === 0 ? 0 : 1);
