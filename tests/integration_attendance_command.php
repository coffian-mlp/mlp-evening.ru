<?php
require __DIR__ . '/integration_helpers.php';
it_require_db();
$db = Infra\Database::getInstance()->getConnection();
$cfg = Infra\ConfigManager::getInstance();
$users = new Domain\UserManager();
$chat = new Domain\ChatManager();
$pm = new Domain\PollManager();
$ids = []; $polls = []; $options = [];
try {
    foreach (['ai_bot_user_id','polls_create_role'] as $key) $options[$key] = $cfg->getOption($key, null);
    $actor = $users->createUser('it_user_attendance_' . bin2hex(random_bytes(6)), bin2hex(random_bytes(24)), 'moderator', 'Attendance Host'); $ids[] = $actor;
    $bot = $users->createUser('it_user_attendance_' . bin2hex(random_bytes(6)), bin2hex(random_bytes(24)), 'user', 'Attendance Bot'); $ids[] = $bot;
    $guest = $users->createUser('it_user_attendance_' . bin2hex(random_bytes(6)), bin2hex(random_bytes(24)), 'user', 'Attendance Viewer'); $ids[] = $guest;
    $cfg->setOption('ai_bot_user_id', (string)$bot); $cfg->setOption('polls_create_role', 'moderator');
    $llm = new class extends LLM\LLMManager {
        public int $calls = 0;
        public function commandConfirmationText(string $instruction, ?string $mustContain, string $message): ?string {
            $this->calls++;
            return 'Копыта размять! Вернувшись, отметьтесь кнопкой ниже.';
        }
    };
    $source = $chat->addMessage($actor, 'Attendance Host', '/перерыв');
    $payload = ['message_id' => $source, 'user_id' => $actor];
    $handler = new LLM\AttendanceCommand($llm);
    check($handler->handle($payload), 'host creates attendance');
    $reply = $chat->findBotReplyTo($source, '[[command-delivery:source_' . $source . ']]');
    preg_match('/\[\[poll:(\d+)\]\]/', $reply['raw_message'], $match);
    $poll = $pm->getPoll((int)$match[1]); $polls[] = (int)$poll['id'];
    check(count($poll['options']) === 1 && $poll['options'][0]['text'] === 'Я тут!', 'exactly one attendance choice');
    check((int)$poll['created_by'] === $actor && !(int)$poll['is_anonymous'], 'host owns the public check');
    check(str_contains($reply['raw_message'], 'Копыта размять'), 'LLM text is preserved');
    check($handler->handle($payload) && $llm->calls === 1, 'retry reuses card without model call');
    check($pm->create($actor, 'Invalid ordinary poll', ['Only one']) === 0, 'ordinary polls still require two choices');
    $option = (int)$poll['options'][0]['id'];
    check($pm->vote((int)$poll['id'], $guest, [$option]), 'viewer marks return');
    check($pm->getResults((int)$poll['id'])['total_voters'] === 1, 'return count is one');
    check(str_contains($pm->getVotersByOption((int)$poll['id'])[$option], 'Attendance Viewer'), 'names are public');
    check($pm->vote((int)$poll['id'], $guest, []), 'viewer removes mark');
    check($pm->getResults((int)$poll['id'])['total_voters'] === 0, 'removed mark is not counted');
    $foreign = $chat->addMessage($guest, 'Attendance Viewer', '/перерыв');
    check(!$handler->handle(['message_id'=>$foreign, 'user_id'=>$guest]), 'ordinary user cannot create under moderator policy');
    check(!$handler->handle(['message_id'=>$source, 'user_id'=>$guest]), 'cannot impersonate host');
    check($pm->close((int)$poll['id']) && !$pm->vote((int)$poll['id'], $guest, [$option]), 'closed check rejects votes');
    $cfg->setOption('polls_create_role', 'all');
    $fallback = new class extends LLM\LLMManager {
        public function commandConfirmationText(string $instruction, ?string $mustContain, string $message): ?string { return null; }
    };
    check((new LLM\AttendanceCommand($fallback))->handle(['message_id'=>$foreign,'user_id'=>$guest]), 'all-users policy and LLM outage fallback');
    $reply = $chat->findBotReplyTo($foreign); preg_match('/\[\[poll:(\d+)\]\]/', $reply['raw_message'], $match); $polls[] = (int)$match[1];
    check(str_contains(html_entity_decode($reply['raw_message']), 'Перерыв!'), 'fallback published');
    $count = (int)$db->query('SELECT COUNT(*) n FROM polls')->fetch_assoc()['n'];
    try {
        Infra\Transaction::run($db, function () use ($pm, $actor): void {
            $pm->createAttendance($actor, 'Rolled back check');
            throw new RuntimeException('rollback fixture');
        });
    } catch (RuntimeException $e) {}
    check((int)$db->query('SELECT COUNT(*) n FROM polls')->fetch_assoc()['n'] === $count, 'poll creation joins outer transaction');
} finally {
    foreach ($polls as $id) { $db->query('DELETE FROM poll_votes WHERE poll_id=' . $id); $db->query('DELETE FROM poll_options WHERE poll_id=' . $id); $db->query('DELETE FROM polls WHERE id=' . $id); }
    foreach ($ids as $id) { $db->query('DELETE FROM chat_messages WHERE user_id=' . $id); $users->deleteUser($id); }
    foreach ($options as $key => $value) {
        if ($value !== null) $cfg->setOption($key, (string)$value);
        else { $q=$db->prepare('DELETE FROM site_options WHERE key_name=?'); $q->bind_param('s',$key); $q->execute(); }
    }
}
it_done();
