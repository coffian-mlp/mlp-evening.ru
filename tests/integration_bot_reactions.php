<?php
/**
 * MLP-360: StreamCommand обрабатывает ответ с новым маркером реакции.
 * ai_reactions=0 блокирует запись, =1 сохраняет реакцию; текст очищается
 * в обоих случаях. Используется существующий $ask seam, внешних LLM нет.
 * Запись/cleanup только собственных Docker-фикстур после DB_HOST=db guard.
 */
require_once __DIR__ . '/integration_helpers.php';

if ((it_config()['db']['host'] ?? '') !== 'db') {
    it_skip('integration_bot_reactions требует изолированную DB_HOST=db');
}
$conn = it_require_db();
$config = Infra\ConfigManager::getInstance();
$users = new Domain\UserManager();
$chat = new Domain\ChatManager();
$saved = [];
foreach (['stream_command_enabled', 'stream_command_owner_id', 'ai_bot_user_id', 'ai_reactions'] as $key) {
    $saved[$key] = $config->getOption($key, null);
}
$fixtureIds = [];
$suffix = bin2hex(random_bytes(6));

try {
    $ownerId = $users->createUser('it_user_mlp360_owner_' . $suffix, 'Test-Reactions-360!', 'user');
    $fixtureIds[] = $ownerId;
    $botId = $users->createUser('it_user_mlp360_bot_' . $suffix, 'Test-Reactions-360!', 'user');
    $fixtureIds[] = $botId;
    $config->setOption('stream_command_enabled', '1');
    $config->setOption('stream_command_owner_id', (string)$ownerId);
    $config->setOption('ai_bot_user_id', (string)$botId);

    foreach (['skull', 'clown', 'hundred', 'poop'] as $reaction) {
        foreach ([0, 1] as $enabled) {
            $config->setOption('ai_reactions', (string)$enabled);
            $config->flushCache();
            $command = "Лира, включи кино ($suffix-$reaction-$enabled)";
            $messageId = $chat->addMessage($ownerId, 'MLP360 Owner', $command);
            if (!is_int($messageId) || $messageId <= 0) throw new RuntimeException('Не создана команда фикстуры');
            $text = "Готово ($suffix-$reaction-$enabled)";
            $called = 0;
            (new LLM\StreamCommand())->handle([
                'message'=>$command, 'message_id'=>$messageId,
                'user_id'=>$ownerId, 'username'=>'MLP360 Owner',
            ], function () use (&$called, $reaction, $text) {
                $called++;
                return "[РЕАКЦИЯ: $reaction] $text";
            });
            check($called === 1, "$reaction / enabled=$enabled: fake generator вызван один раз");

            $stmt = $conn->prepare('SELECT reaction FROM chat_reactions WHERE message_id = ? AND user_id = ?');
            $stmt->bind_param('ii', $messageId, $botId);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            check($enabled ? $rows === [['reaction'=>$reaction]] : $rows === [],
                "$reaction / enabled=$enabled: фактические записи реакции соответствуют flag");

            $stmt = $conn->prepare('SELECT message, quoted_msg_ids FROM chat_messages WHERE user_id = ? AND id > ? ORDER BY id');
            $stmt->bind_param('ii', $botId, $messageId);
            $stmt->execute();
            $replies = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            check(count($replies) === 1 && $replies[0]['message'] === $text,
                "$reaction / enabled=$enabled: опубликован чистый текст без маркера");
            check(count($replies) === 1 && json_decode($replies[0]['quoted_msg_ids'], true) === [$messageId],
                "$reaction / enabled=$enabled: ответ ссылается на исходную команду");
        }
    }
} finally {
    foreach ($fixtureIds as $id) {
        // Cleanup разрешён только собственным fixture users в guarded Docker.
        $stmt = $conn->prepare('DELETE FROM chat_messages WHERE user_id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $users->deleteUser($id);
    }
    foreach ($saved as $key => $value) {
        if ($value === null) {
            $stmt = $conn->prepare('DELETE FROM site_options WHERE key_name = ?');
            $stmt->bind_param('s', $key);
            $stmt->execute();
        } else {
            $config->setOption($key, (string)$value);
        }
    }
    $config->flushCache();
}
it_done();
