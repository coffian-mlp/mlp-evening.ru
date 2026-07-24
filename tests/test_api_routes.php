<?php
/**
 * Юнит-тест карты тонкого роутера api.php (MLP-255).
 *
 * Проверяет три инварианта миграции «switch → контроллеры»:
 *   1) каждый перенесённый action есть в карте и объявлен с ТОЙ ЖЕ ролью,
 *      что его гейт до переноса (сверка «было/стало», AC4);
 *   2) хендлеры существуют и вызываемы (класс + статический метод, autoload);
 *   3) в legacy-switch api.php перенесённых веток больше нет, а роли в карте —
 *      только из допустимого набора public|user|moderator|admin.
 *
 * БД не нужна: карта — чистый массив (src/Api/routes.php).
 *
 * Запуск: php tests/test_api_routes.php
 */

require_once __DIR__ . '/../autoload.php';

$fail = 0;
function ok($cond, $label) {
    global $fail;
    echo ($cond ? "  [OK] " : "  [FAIL] ") . $label . "\n";
    if (!$cond) $fail++;
}

$routes = require __DIR__ . '/../src/Api/routes.php';

// Сверочная таблица «action → роль» (гейт ДО переноса = роль ПОСЛЕ).
$expected = [
    // MLP-229/238/242/245 (были в роутере до MLP-255)
    'get_public_events'   => 'public',
    'save_event'          => 'admin',
    'delete_event'        => 'admin',
    'get_poll'            => 'public',
    'create_poll'         => 'user',
    'vote_poll'           => 'user',
    'close_poll'          => 'user',
    'get_pinned'          => 'public',
    'pin_message'         => 'user',
    'unpin_message'       => 'user',
    'captcha_start'       => 'public',
    'captcha_check'       => 'public',
    'heartbeat'           => 'public',
    'leave'               => 'public',
    // MLP-255: настройки и плейлист (было: Auth::requireApiAdmin в ветках)
    'update_settings'     => 'admin',
    'regenerate_playlist' => 'admin',
    'vote'                => 'admin',
    'mark_watched'        => 'admin',
    'clear_votes'         => 'admin',
    'reset_times_watched' => 'admin',
    'clear_watching_log'  => 'admin',
    // MLP-255: администрирование пользователей (было: requireApiAdmin)
    'get_users'           => 'admin',
    'get_user_options'    => 'admin',
    'get_audit_logs'      => 'admin',
    'save_user'           => 'admin',
    'delete_user'         => 'admin',
    // MLP-258: соц-привязки из карточки пользователя
    'get_user_socials_admin' => 'admin',
    'unlink_social_admin'    => 'admin',
    // MLP-259: меню сайта
    'get_menu_items'      => 'admin',
    'save_menu_item'      => 'admin',
    'delete_menu_item'    => 'admin',
    'move_menu_item'      => 'admin',
    // MLP-255: модерация (было: Auth::isModerator() в ветках)
    'ban_user'            => 'moderator',
    'unban_user'          => 'moderator',
    'mute_user'           => 'moderator',
    'unmute_user'         => 'moderator',
    'purge_messages'      => 'moderator',
    // MLP-255: стикеры (чтение БЫЛО публичным — пикер чата у гостей!)
    'get_packs'           => 'public',
    'get_stickers'        => 'public',
    'create_pack'         => 'admin',
    'update_pack'         => 'admin',
    'delete_pack'         => 'admin',
    'add_sticker'         => 'admin',
    'import_zip_stickers' => 'admin',
    'delete_sticker'      => 'admin',
    // MLP-255: команды бота (было: isAdmin в компоненте + свой CSRF)
    'save_bot_command'    => 'admin',
    'delete_bot_command'  => 'admin',
    // MLP-255: панель БД (было: requireAdmin страницы + свой CSRF)
    'db_get_row'          => 'admin',
    'db_update_row'       => 'admin',
    'db_export'           => 'admin',
    // MLP-264: срез auth/profile/socials (было: if-цепочка + switch api.php;
    // публичные и раньше были в guest-whitelist, user-гейт был requireApiLogin)
    'login'                 => 'public',
    'register'              => 'public',
    'forgot_password'       => 'public',
    'reset_password_submit' => 'public',
    'social_login'          => 'public',
    'logout'                => 'user',
    'bind_social'           => 'user',
    'update_profile'        => 'user',
    'save_user_option'      => 'user',
    'get_user_socials'      => 'user',
    'unlink_social'         => 'user',
    // MLP-265: чат-срез (get_chat_input/get_messages были публичными до гейта/в whitelist)
    'get_chat_input'      => 'public',
    'get_messages'        => 'public',
    'search_messages'     => 'user',
    'get_message_context' => 'user',
    'toggle_reaction'     => 'user',
    'send_message'        => 'user',
    'edit_message'        => 'user',
    'delete_message'      => 'user',
    'restore_message'     => 'user',
    'upload_file'         => 'user',
    // MLP-270: беклог фидбека (дашборд)
    'get_feedback'        => 'admin',
    'get_lyra_metrics'    => 'admin',
    'set_feedback_status' => 'admin',
];

echo "== Карта ролей: каждый action объявлен с гейтом «как было» ==\n";
foreach ($expected as $action => $role) {
    ok(isset($routes[$action]), "action '$action' есть в карте");
    ok(($routes[$action]['role'] ?? '?') === $role, "  роль '$action' = $role");
}

echo "\n== В карте нет лишних actions (полное соответствие сверочной таблице) ==\n";
$extra = array_diff(array_keys($routes), array_keys($expected));
ok($extra === [], 'лишних actions нет' . ($extra ? ' (найдены: ' . implode(', ', $extra) . ')' : ''));

echo "\n== Роли только из допустимого набора ==\n";
$allowedRoles = ['public', 'user', 'moderator', 'admin'];
$badRoles = [];
foreach ($routes as $action => $r) {
    if (!in_array($r['role'] ?? null, $allowedRoles, true)) $badRoles[] = $action;
}
ok($badRoles === [], 'все роли валидны' . ($badRoles ? ' (плохие: ' . implode(', ', $badRoles) . ')' : ''));

echo "\n== Хендлеры существуют и вызываемы (autoload) ==\n";
$badHandlers = [];
foreach ($routes as $action => $r) {
    $h = $r['handler'] ?? null;
    if (!is_array($h) || count($h) !== 2 || !class_exists($h[0]) || !method_exists($h[0], $h[1])) {
        $badHandlers[] = $action;
    }
}
ok($badHandlers === [], 'все хендлеры вызываемы' . ($badHandlers ? ' (плохие: ' . implode(', ', $badHandlers) . ')' : ''));

// Runtime-зависимости контроллеров вне PSR-4 (ревью MLP-255: class.php компонентов
// не резолвился автолоадером — DbAdminController падал fatal; фикс — компонентная
// ветка в autoload.php). Проверяем разрешимость через autoload, не через require.
ok(class_exists('Components\\DbAdmin\\DbAdminComponent'),
   'DbAdminComponent автозагружаем (зависимость DbAdminController)');

echo "\n== Guest-гейт выводится из карты, не из ручного списка (MLP-282) ==\n";
$apiSrc = file_get_contents(__DIR__ . '/../api.php');
ok(!preg_match("/in_array\\(\\\$action,\\s*\\['get_chat_input'/", $apiSrc),
   'ручной guest-whitelist удалён из api.php');
ok(strpos($apiSrc, "!== 'public'") !== false,
   'гостевой гейт сверяется с role=public из карты');
// Смысловая сверка: набор public-маршрутов карты = ожидаемый (страхует от
// случайного открытия user-действия гостям при правке routes.php).
$publicNow = array_keys(array_filter($routes, static fn($r) => ($r['role'] ?? '') === 'public'));
$publicExpected = array_keys(array_filter($expected, static fn($role) => $role === 'public'));
sort($publicNow); sort($publicExpected);
ok($publicNow === $publicExpected,
   'public-набор карты совпадает со сверочной таблицей (' . count($publicNow) . ' шт.)');

echo "\n== Legacy-switch: перенесённых веток больше нет ==\n";
$migrated = [
    'update_settings', 'regenerate_playlist', 'mark_watched', 'clear_votes',
    'reset_times_watched', 'clear_watching_log', 'get_users', 'get_user_options',
    'get_audit_logs', 'save_user', 'delete_user', 'ban_user', 'unban_user',
    'mute_user', 'unmute_user', 'purge_messages', 'get_packs', 'create_pack',
    'update_pack', 'delete_pack', 'get_stickers', 'add_sticker',
    'import_zip_stickers', 'delete_sticker',
];
$leftovers = [];
foreach ($migrated as $action) {
    if (preg_match("/case\\s+'" . preg_quote($action, '/') . "'/", $apiSrc)) $leftovers[] = $action;
}
ok($leftovers === [], 'в switch не осталось admin-веток' . ($leftovers ? ' (остались: ' . implode(', ', $leftovers) . ')' : ''));

// Устаревшие транспорты убраны
$dashSrc = file_get_contents(__DIR__ . '/../dashboard/index.php');
ok(strpos($dashSrc, "db_action") === false || !preg_match('/\$_(GET|POST)\[.db_action.\]/', $dashSrc), 'dashboard/index.php не обрабатывает db_action');
$botCmdSrc = file_get_contents(__DIR__ . '/../src/Components/AdminBotCommands/class.php');
ok(strpos($botCmdSrc, "create_command") === false, 'AdminBotCommands не обрабатывает POST-мутации');

echo "\n" . ($fail === 0 ? "ALL PASS" : "FAILED: $fail") . "\n";
exit($fail === 0 ? 0 : 1);
