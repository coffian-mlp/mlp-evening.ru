<?php
require_once __DIR__ . '/integration_helpers.php';
if ((it_config()['db']['host'] ?? '') !== 'db') it_skip('Command interactions require isolated db');
it_require_db();
$db = Infra\Database::getInstance()->getConnection();
$config = Infra\ConfigManager::getInstance();
$users = new Domain\UserManager();
$chat = new Domain\ChatManager();
$transport = new class extends Infra\CentrifugoService {
    public array $published = [];
    public function publish($channel, $data) {
        $this->published[] = ['channel' => $channel, 'data' => $data];
        return true;
    }
};
(new ReflectionProperty($chat, 'centrifugo'))->setValue($chat, $transport);
$savedBot = $config->getOption('ai_bot_user_id', null);
$suffix = bin2hex(random_bytes(6));
$table = 'it_ci_' . $suffix;
$ids = [];
$messages = [];
$interactions = [];
$db->query("CREATE TABLE $table (id INT PRIMARY KEY,effects INT NOT NULL) ENGINE=InnoDB");
$db->query("INSERT INTO $table VALUES (1,0)");
$handler = ['visibility' => 'public', 'permission' => static fn() => true,
    'execute' => function ($actor, $payload, $key) use ($db, $table) {
        Infra\Transaction::run($db, fn() => $db->query("UPDATE $table SET effects=effects+1 WHERE id=1"));
        if (!empty($payload['fail'])) throw new RuntimeException('Injected effect failure');
        return ['status' => 'accepted', 'code' => 'fixture', 'facts' => ['choice' => $payload['choice'] ?? 'yes']];
    }];
$registry = ['fixture' => $handler, 'private_fixture' => array_merge($handler, ['visibility' => 'owner'])];
$manager = new Domain\CommandInteractionManager($registry);
$denied = function (callable $call, string $label) {
    try { $call(); check(false, $label); } catch (Core\UserError $error) { check(true, $label); }
};
try {
    foreach (['owner', 'foreign', 'bot'] as $role) {
        $ids[$role] = $users->createUser('it_user_mlp361_' . $role . '_' . $suffix, bin2hex(random_bytes(20)), 'user');
    }
    $config->setOption('ai_bot_user_id', (string)$ids['bot']);
    $make = function (string $type = 'fixture', array $payload = [], int $ttl = 900) use ($manager, $chat, $ids, &$messages, &$interactions, $suffix) {
        $source = $chat->addMessage($ids['owner'], 'Fixture owner', 'command ' . $suffix . ' ' . bin2hex(random_bytes(4)));
        $messages[] = $source;
        $options = [['key' => 'one', 'label' => '<b>One</b>', 'payload' => array_merge(['choice' => 'one', 'secret' => 'server-only'], $payload)],
            ['key' => 'two', 'label' => 'Two', 'payload' => ['choice' => 'two']], ['key' => 'cancel', 'label' => 'Cancel']];
        $id = $manager->create($type, $ids['owner'], $source, $options, $ttl);
        check($manager->create($type, $ids['owner'], $source, $options, $ttl) === $id, 'create source replay returns same interaction');
        $interactions[] = $id;
        $bot = $chat->addMessage($ids['bot'], 'Fixture bot', 'Select [[command:' . $id . ']]', [$source]);
        $messages[] = $bot;
        $manager->bindMessage($id, $bot);
        return [$id, $source, $bot];
    };
    [$id, $source, $bot] = $make();
    check(!array_key_exists('handler_context',$manager->getResult($id,$ids['owner'])) && $manager->getResult($id,$ids['owner'],true)['handler_context']===[], 'legacy NULL context optional metadata stays empty and default shape unchanged');
    $published = array_values(array_filter($transport->published, static fn($item) => (int)($item['data']['id'] ?? 0) === $bot));
    $history = array_values(array_filter($chat->getMessages(20), static fn($item) => (int)$item['id'] === $bot));
    check(count($published) === 1 && $published[0]['channel'] === 'public:chat' && count($history) === 1
        && $published[0]['data']['message'] === $history[0]['message']
        && str_contains($published[0]['data']['message'], '[[command:' . $id . ']]'),
        'actual ChatManager publishes bound marker and message ID identical to history');
    check(!str_contains(json_encode($published[0]['data']), 'server-only')
        && !array_key_exists('options_json', $published[0]['data']), 'Centrifugo payload excludes private interaction options');
    $public = $manager->readPublic($id, null, $bot);
    check(!$public['can_act'] && count($public['options']) === 3 && !str_contains(json_encode($public), 'server-only'), 'guest reads labels but no payload/privileges');
    check($manager->readPublic($id, $ids['owner'], $source)['state'] === 'unavailable', 'fake containing message cannot activate choice');
    $denied(fn() => $manager->consume($id, 'one', $ids['foreign']), 'foreign owner cannot execute');
    $denied(fn() => $manager->consume($id, 'invented', $ids['owner']), 'unknown option cannot execute');
    $users->banUser($ids['owner'], 'Fixture');
    $denied(fn() => $manager->consume($id, 'one', $ids['owner']), 'ban after creation blocks execution');
    $users->unbanUser($ids['owner']);
    $users->muteUser($ids['owner'], 1);
    $denied(fn() => $manager->consume($id, 'one', $ids['owner']), 'mute after creation blocks execution');
    $users->unmuteUser($ids['owner']);
    $first = $manager->consume($id, 'one', $ids['owner']);
    check($first === $manager->consume($id, 'two', $ids['owner']), 'different candidate retry returns original outcome');
    check((int)$db->query("SELECT effects FROM $table WHERE id=1")->fetch_assoc()['effects'] === 1, 'exactly one effect after retry');
    [$private, , $privateBot] = $make('private_fixture');
    check($manager->readPublic($private, $ids['foreign'], $privateBot)['options'] === [], 'private handler does not leak labels to foreign reader');
    check($manager->consume($private, 'one', $ids['owner'])['status'] === 'accepted', 'second independent handler uses same lifecycle');
    [$failed] = $make('fixture', ['fail' => true]);
    try { $manager->consume($failed, 'one', $ids['owner']); check(false, 'injected failure throws'); }
    catch (RuntimeException $error) { check(true, 'injected failure throws'); }
    check((int)$db->query("SELECT effects FROM $table WHERE id=1")->fetch_assoc()['effects'] === 2 && $manager->getResult($failed, $ids['owner'])['outcome'] === null,
        'nested effect and consumed outcome rollback together');
    check($manager->consume($failed, 'two', $ids['owner'])['status'] === 'accepted', 'failed action remains retryable');
    [$expired] = $make('fixture', [], 1);
    $denied(fn() => $manager->consume($expired, 'one', $ids['owner'], time() + 2), 'expiry prevents effect');
    [$edited, $editedSource] = $make();
    $chat->editMessage($editedSource, $ids['owner'], 'Changed ' . $suffix);
    $denied(fn() => $manager->consume($edited, 'one', $ids['owner']), 'source editing invalidates action');
    [$cancelled] = $make();
    check($manager->consume($cancelled, 'cancel', $ids['owner'])['status'] === 'cancelled', 'cancel consumes choice without handler');
    [$race] = $make();
    $before = (int)$db->query("SELECT effects FROM $table WHERE id=1")->fetch_assoc()['effects'];
    $processes = [];
    foreach (['one', 'two'] as $choice) {
        $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/command-interaction-consume.php', $table, (string)$race, $choice, (string)$ids['owner']],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $processes[] = [$process, $pipes];
    }
    $results = [];
    foreach ($processes as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        check(proc_close($process) === 0, 'parallel consume process succeeds: ' . $errors);
        $results[] = json_decode($output, true);
    }
    check($results[0] === $results[1] && (int)$db->query("SELECT effects FROM $table WHERE id=1")->fetch_assoc()['effects'] === $before + 1,
        'two parallel candidate clicks return same result and one real effect');
} finally {
    foreach ($interactions as $id) $db->query('DELETE FROM command_interactions WHERE id=' . (int)$id);
    foreach ($messages as $id) $db->query('DELETE FROM chat_messages WHERE id=' . (int)$id);
    foreach ($ids as $id) $users->deleteUser($id);
    $db->query("DROP TABLE $table");
    if ($savedBot === null) $db->query("DELETE FROM site_options WHERE key_name='ai_bot_user_id'");
    else $config->setOption('ai_bot_user_id', (string)$savedBot);
    $config->flushCache();
}
it_done();
