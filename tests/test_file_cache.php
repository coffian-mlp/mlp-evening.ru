<?php
/**
 * Юнит-тест Core\FileCache (MLP-263, AR6-2) — промоция трёх файловых кешей.
 *
 * Пишет во ВРЕМЕННЫЙ каталог (параметр $root), боевой cache/ не трогает;
 * убирает за собой (правило изоляции тестов).
 *
 * Запуск: php tests/test_file_cache.php
 */

require_once __DIR__ . '/../autoload.php';

use Core\FileCache;

$fail = 0;
function ok($cond, $label) {
    global $fail;
    echo ($cond ? "  [OK] " : "  [FAIL] ") . $label . "\n";
    if (!$cond) $fail++;
}

$root = sys_get_temp_dir() . '/mlp_fc_test_' . getmypid();

try {
    $c = new FileCache('', $root);

    echo "== roundtrip ==\n";
    $data = ['пони' => 'Лира', 'nested' => ['a' => 1, 'b' => [2, 3]], 'flag' => true];
    ok($c->set('menu', $data) === true, 'set возвращает true');
    ok($c->get('menu', 60) === $data, 'get возвращает те же данные (кириллица, вложенность, типы)');
    ok(is_file("$root/menu.json"), 'файл лежит по пути <root>/<key>.json (совместим с legacy)');

    echo "== подкаталог (users/) ==\n";
    $u = new FileCache('users', $root);
    ok($u->set('user_42', ['id' => 42]) && $u->get('user_42', 60) === ['id' => 42], 'set/get в подкаталоге');
    ok(is_file("$root/users/user_42.json"), 'файл по пути <root>/users/<key>.json');

    echo "== TTL и промахи ==\n";
    ok($c->get('no_such_key', 60) === null, 'miss → null');
    touch("$root/menu.json", time() - 120);
    ok($c->get('menu', 60) === null, 'протухший (filemtime старше TTL) → null');
    ok($c->get('menu', 300) === $data, 'тот же файл с большим TTL — ещё жив');

    echo "== устойчивость ==\n";
    file_put_contents("$root/broken.json", '{оборванный json');
    ok($c->get('broken', 60) === null, 'битый JSON → null (не исключение)');
    ok($c->set('menu', ['v' => 2]) && $c->get('menu', 60) === ['v' => 2], 'повторный set перезаписывает');
    $c->delete('menu');
    ok($c->get('menu', 3600) === null && !is_file("$root/menu.json"), 'delete удаляет файл');
    $c->delete('menu'); // повторный delete не падает
    ok(true, 'delete отсутствующего ключа — тихий no-op');

    echo "== безопасность ключей ==\n";
    $bad = false;
    try { $c->set('../evil', ['x' => 1]); } catch (Throwable $e) { $bad = true; }
    ok($bad, 'ключ с ../ отвергается исключением');
    ok(!is_file(dirname($root) . '/evil.json'), 'файл вне root не создан');

} finally {
    // Уборка временного каталога.
    foreach (['users/user_42.json', 'broken.json', 'menu.json'] as $f) {
        @unlink("$root/$f");
    }
    @rmdir("$root/users");
    @rmdir($root);
}

echo "\n";
if ($fail > 0) { echo "FAIL: $fail\n"; exit(1); }
echo "ALL PASS\n";
