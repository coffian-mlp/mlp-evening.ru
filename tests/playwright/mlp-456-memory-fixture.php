<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__, 2) . '/autoload.php';
if (!is_file('/.dockerenv') || Infra\Env::get('DB_HOST') !== 'db' || Infra\Env::get('DB_NAME') !== 'mlp456pw' || getenv('MLP456_PW_FIXTURE') !== '1') {
    throw new RuntimeException('Dedicated mlp456pw fixture environment required');
}
$db = Infra\Database::getInstance()->getConnection();
$statePath = dirname(__DIR__, 2) . '/docs/tests/MLP-456/browser-state.json';
$mode = $argv[1] ?? '';
$config = Infra\ConfigManager::getInstance();
$root = new mysqli('db', 'root', Infra\Env::get('DB_ROOT_PASS'), 'mlp456pw');

function primeMemory(array $state): void {
    $builder = new LLM\MemoryEpisodeBuilder(12);
    $reader = new LLM\MemorySourceReader();
    $store = new Domain\MemoryEpisodeManager();
    $episodes = array_merge(
        $builder->chat((new Domain\ChatManager())->memorySourceRows([$state['message_id']]), 'browser-fake'),
        $builder->memory((new Domain\BotMemoryManager())->memorySourceRows([$state['memory_id']]), 'browser-fake')
    );
    foreach ($episodes as $episode) {
        $record = $store->register($episode, $reader->current(...));
        if ($record && $record['status'] !== 'ready') $store->publish($episode, $record, 'Ace Attorney, Phoenix Wright', array_fill(0, 512, 1 / sqrt(512)), $reader->current(...));
    }
}

if ($mode === 'setup') {
    if (is_file($statePath)) throw new RuntimeException('Previous fixture requires cleanup');
    $config->setOption('ai_enabled', '0');
    $config->setOption('ai_memory_index_enabled', '0');
    $config->setOption('ai_memory_index_history', '0');
    $config->setOption('chat_rate_limit', '0');
    $config->flushCache();
    $nonce = bin2hex(random_bytes(5));
    $state = ['login'=>'it456pw_'.$nonce, 'password'=>bin2hex(random_bytes(24)), 'marker'=>'it456pw_'.$nonce];
    $state['user_id'] = (new Domain\UserManager())->createUser($state['login'], $state['password'], 'user', 'Memory Browser Fixture');
    $state['admin_login'] = $state['login'] . '_admin';
    $state['admin_id'] = (new Domain\UserManager())->createUser($state['admin_login'], $state['password'], 'admin', 'Memory Admin Fixture');
    $version = Domain\Privacy::version();
    $stmt = $db->prepare("INSERT INTO account_privacy(user_id,state,policy_version,accepted_at) VALUES(?,'active',?,UTC_TIMESTAMP())");
    foreach ([$state['user_id'], $state['admin_id']] as $consentingUser) {
        $stmt->bind_param('is', $consentingUser, $version);
        $stmt->execute();
    }
    $state['message_text'] = $state['marker'] . ' original message';
    $state['memory_text'] = $state['marker'] . ' original memory';
    $state['message_id'] = (new Domain\ChatManager())->addMessage($state['user_id'], $state['login'], $state['message_text']);
    $state['memory_id'] = (new Domain\BotMemoryManager())->add('dossier', $state['user_id'], $state['memory_text'], 'manual', $state['user_id']);
    primeMemory($state);
    umask(0077);
    file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR));
    echo json_encode($state, JSON_THROW_ON_ERROR);
    exit;
}
$state = json_decode(file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
if ($mode === 'oversized-limits') {
    foreach (['ai_memory_block_limit'=>10000,'ai_memory_user_limit'=>4000,'ai_memory_meme_limit'=>4000] as $key=>$value) $config->setOption($key,$value);
    echo '{}'; exit;
}
if ($mode === 'inspect') {
    $message = $db->query('SELECT message,is_deleted FROM chat_messages WHERE id='.(int)$state['message_id'])->fetch_assoc();
    $memory = $db->query('SELECT text FROM bot_memory WHERE id='.(int)$state['memory_id'])->fetch_assoc();
    $derived = $db->query('SELECT e.id,e.source_kind,e.status,e.revision,e.associations,OCTET_LENGTH(e.embedding) AS vector_bytes FROM memory_episodes e JOIN memory_episode_sources s ON s.episode_id=e.id WHERE s.user_id='.(int)$state['user_id'].' OR s.related_user_id='.(int)$state['user_id'].' ORDER BY e.id')->fetch_all(MYSQLI_ASSOC);
    echo json_encode(['message'=>$message,'memory'=>$memory,'derived'=>$derived,'account'=>Domain\Privacy::account($state['user_id'])], JSON_THROW_ON_ERROR);
} elseif ($mode === 'prime') {
    primeMemory($state);
    echo '{}';
} elseif ($mode === 'fail-derived') {
    $root->query("CREATE TRIGGER mlp456pw_fail_derived BEFORE UPDATE ON memory_episodes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fixture derived write failure'");
    echo '{}';
} elseif ($mode === 'allow-derived') {
    $root->query('DROP TRIGGER IF EXISTS mlp456pw_fail_derived');
    echo '{}';
} elseif ($mode === 'cleanup') {
    $root->query('DROP TRIGGER IF EXISTS mlp456pw_fail_derived');
    $user = (new Domain\UserManager())->getUserById((int)$state['user_id']);
    if (!$user || $user['login'] !== $state['login']) throw new RuntimeException('Fixture owner mismatch');
    (new Domain\MemoryEpisodeManager())->eraseUser((int)$state['user_id']);
    $db->query('DELETE FROM chat_messages WHERE id='.(int)$state['message_id'].' AND user_id='.(int)$state['user_id']);
    (new Domain\BotMemoryManager())->eraseUserData((int)$state['user_id']);
    foreach (['privacy_consent_events','privacy_account_choices','privacy_browser_owners','privacy_sessions','privacy_mail_cleanup','privacy_files','account_privacy'] as $table) {
        $db->query('DELETE FROM '.$table.' WHERE user_id='.(int)$state['user_id']);
    }
    foreach (['privacy_consent_events','privacy_account_choices','privacy_browser_owners','privacy_sessions','privacy_mail_cleanup','privacy_files','account_privacy'] as $table) {
        $db->query('DELETE FROM '.$table.' WHERE user_id='.(int)$state['admin_id']);
    }
    (new Domain\UserManager())->deleteUser($state['user_id']);
    (new Domain\UserManager())->deleteUser($state['admin_id']);
    unlink($statePath);
    echo '{}';
} else {
    throw new RuntimeException('Unknown fixture mode');
}
