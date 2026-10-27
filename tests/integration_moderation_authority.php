<?php
/** Queued moderation must use current authority, not the captured role. */
require_once __DIR__ . '/integration_helpers.php';
if ((it_config()['db']['host'] ?? '') !== 'db') it_skip('Requires isolated DB_HOST=db');
it_require_db();
$db = Infra\Database::getInstance()->getConnection();
$users = new Domain\UserManager();
$llm = new class extends LLM\LLMManager {
    public array $replies = [];
    public function __construct() {}
    public function getBotUserId(): int { return 0; }
    public function botSayLive(string $instruction, string $fallback, array $quotedIds = [], ?string $mustContain = null) { $this->replies[] = $fallback; return true; }
};
$db->begin_transaction();
try {
    $suffix = bin2hex(random_bytes(6));
    $actor = $users->createUser('it_mod_actor_' . $suffix, 'Test-392!', 'admin');
    $target = $users->createUser('it_mod_target_' . $suffix, 'Test-392!', 'moderator');
    $nick = 'it_mod_target_' . $suffix;
    $handler = new LLM\ModerationCommand($llm);
    foreach (['ban', 'mute', 'unban'] as $type) {
        $command = ['id' => 392, 'handler_type' => $type, 'command_prefix' => '/x'];
        $payload = ['username' => 'actor', 'user_id' => $actor, 'moderator_role' => 'admin', 'message' => '/x @' . $nick . ' 15 причина'];
        $users->updateUser($actor, ['roles'=>['user','user']]);
        $handler->handle($command, $payload);
        check(!empty($llm->replies) && str_contains(end($llm->replies), 'прав'), "$type refuses revoked queued authority");
        $users->updateUser($actor, ['roles'=>['user','moderator']]);
        $handler->handle($command, $payload);
        check(str_contains(end($llm->replies), 'коллег'), "$type rechecks admin-to-moderator downgrade");
    }
    $users->updateUser($actor, ['roles'=>['user','admin']]);
    $users->updateUser($target, ['roles'=>['user','user']]);
    check($users->applyModeration($actor, $target, 'ban', 30, 'test')['error'] === null, 'current admin can ban');
    check(!empty($users->getBanStatus($target)['is_banned']), 'sanction persisted');
    $users->updateUser($target, ['roles'=>['user','admin']]);
    check($users->applyModeration($actor, $target, 'lift')['error'] !== null, 'target promotion prevents lifting');
    check(!empty($users->getBanStatus($target)['is_banned']), 'refused lift preserves ban');
    $users->updateUser($target, ['roles'=>['user','user']]);
    $db->query("UPDATE users SET muted_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 MINUTE) WHERE id = $target");
    $result = $users->applyModeration($actor, $target, 'lift');
    check($result['error'] === null && $result['banned'] && $result['muted'], 'one transaction lifts both sanctions');
    check(empty($users->getBanStatus($target)['is_banned']) && empty($users->getBanStatus($target)['muted_until']), 'both sanctions removed');
    $db->query("UPDATE users SET is_banned = 1 WHERE id = $actor");
    check($users->applyModeration($actor, $target, 'mute', 15)['error'] !== null, 'banned staff cannot apply sanctions');
    $audit = $db->query("SELECT COUNT(*) AS n FROM audit_logs WHERE user_id = $actor")->fetch_assoc();
    check((int)$audit['n'] === 3, 'only accepted ban and two lifts are audited');
} finally {
    $db->rollback();
}
it_done();
