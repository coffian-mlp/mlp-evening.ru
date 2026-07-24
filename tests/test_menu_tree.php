<?php
/**
 * Юнит-тест дерева меню (MLP-259): buildTree / filterForViewer / sanitizeUrl.
 *
 * Ключевой инвариант безопасности: кешируется полное дерево, а роли
 * фильтруются после — filterForViewer обязан прятать admin-пункты от гостя.
 *
 * БД не нужна. Запуск: php tests/test_menu_tree.php
 */

require_once __DIR__ . '/../autoload.php';

use Domain\MenuManager;

$fail = 0;
function ok($cond, $label) {
    global $fail;
    echo ($cond ? "  [OK] " : "  [FAIL] ") . $label . "\n";
    if (!$cond) $fail++;
}

function item(int $id, ?int $parent, string $title, ?string $url, string $vis = 'all', array $extra = []): array {
    return array_merge([
        'id' => $id, 'parent_id' => $parent, 'title' => $title, 'url' => $url,
        'sort_order' => $id * 10, 'is_active' => 1, 'visibility' => $vis,
        'is_external' => 0, 'show_in_header' => 1, 'show_in_burger' => 1,
    ], $extra);
}

echo "== buildTree ==\n";
$rows = [
    item(1, null, 'Стрим', '/'),
    item(2, null, 'Комнаты', null),
    item(3, 2, 'Комната А', '/p/room-a'),
    item(4, 2, 'Комната Б', '/p/room-b'),
    item(5, 99, 'Сирота', '/lost'), // родитель не существует
];
$tree = MenuManager::buildTree($rows);
ok(count($tree) === 2, 'два корня (сирота не рендерится)');
ok($tree[1]['title'] === 'Комнаты' && count($tree[1]['children']) === 2, 'дети собраны под родителем');
ok($tree[0]['children'] === [], 'у листа пустые children');

echo "\n== filterForViewer: роли ==\n";
$tree = MenuManager::buildTree([
    item(1, null, 'Стрим', '/'),
    item(2, null, 'Профиль', '/profile', 'users'),
    item(3, null, 'Админка', '/dashboard/', 'admins'),
]);
$guest = MenuManager::filterForViewer($tree, 'all', 'header');
ok(count($guest) === 1 && $guest[0]['title'] === 'Стрим', 'гость видит только all');
$user = MenuManager::filterForViewer($tree, 'users', 'header');
ok(count($user) === 2, 'юзер видит all+users, но не admins');
$admin = MenuManager::filterForViewer($tree, 'admins', 'header');
ok(count($admin) === 3, 'админ видит всё');

echo "\n== filterForViewer: подача header/burger ==\n";
$tree = MenuManager::buildTree([
    item(1, null, 'Оба', '/a'),
    item(2, null, 'Только шапка', '/b', 'all', ['show_in_burger' => 0]),
    item(3, null, 'Только бургер', '/c', 'all', ['show_in_header' => 0]),
]);
$header = array_column(MenuManager::filterForViewer($tree, 'all', 'header'), 'title');
$burger = array_column(MenuManager::filterForViewer($tree, 'all', 'burger'), 'title');
ok($header === ['Оба', 'Только шапка'], 'header-набор по флагу');
ok($burger === ['Оба', 'Только бургер'], 'burger-набор по флагу');

echo "\n== filterForViewer: подача mobile — объединение (MLP-290) ==\n";
$mobile = array_column(MenuManager::filterForViewer($tree, 'all', 'mobile'), 'title');
ok($mobile === ['Оба', 'Только шапка', 'Только бургер'], 'mobile = header ∪ burger, порядок sort_order');
// Прецедент бага: админ-пункт «только шапка» обязан попасть в мобильный бургер.
$tree2 = MenuManager::buildTree([
    item(1, null, 'Админка', '/dashboard/', 'admins', ['show_in_burger' => 0]),
]);
ok(MenuManager::filterForViewer($tree2, 'admins', 'mobile') !== [], 'header-only админ-пункт виден в mobile-наборе');
ok(MenuManager::filterForViewer($tree2, 'admins', 'burger') === [], 'в чистом burger-наборе его по-прежнему нет');
// Дети тоже объединяются
$tree3 = MenuManager::buildTree([
    item(1, null, 'Комнаты', null),
    item(2, 1, 'Только шапка', '/p/a', 'all', ['show_in_burger' => 0]),
    item(3, 1, 'Только бургер', '/p/b', 'all', ['show_in_header' => 0]),
]);
$m3 = MenuManager::filterForViewer($tree3, 'all', 'mobile');
ok(count($m3) === 1 && count($m3[0]['children']) === 2, 'mobile объединяет и детей раскрывашки');

echo "\n== filterForViewer: пустые раскрывашки ==\n";
$tree = MenuManager::buildTree([
    item(1, null, 'Комнаты', null),
    item(2, 1, 'Секретная', '/p/x', 'admins'),
]);
ok(MenuManager::filterForViewer($tree, 'all', 'header') === [], 'раскрывашка без видимых детей скрыта у гостя');
$adm = MenuManager::filterForViewer($tree, 'admins', 'header');
ok(count($adm) === 1 && count($adm[0]['children']) === 1, 'у админа раскрывашка с ребёнком');

echo "\n== sanitizeUrl ==\n";
ok(MenuManager::sanitizeUrl('/schedule.php') === '/schedule.php', 'локальный путь проходит');
ok(MenuManager::sanitizeUrl('https://discord.gg/x') === 'https://discord.gg/x', 'https проходит');
ok(MenuManager::sanitizeUrl('javascript:alert(1)') === null, 'javascript: режется');
ok(MenuManager::sanitizeUrl('//evil.com') === null, 'protocol-relative режется');
ok(MenuManager::sanitizeUrl('') === null, 'пустой → null (раскрывашка)');
ok(MenuManager::sanitizeUrl('  /a  ') === '/a', 'trim');

echo "\n" . ($fail === 0 ? "ALL PASS" : "FAILED: $fail") . "\n";
exit($fail === 0 ? 0 : 1);
