<?php
/**
 * Изолированная CLI-фикстура MLP-360. Запускать только внутри Docker с DB_HOST=db.
 * setup создаёт отдельного пользователя и два сообщения через managers;
 * cleanup удаляет только точные ID и проверяет их владельца/маркер.
 * HTTP send_message не вызывается: фикстура не диспатчит Лиру.
 */
require_once dirname(__DIR__) . '/integration_helpers.php';

if (PHP_SAPI !== 'cli' || !is_file('/.dockerenv') || (it_config()['db']['host'] ?? '') !== 'db') {
    fwrite(STDERR, "Изолированная Docker CLI среда обязательна\n");
    exit(1);
}
$db = it_require_db();
$path = dirname(__DIR__, 2) . '/docs/private/mlp360-local.json';
$mode = $argv[1] ?? '';
$users = new Domain\UserManager();
$chat = new Domain\ChatManager();

if ($mode === 'setup') {
    if (is_file($path)) throw new RuntimeException('Сначала cleanup прежней фикстуры');
    $login = 'it_user_mlp360_' . bin2hex(random_bytes(6));
    $password = bin2hex(random_bytes(24));
    $id = $users->createUser($login, $password, 'user', 'MLP360 Test');
    $message = 'mlp360-fixture-' . bin2hex(random_bytes(6));
    $oldId = $chat->addMessage($id, $login, $message . '-legacy');
    $messageId = $chat->addMessage($id, $login, $message);
    if (!$oldId || !$messageId) throw new RuntimeException('Не создано тестовое сообщение');
    foreach (['like','dislike','laugh','cry','neutral','heart','fire','wow','think','party','cool','eyes'] as $key) {
        if (!$chat->toggleReaction($oldId, $id, $key)['success']) throw new RuntimeException('Не создана старая реакция');
    }
    $fixture = ['login'=>$login, 'password'=>$password, 'userId'=>$id,
        'messageId'=>$messageId, 'legacyId'=>$oldId, 'marker'=>$message];
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
    $previous = umask(0077);
    try {
        if (file_put_contents($path, json_encode($fixture, JSON_THROW_ON_ERROR)) === false) {
            throw new RuntimeException('Не сохранена фикстура');
        }
        chmod($path, 0600);
    } finally {
        umask($previous);
    }
    echo "Fixture ready (credentials вне Git)\n";
} elseif ($mode === 'cleanup') {
    $fixture = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $user = $users->getUserById($fixture['userId']);
    if (!$user || $user['login'] !== $fixture['login'] || !str_starts_with($user['login'], 'it_user_mlp360_')) {
        throw new RuntimeException('Владелец фикстуры не совпадает');
    }
    foreach ([$fixture['messageId'], $fixture['legacyId']] as $messageId) {
        $message = $chat->getMessageById($messageId);
        if (!$message) continue; // Уже удалённая isolated fixture не требует очистки.
        if ((int)$message['user_id'] !== (int)$user['id']
            || !str_starts_with($message['message'], $fixture['marker'])) {
            throw new RuntimeException('Маркер/владелец сообщения не совпадает');
        }
        // Hard DELETE допустим только для точных подтверждённых фикстурных ID в Docker.
        $stmt = $db->prepare('DELETE FROM chat_messages WHERE id = ? AND user_id = ?');
        $stmt->bind_param('ii', $messageId, $user['id']);
        $stmt->execute();
    }
    $users->deleteUser($user['id']);
    unlink($path);
    echo "Fixture removed\n";
} else {
    fwrite(STDERR, "Usage: php tests/playwright/mlp-360-fixture.php setup|cleanup\n");
    exit(1);
}
