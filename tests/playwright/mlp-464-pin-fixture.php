<?php
/** Synthetic pin fixtures; CLI in the disposable Docker database only. */
require_once dirname(__DIR__) . '/integration_helpers.php';
if (PHP_SAPI !== 'cli' || !is_file('/.dockerenv') || (it_config()['db']['host'] ?? '') !== 'db'
    || !is_file(dirname(__DIR__, 2) . '/.mlp464-owned')) {
    throw new RuntimeException('MLP-464 disposable Docker environment required');
}
it_require_db();
$path = dirname(__DIR__, 2) . '/docs/private/mlp464-local.json';
if (is_file($path)) throw new RuntimeException('Fixture already exists; discard the disposable environment first');
$config = Infra\ConfigManager::getInstance();
$config->setOption('ai_enabled', '0');
$config->flushCache();
$users = new Domain\UserManager();
$chat = new Domain\ChatManager();
$fixture = [];
foreach (['user', 'moderator'] as $role) {
    $login = 'it_mlp464_' . $role . '_' . bin2hex(random_bytes(4));
    $password = bin2hex(random_bytes(24));
    $id = $users->createUser($login, $password, $role, 'Pin test ' . $role);
    Domain\Privacy::setAccountConsent($id, true);
    $fixture[$role] = ['login' => $login, 'password' => $password, 'id' => $id];
}
foreach (['First synthetic pinned message', 'Second synthetic pinned message'] as $text) {
    $id = $chat->addMessage($fixture['user']['id'], $fixture['user']['login'], $text);
    if (!$id) throw new RuntimeException('Fixture message not created');
    $fixture['messages'][] = $id;
}
umask(0077);
if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
file_put_contents($path, json_encode($fixture, JSON_THROW_ON_ERROR));
chmod($path, 0600);
echo "MLP-464 synthetic fixture ready\n";
