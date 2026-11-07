<?php
require_once __DIR__ . '/integration_helpers.php';
$conn = it_require_db();
require_once dirname(__DIR__) . '/migrate.php';
migrate_database($conn, dirname(__DIR__) . '/migrations');
$db = Infra\Database::getInstance()->getConnection();
$store = new Domain\MemoryEpisodeManager();
$marker = 'it_memory_deadline_' . bin2hex(random_bytes(5));
$user = (new Domain\UserManager())->createUser($marker, 'Aa!'.bin2hex(random_bytes(12)), 'user', 'Deadline');
$stmt = $db->prepare("INSERT INTO chat_messages(user_id,username,message,created_at) VALUES(?,'Deadline',?,UTC_TIMESTAMP())");
$stmt->bind_param('is', $user, $marker);
$stmt->execute();
$message = (int)$db->insert_id;
$orphanKey = hash('sha256', $marker);
$stmt = $db->prepare("INSERT INTO memory_episodes(episode_key,source_kind,first_source_id,last_source_id,source_hash,profile_hash,authors_json,started_at,ended_at)
 VALUES(?,'chat',0,0,?,?,'[]',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
$stmt->bind_param('sss', $orphanKey, $orphanKey, $orphanKey);
$stmt->execute();
$orphanId = (int)$db->insert_id;
$provider = new class implements LLM\EmbeddingProviderInterface {
    public function profile(): string { return 'deadline-fake'; }
    public function embed(array $texts, float $deadline): array { throw new RuntimeException('API must not run while sources are locked'); }
};
$associations = new LLM\MemoryAssociations(fn() => throw new RuntimeException('API must not run'), 'deadline-fake');
$indexer = new LLM\MemoryIndexer($store, new LLM\MemoryEpisodeBuilder(12), $provider, $associations);
$peer = it_db();
$beforeSettings = $db->query('SELECT @@innodb_lock_wait_timeout,@@lock_wait_timeout,@@max_execution_time')->fetch_row();
try {
    $peer->begin_transaction();
    $peer->query("SELECT id FROM chat_messages WHERE id=$message FOR UPDATE");
    $waitsBefore = (int)$db->query("SHOW GLOBAL STATUS LIKE 'Innodb_row_lock_waits'")->fetch_row()[1];
    $start = microtime(true);
    $result = $indexer->run(false, 20, $start + 0.15);
    $elapsed = microtime(true) - $start;
    $waitsAfter = (int)$db->query("SHOW GLOBAL STATUS LIKE 'Innodb_row_lock_waits'")->fetch_row()[1];
    check($result['deadline_stopped'] && !$result['reconciled'], 'row lock stops reconciliation with explicit deadline status');
    check($elapsed < 1.65, 'deadline plus at most one one-second lock wait');
    check($waitsAfter - $waitsBefore === 1, 'only one real row-lock wait, no transaction retries');
    check($result['pruned'] === 0 && $db->query("SELECT id FROM memory_episodes WHERE id=$orphanId")->num_rows === 1, 'interrupted traversal does not prune obsolete episodes');
    check($result['association_calls'] === 0 && $result['embedding_calls'] === 0 && $result['http_attempts'] === 0, 'interrupted traversal makes no API attempts');
    check($beforeSettings === $db->query('SELECT @@innodb_lock_wait_timeout,@@lock_wait_timeout,@@max_execution_time')->fetch_row(), 'indexer restores original session limits');
    echo 'ROW_LOCK_ELAPSED_SECONDS=' . round($elapsed, 4) . "\n";
    $expired = $indexer->run(false, 20, microtime(true) - 1);
    check($expired['deadline_stopped'] && $expired['registered'] === 0 && $expired['pruned'] === 0, 'already expired deadline does no reconciliation work');
    $peer->rollback();

    // A deadline raised during prune rolls back all its deletions.
    $secondKey = hash('sha256', $marker . '-second');
    $db->query("INSERT INTO memory_episodes(episode_key,source_kind,first_source_id,last_source_id,source_hash,profile_hash,authors_json,started_at,ended_at) SELECT '$secondKey',source_kind,first_source_id,last_source_id,source_hash,profile_hash,authors_json,started_at,ended_at FROM memory_episodes WHERE id=$orphanId");
    $secondOrphan = (int)$db->insert_id;
    $budget = new LLM\MemoryIndexBudget($db, microtime(true) + 5);
    $checks = 0;
    try {
        $budget->transaction(function () use ($store, &$checks) {
            return $store->prune([], function () use (&$checks) {
                if (++$checks === 3) throw new LLM\MemoryIndexDeadline('deadline');
            });
        });
        check(false, 'prune should be interrupted');
    } catch (LLM\MemoryIndexDeadline $error) {
        check($db->query("SELECT id FROM memory_episodes WHERE id=$orphanId")->num_rows === 1, 'interrupted pruning rolls back its deletes');
    } finally {
        $budget->restore();
    }
} finally {
    $peer->rollback();
    $peer->close();
    if (isset($secondOrphan)) $db->query("DELETE FROM memory_episodes WHERE id=$secondOrphan");
    $db->query("DELETE FROM memory_episodes WHERE id=$orphanId");
    $db->query("DELETE FROM chat_messages WHERE id=$message");
    (new Domain\UserManager())->deleteUser($user);
}
it_done();
