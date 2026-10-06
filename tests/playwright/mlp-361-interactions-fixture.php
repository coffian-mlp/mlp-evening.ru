<?php
/** Docker-only real domain fixture. Credentials stay in an ignored mode-600 file. */
if (PHP_SAPI === 'cli' && is_file('/.dockerenv') && ($argv[1] ?? '') === 'live') require_once __DIR__ . '/mlp-363-live-transport.php';
require_once dirname(__DIR__) . '/integration_helpers.php';
if (PHP_SAPI !== 'cli' || !is_file('/.dockerenv') || (it_config()['db']['host'] ?? '') !== 'db') exit(1);
it_require_db();
$db = Infra\Database::getInstance()->getConnection();
$config = Infra\ConfigManager::getInstance();
$users = new Domain\UserManager();
$chat = new Domain\ChatManager();
$path = dirname(__DIR__, 2) . '/docs/private/mlp361-interactions-local.json';
$mode = $argv[1] ?? '';
$save = function (array $fixture) use ($path) {
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
    $mask = umask(0077);
    try { file_put_contents($path, json_encode($fixture, JSON_THROW_ON_ERROR)); chmod($path, 0600); }
    finally { umask($mask); }
};
if ($mode === 'setup') {
    if (is_file($path)) throw new RuntimeException('Cleanup existing fixture first');
    $fixture = ['saved' => ['ai_enabled' => $config->getOption('ai_enabled', null), 'ai_bot_user_id' => $config->getOption('ai_bot_user_id', null),
        'ai_use_queue' => $config->getOption('ai_use_queue', null), 'ai_worker_mode' => $config->getOption('ai_worker_mode', null)],
        'users' => [], 'messages' => [], 'interactions' => [], 'cases' => []];
    $bot = $users->createUser('it_user_mlp361_ui_bot_' . bin2hex(random_bytes(6)), bin2hex(random_bytes(24)), 'user');
    $fixture['users'][] = $bot; $fixture['botId'] = $bot;
    $config->setOption('ai_enabled', '0');
    $config->setOption('ai_use_queue', '1');
    $config->setOption('ai_worker_mode', 'cron');
    $config->setOption('ai_bot_user_id', (string)$bot);
    $save($fixture);
    echo "Fixture ready\n";
} elseif ($mode === 'correction') {
    $fixture = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $key = $argv[2] ?? '';
    if (!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $key)) throw new RuntimeException('Invalid fixture case');
    $login = 'it_user_mlp361_ui_' . bin2hex(random_bytes(6)); $password = bin2hex(random_bytes(24));
    $owner = $users->createUser($login, $password, 'admin', 'Correction Fixture');
    $fixture['users'][] = $owner;
    $episodes = [];
    for ($n = 0; $n < 2; $n++) {
        $title = 'MLP361 isolated correction ' . $key . ' ' . $n;
        $stmt = $db->prepare('INSERT INTO episode_list(TITLE,LENGTH) VALUES (?,1)');
        $stmt->bind_param('s', $title); $stmt->execute();
        $id = (int)$db->insert_id;
        $fixture['episodes'][] = $id;
        $episodes[] = ['ID' => $id, 'TITLE' => $title, 'LENGTH' => 1, 'TWOPART_ID' => null, 'WANNA_WATCH' => 0, 'TIMES_WATCHED' => 0];
    }
    $stories = Domain\EpisodeCatalog::normalize($episodes);
    $payload = json_encode($stories, JSON_THROW_ON_ERROR);
    $stmt = $db->prepare('INSERT INTO playlist_snapshots(created_at,payload_json,origin) VALUES(UTC_TIMESTAMP(),?,"fixture")');
    $stmt->bind_param('s', $payload); $stmt->execute();
    $snapshotId = (int)$db->insert_id;
    $fixture['snapshots'][] = $snapshotId;
    (new Domain\EpisodeManager())->completeSnapshot($snapshotId);
    $fixture['cases'][$key] = ['login' => $login, 'password' => $password, 'userId' => $owner,
        'snapshotId' => $snapshotId, 'episodeIds' => array_column($episodes, 'ID'), 'storyIds' => array_column($stories, 'story_id')];
    $save($fixture); echo "Correction fixture ready\n";
 } elseif ($mode === 'semantic' || $mode === 'live') {
    $fixture=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    $key=$argv[2] ?? '';
    if(!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$key)) throw new RuntimeException('Invalid fixture key');
    $login='it_user_mlp361_ui_'.bin2hex(random_bytes(6));$password=bin2hex(random_bytes(24));
    $owner=$users->createUser($login,$password,'user','Semantic Fixture');$fixture['users'][]=$owner;
    $text='!хочу самую первую серию';$source=$chat->addMessage($owner,$login,$text);
    $savedLive=[];
    if ($mode === 'live') {
        foreach (['ai_enabled'=>'1','ai_live_confirm'=>'1','ai_primary_provider'=>'routerai','ai_routerai_key'=>'fixture-key','ai_routerai_model'=>'fixture-main','ai_fast_model'=>'fixture-fast','ai_proxy_url'=>'','ai_openai_key'=>'','ai_openrouter_key'=>'','ai_yandex_key'=>'','ai_gigachat_key'=>''] as $option=>$value) {
            $savedLive[$option]=$config->getOption($option,null); $config->setOption($option,$value);
        }
    }
    try {
        (new LLM\LLMManager())->processTrigger('dynamic_command',['message_id'=>$source,'user_id'=>$owner,'message'=>$text,'command'=>['handler_type'=>'playlist']]);
    } finally {
        foreach ($savedLive as $option=>$value) {
            if ($value===null) { $stmt=$db->prepare('DELETE FROM site_options WHERE key_name=?');$stmt->bind_param('s',$option);$stmt->execute(); }
            else $config->setOption($option,$value);
        }
        $config->flushCache();
    }
    $reply=$chat->findBotReplyTo($source);
    if(!$reply || !preg_match('/\[\[command:(\d+)\]\]/',$reply['raw_message'],$match)) throw new RuntimeException('Semantic command has no proposal');
    $fixture['cases'][$key]=['login'=>$login,'password'=>$password,'userId'=>$owner,'sourceId'=>$source,'messageId'=>(int)$reply['id'],'interactionId'=>(int)$match[1]];
    if ($mode === 'live') {
        $fixture['cases'][$key]['liveCalls']=$GLOBALS['mlp363_calls'] ?? 0;
        $fixture['cases'][$key]['liveUser']=$GLOBALS['mlp363_payload']['messages'][1]['content'] ?? '';
    }
    $save($fixture);echo "Semantic fixture ready\n";
} elseif ($mode === 'wish-count') {
    $fixture=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    $case=$fixture['cases'][$argv[2] ?? ''] ?? null;
    if (!$case) throw new RuntimeException('Missing fixture case');
    echo json_encode(['count'=>count((new Domain\EpisodeManager())->getUserWishes((int)$case['userId']))]);
} elseif ($mode === 'inspect') {
    $fixture = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $case = $fixture['cases'][$argv[2] ?? ''] ?? null;
    if (!$case || !isset($case['episodeIds'])) throw new RuntimeException('Missing correction fixture');
    $values = [];
    foreach ($case['episodeIds'] as $id) $values[] = (int)$db->query('SELECT TIMES_WATCHED FROM episode_list WHERE ID=' . (int)$id)->fetch_assoc()['TIMES_WATCHED'];
    echo json_encode($values);
} elseif ($mode === 'case') {
    $fixture = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $key = $argv[2] ?? '';
    if (!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $key)) throw new RuntimeException('Invalid fixture case');
    $login = 'it_user_mlp361_ui_' . bin2hex(random_bytes(6)); $password = bin2hex(random_bytes(24));
    $owner = $users->createUser($login, $password, 'user', 'Interaction Fixture');
    $fixture['users'][] = $owner;
    $source = $chat->addMessage($owner, $login, '!хочу fixture ' . $key . ' ' . bin2hex(random_bytes(5)));
    $fixture['messages'][] = $source;
    $manager = new Domain\CommandInteractionManager(LLM\PlaylistCommand::interactionRegistry());
    $id = $manager->create('episode_wish', $owner, $source, [
        ['key' => 'one', 'label' => '<img src=x onerror=alert(1)> Episode one', 'payload' => ['episode_id' => 1]],
        ['key' => 'two', 'label' => 'Episode two', 'payload' => ['episode_id' => 2]],
        ['key' => 'cancel', 'label' => 'Отмена'],
    ]);
    $fixture['interactions'][] = $id;
    $botMessage = $chat->addMessage($fixture['botId'], 'Interaction bot', 'MLP361 выбор ' . $key . ' [[command:' . $id . ']]', [$source]);
    $fixture['messages'][] = $botMessage;
    $manager->bindMessage($id, $botMessage);
    $fake = $chat->addMessage($owner, $login, 'Поддельный marker [[command:' . $id . ']]');
    $quote = $chat->addMessage($owner, $login, 'Цитата выбора ' . $key, [$botMessage]);
    $fixture['messages'][] = $fake; $fixture['messages'][] = $quote;
    $fixture['cases'][$key] = ['login' => $login, 'password' => $password, 'userId' => $owner,
        'interactionId' => $id, 'messageId' => $botMessage, 'sourceId' => $source, 'fakeId' => $fake, 'quoteId' => $quote];
    $save($fixture);
    echo "Fixture case ready\n";
} elseif ($mode === 'queued-command' || $mode === 'age-messages') {
    $fixture = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $case = $fixture['cases'][$argv[2] ?? ''] ?? null;
    if (!$case) throw new RuntimeException('Missing fixture case');
    if ($mode === 'age-messages') {
        $db->query('UPDATE chat_messages SET created_at=UTC_TIMESTAMP()-INTERVAL 10 SECOND WHERE user_id=' . (int)$case['userId']);
        echo "Fixture messages aged\n";
    } else {
        $stmt = $db->prepare('SELECT COUNT(*) AS n FROM llm_jobs WHERE type="dynamic_command" AND JSON_EXTRACT(payload,"$.user_id")=? AND JSON_UNQUOTE(JSON_EXTRACT(payload,"$.command.handler_type"))="playlist"');
        $stmt->bind_param('i', $case['userId']); $stmt->execute();
        echo json_encode(['count' => (int)$stmt->get_result()->fetch_assoc()['n']]);
    }
} elseif ($mode === 'expire' || $mode === 'ban' || $mode === 'mute' || $mode === 'edit') {
    $fixture = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $case = $fixture['cases'][$argv[2] ?? ''] ?? null;
    if (!$case) throw new RuntimeException('Missing fixture case');
    if ($mode === 'expire') $db->query('UPDATE command_interactions SET expires_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE id=' . (int)$case['interactionId']);
    elseif ($mode === 'ban') $users->banUser($case['userId'], 'Fixture sanction');
    elseif ($mode === 'mute') $users->muteUser($case['userId'], 1, null, 'Fixture sanction');
    else $chat->editMessage($case['sourceId'], $case['userId'], 'Edited fixture command');
    echo "Fixture state changed\n";
} elseif ($mode === 'cleanup') {
    $fixture = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    foreach ($fixture['users'] as $id) {
        $user = $users->getUserById($id);
        if (!$user || !str_starts_with($user['login'], 'it_user_mlp361_ui_')) throw new RuntimeException('Fixture owner mismatch');
        foreach (['command_interactions' => 'owner_id', 'episode_wishes' => 'user_id', 'episode_wish_events' => 'user_id', 'episode_wish_locks' => 'user_id'] as $table => $column) {
            $db->query("DELETE FROM $table WHERE $column=" . (int)$id);
        }
        // Exact fixture authors only; no unrelated chat tail cleanup.
        $db->query('DELETE FROM chat_messages WHERE user_id=' . (int)$id);
        $db->query('DELETE FROM llm_jobs WHERE JSON_EXTRACT(payload,"$.user_id")=' . (int)$id);
        $users->deleteUser($id);
    }
    foreach ($fixture['saved'] as $key => $value) {
        if ($value === null) { $stmt = $db->prepare('DELETE FROM site_options WHERE key_name=?'); $stmt->bind_param('s', $key); $stmt->execute(); }
        else $config->setOption($key, (string)$value);
    }
    foreach ($fixture['episodes'] ?? [] as $id) {
        $db->query('DELETE FROM watching_now WHERE EPNUM=' . (int)$id);
        $db->query('DELETE FROM episode_list WHERE ID=' . (int)$id);
    }
    foreach ($fixture['snapshots'] ?? [] as $id) {
        $db->query('DELETE FROM playlist_corrections WHERE snapshot_id=' . (int)$id);
        $db->query('DELETE FROM playlist_completions WHERE snapshot_id=' . (int)$id);
        $db->query('DELETE FROM playlist_snapshots WHERE id=' . (int)$id);
    }
    $config->flushCache(); unlink($path); echo "Fixture cleaned\n";
} else { throw new RuntimeException('Use setup|case KEY|expire KEY|ban KEY|mute KEY|edit KEY|cleanup'); }
