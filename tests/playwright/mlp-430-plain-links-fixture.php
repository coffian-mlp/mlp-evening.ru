<?php
require dirname(__DIR__) . '/integration_helpers.php';
if (PHP_SAPI !== 'cli' || !is_file('/.dockerenv') || (it_config()['db']['host'] ?? '') !== 'db') exit(2);
$db = it_require_db();
$path = dirname(__DIR__, 2) . '/docs/private/mlp430-local.json';
$config = Infra\ConfigManager::getInstance();
$users = new Domain\UserManager();
if (($argv[1] ?? '') === 'setup') {
    if (is_file($path)) throw new RuntimeException('Fixture already exists');
    $state = ['login' => 'it_user_mlp430_' . bin2hex(random_bytes(6)), 'password' => bin2hex(random_bytes(24)), 'options' => []];
    foreach (['ai_enabled', 'chat_rate_limit'] as $key) $state['options'][$key] = $config->getOption($key, null);
    $mask = umask(0077);
    file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR));
    umask($mask);
    $config->setOption('ai_enabled', '0');
    $config->setOption('chat_rate_limit', '0');
    $state['userId'] = $users->createUser($state['login'], $state['password'], 'user', 'Plain link fixture');
    file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR));
    Domain\Privacy::setAccountConsent($state['userId'], true);
} elseif (($argv[1] ?? '') === 'cleanup') {
    $state = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!empty($state['userId'])) {
        $user = $users->getUserById($state['userId']);
        if (!$user || $user['login'] !== $state['login'] || !str_starts_with($user['login'], 'it_user_mlp430_')) throw new RuntimeException('Fixture ownership mismatch');
        $stmt = $db->prepare('DELETE FROM chat_messages WHERE user_id=?');
        $stmt->bind_param('i', $state['userId']);
        $stmt->execute();
        Domain\Privacy::stageErasure($state['userId'], []);
        $users->deleteUser($state['userId']);
    }
    foreach ($state['options'] as $key => $value) {
        if ($value === null) {
            $stmt = $db->prepare('DELETE FROM site_options WHERE key_name=?');
            $stmt->bind_param('s', $key); $stmt->execute();
        } else $config->setOption($key, $value);
    }
    $config->flushCache();
    unlink($path);
} else exit(2);
echo "Fixture ready\n";
