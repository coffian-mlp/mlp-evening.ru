<?php
/**
 * Юнит-тест построителей запросов DbAdmin (MLP-257).
 *
 * parseFilters/buildWhere/buildOrderBy — единая правда для просмотра и CSV.
 * Фиксируем семантику прежнего одиночного фильтра (research §Контракт данных):
 * неизвестная колонка → игнор, оператор вне whitelist → '=', LIKE → %...%,
 * BETWEEN «min,max» с фоллбеком; направление сортировки строго ASC|DESC.
 *
 * БД не нужна: методы — чистые статики.
 *
 * Запуск: php tests/test_dbadmin_query_builder.php
 */

require_once __DIR__ . '/../autoload.php';

use Components\DbAdmin\DbAdminComponent;

$fail = 0;
function ok($cond, $label) {
    global $fail;
    echo ($cond ? "  [OK] " : "  [FAIL] ") . $label . "\n";
    if (!$cond) $fail++;
}

$cols = ['id', 'nickname', 'created_at'];

echo "== parseFilters: массивы, legacy, мусор ==\n";
ok(DbAdminComponent::parseFilters([]) === [], 'пустой запрос → нет условий');
ok(DbAdminComponent::parseFilters([
    'filter_col' => ['id', 'nickname'], 'filter_op' => ['>', 'LIKE'], 'filter_val' => ['5', 'Lyra'],
]) === [
    ['col' => 'id', 'op' => '>', 'val' => '5'],
    ['col' => 'nickname', 'op' => 'LIKE', 'val' => 'Lyra'],
], 'два условия из массивов');
ok(DbAdminComponent::parseFilters([
    'filter_column' => 'id', 'filter_operator' => '>=', 'filter_value' => '7',
]) === [['col' => 'id', 'op' => '>=', 'val' => '7']], 'legacy-одиночный фильтр принимается');
ok(DbAdminComponent::parseFilters([
    'filter_col' => ['id', 'nickname'], 'filter_op' => ['='], 'filter_val' => ['5'],
]) === [['col' => 'id', 'op' => '=', 'val' => '5']], 'рассинхрон длин → лишнее отбрасывается');
ok(DbAdminComponent::parseFilters([
    'filter_col' => ['id', ''], 'filter_op' => ['=', '='], 'filter_val' => ['5', ''],
]) === [['col' => 'id', 'op' => '=', 'val' => '5']], 'пустые колонка/значение пропускаются');
ok(DbAdminComponent::parseFilters([
    'filter_col' => 'id', 'filter_op' => '=', 'filter_val' => '5',
]) === [], 'скаляры вместо массивов → нет условий (не фатал)');

echo "\n== buildWhere: AND-набор, семантика операторов ==\n";
ok(DbAdminComponent::buildWhere([], $cols) === ['', '', []], 'нет условий → пустой WHERE');
ok(DbAdminComponent::buildWhere([['col' => 'id', 'op' => '>', 'val' => '5']], $cols)
    === ['WHERE `id` > ?', 's', ['5']], 'одно условие');
ok(DbAdminComponent::buildWhere([
    ['col' => 'id', 'op' => '>', 'val' => '5'],
    ['col' => 'nickname', 'op' => 'LIKE', 'val' => 'Lyra'],
], $cols) === ['WHERE `id` > ? AND `nickname` LIKE ?', 'ss', ['5', '%Lyra%']], 'два условия AND, LIKE в %%');
ok(DbAdminComponent::buildWhere([['col' => 'evil', 'op' => '=', 'val' => 'x']], $cols)
    === ['', '', []], 'неизвестная колонка игнорируется (H3)');
ok(DbAdminComponent::buildWhere([
    ['col' => 'evil', 'op' => '=', 'val' => 'x'],
    ['col' => 'id', 'op' => '=', 'val' => '1'],
], $cols) === ['WHERE `id` = ?', 's', ['1']], 'неизвестная колонка не убивает остальные условия');
ok(DbAdminComponent::buildWhere([['col' => 'id', 'op' => 'UNION SELECT', 'val' => '1']], $cols)
    === ['WHERE `id` = ?', 's', ['1']], 'оператор вне whitelist → =');
ok(DbAdminComponent::buildWhere([['col' => 'created_at', 'op' => 'BETWEEN', 'val' => ' 2026-01-01 , 2026-02-01 ']], $cols)
    === ['WHERE `created_at` BETWEEN ? AND ?', 'ss', ['2026-01-01', '2026-02-01']], 'BETWEEN min,max с trim');
ok(DbAdminComponent::buildWhere([['col' => 'created_at', 'op' => 'BETWEEN', 'val' => 'кривое']], $cols)
    === ['WHERE `created_at` = ?', 's', ['кривое']], 'кривой BETWEEN → фоллбек =');

echo "\n== buildOrderBy: whitelist + направление ==\n";
ok(DbAdminComponent::buildOrderBy('id', 'desc', $cols) === 'ORDER BY `id` DESC', 'desc');
ok(DbAdminComponent::buildOrderBy('id', 'ASC', $cols) === 'ORDER BY `id` ASC', 'ASC (регистр)');
ok(DbAdminComponent::buildOrderBy('id', 'sideways', $cols) === 'ORDER BY `id` ASC', 'мусорное направление → ASC');
ok(DbAdminComponent::buildOrderBy('evil`; DROP', 'asc', $cols) === '', 'неизвестная колонка → без сортировки');
ok(DbAdminComponent::buildOrderBy('', 'asc', $cols) === '', 'пустая колонка → без сортировки');
ok(DbAdminComponent::buildOrderBy(null, null, $cols) === '', 'null → без сортировки');

echo "\n" . ($fail === 0 ? "ALL PASS" : "FAILED: $fail") . "\n";
exit($fail === 0 ? 0 : 1);
