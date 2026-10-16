<?php
require dirname(__DIR__) . '/integration_helpers.php';
if (PHP_SAPI !== 'cli' || !is_file('/.dockerenv') || (it_config()['db']['host'] ?? '') !== 'db') exit(2);
it_require_db();
$db = Infra\Database::getInstance()->getConnection();
$users = new Domain\UserManager();
$path = dirname(__DIR__, 2) . '/docs/private/mlp411-local.json';
$save = static function ($state) use ($path) {
    $old = umask(0077); file_put_contents($path, json_encode($state)); umask($old);
};
if (($argv[1] ?? '') === 'setup') {
    if (is_file($path)) throw new RuntimeException('Fixture exists');
    $state = ['users' => [], 'options' => [], 'password' => bin2hex(random_bytes(24)), 'suffix' => bin2hex(random_bytes(6))];
    foreach (Domain\SiteAppearance::DEFAULTS as $key => $default) {
        $stmt = $db->prepare('SELECT value FROM site_options WHERE key_name=?'); $stmt->bind_param('s', $key); $stmt->execute();
        $state['options'][$key] = $stmt->get_result()->fetch_assoc()['value'] ?? null;
    }
    $save($state);
    foreach (['admin', 'reader'] as $role) {
        $state[$role . 'Login'] = 'it_mlp411_' . $role . '_' . $state['suffix'];
        $state[$role] = $users->createUser($state[$role . 'Login'], $state['password'], $role === 'admin' ? 'admin' : 'user', 'Theme ' . ucfirst($role));
        $state['users'][] = $state[$role]; $save($state); \Domain\Privacy::setAccountConsent((int)$state[$role],true);
    }
    $text = htmlspecialchars("Theme fixture **bold** `code` ||spoiler||\n> Quote fixture", ENT_QUOTES, 'UTF-8');
    $stmt = $db->prepare("INSERT INTO chat_messages(user_id,username,message,created_at) VALUES(?,'Theme Reader',?,UTC_TIMESTAMP())");
    $stmt->bind_param('is', $state['reader'], $text); $stmt->execute();
    $state['message'] = $db->insert_id; $save($state);
    $quotes = json_encode([$state['message']]);
    $stmt = $db->prepare("INSERT INTO chat_messages(user_id,username,message,created_at,quoted_msg_ids) VALUES(?,'Theme Reader','Reply fixture',UTC_TIMESTAMP(),?)");
    $stmt->bind_param('is', $state['reader'], $quotes); $stmt->execute();
} elseif (($argv[1] ?? '') === 'cleanup') {
    $state = json_decode(file_get_contents($path), true);
    foreach ($state['options'] as $key => $value) {
        if (!array_key_exists($key, Domain\SiteAppearance::DEFAULTS)) throw new RuntimeException('Invalid option');
        if ($value === null) { $stmt = $db->prepare('DELETE FROM site_options WHERE key_name=?'); $stmt->bind_param('s', $key); $stmt->execute(); }
        else Infra\ConfigManager::getInstance()->setOption($key, $value);
    }
    foreach ($state['users'] as $id) {
        if (!str_starts_with($users->getUserById($id)['login'] ?? '', 'it_mlp411_')) throw new RuntimeException('Invalid user');
        $db->query('DELETE FROM chat_messages WHERE user_id=' . (int)$id);
        $users->deleteUser($id);
    }
    unlink($path);
} else throw new RuntimeException('Invalid action');
echo "PASS\n";
