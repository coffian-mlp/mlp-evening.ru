<?php
require_once dirname(__DIR__) . '/integration_helpers.php';
if (PHP_SAPI !== 'cli' || !is_file('/.dockerenv') || (it_config()['db']['host'] ?? '') !== 'db') exit(1);
it_require_db();
$db = Infra\Database::getInstance()->getConnection();
$users = new Domain\UserManager();
$path = dirname(__DIR__, 2) . '/docs/private/mlp392-local.json';
$action = $argv[1] ?? '';
if ($action === 'setup') {
    if (is_file($path)) throw new RuntimeException('Existing fixture');
    $s = ['login' => 'it_mlp392_' . bin2hex(random_bytes(6)), 'password' => bin2hex(random_bytes(24))];
    $s['actor'] = $users->createUser($s['login'], $s['password'], 'admin');
    $s['target'] = $users->createUser('it_mlp392_target_' . bin2hex(random_bytes(6)), bin2hex(random_bytes(24)), 'moderator');
    $old = umask(0077); file_put_contents($path, json_encode($s, JSON_THROW_ON_ERROR)); umask($old); chmod($path, 0600);
} else {
    $s = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    foreach (['actor', 'target'] as $key) {
        if (!str_starts_with($users->getUserById($s[$key])['login'] ?? '', 'it_mlp392_')) throw new RuntimeException('Unexpected fixture owner');
    }
    $actor = (int)$s['actor']; $target = (int)$s['target'];
    if ($action === 'downgrade') $users->updateUser($actor,['roles'=>['user','moderator']]);
    elseif ($action === 'revoke') $users->updateUser($actor,['roles'=>['user']]);
    elseif ($action === 'cleanup') {
        $db->query("DELETE FROM audit_logs WHERE user_id = $actor AND target_id = $target");
        $users->deleteUser($target); $users->deleteUser($actor); unlink($path);
    } else throw new RuntimeException('Unknown fixture operation');
}
echo "Fixture operation complete\n";
