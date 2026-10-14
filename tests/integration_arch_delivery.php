<?php
require_once __DIR__ . '/integration_helpers.php';
if ((it_config()['db']['host'] ?? '') !== 'db') it_skip('Requires isolated DB_HOST=db');
it_require_db();
$db = Infra\Database::getInstance()->getConnection();
$config = Infra\ConfigManager::getInstance();
$keys = ['ai_bot_user_id', 'ai_memory_enabled', 'announcements_image_main_use_memory', 'announcements_image_reminder_use_memory'];
$saved = []; foreach ($keys as $key) $saved[$key] = $config->getOption($key, null);
$jobs = []; $bot = (new Domain\UserManager())->createUser('it_arch393_' . bin2hex(random_bytes(6)), 'Test-393!', 'user');
try {
    $config->setOption('ai_bot_user_id', (string)$bot);
    $llm = new class extends LLM\LLMManager {
        public int $calls = 0;
        public function processTrigger($type, $data = []) { $this->calls++; return $this->botSay('view accounted: first generated announcement'); }
    };
    $queue = new LLM\JobQueue();
    $data = ['command' => ['handler_type' => 'schedule'], 'schedule_announcement' => 'playlist_finished', 'event_run_id' => 'fixture393'];
    $id = $queue->enqueue('dynamic_command', $data, 0); $jobs[] = $id;
    $job = ['id' => $id, 'data' => $data, 'attempts' => 0];
    LLM\ScheduleAnnouncement::processJob($llm, $queue, $job, true);
    $payload = json_decode($db->query("SELECT payload FROM llm_jobs WHERE id=$id")->fetch_assoc()['payload'], true);
    check(!empty($payload['reply_message_id']) && isset($payload['prepared_reply']), 'announcement text and receipt persist');
    $firstId = $payload['reply_message_id'];
    // Simulate a resumed worker holding the payload from before publication.
    $db->query("UPDATE llm_jobs SET status='processing' WHERE id=$id");
    LLM\ScheduleAnnouncement::processJob($llm, $queue, $job, true);
    $again = json_decode($db->query("SELECT payload FROM llm_jobs WHERE id=$id")->fetch_assoc()['payload'], true);
    check($again['reply_message_id'] === $firstId, 'stale regeneration cannot overwrite committed receipt');
    check((int)$db->query("SELECT COUNT(*) AS n FROM chat_messages WHERE user_id=$bot")->fetch_assoc()['n'] === 1, 'interrupted announcement publishes exactly once');
    $calls = $llm->calls;
    LLM\ScheduleAnnouncement::processJob($llm, $queue, ['id'=>$id, 'data'=>$again, 'attempts'=>2], true);
    check($llm->calls === $calls, 'prepared announcement replay makes no model call');
    $data['event_run_id'] = 'fixture393_saved'; $data['prepared_reply'] = ['text'=>'saved before publication', 'quotes'=>[]];
    $id = $queue->enqueue('dynamic_command', $data, 0); $jobs[] = $id;
    LLM\ScheduleAnnouncement::processJob($llm, $queue, ['id'=>$id, 'data'=>$data, 'attempts'=>1], true);
    check($llm->calls === $calls, 'generation interruption reuses prepared text');
    $config->setOption('ai_memory_enabled', '1');
    foreach (['main', 'reminder'] as $kind) {
        $config->setOption('announcements_image_' . $kind . '_use_memory', '1');
        $content = new LLM\AnnouncementContent();
        $prompt = (new ReflectionMethod($content, 'imagePrompt'))->invoke($content, ['snapshot'=>['stories'=>[]], 'occurrence'=>[]], $kind, 'caption');
        check(str_contains($prompt, 'community_memory'), "$kind fresh image process builds memory context without null LLM");
    }
} finally {
    foreach ($jobs as $id) $db->query('DELETE FROM llm_jobs WHERE id=' . (int)$id);
    $db->query("DELETE FROM chat_messages WHERE user_id=$bot"); (new Domain\UserManager())->deleteUser($bot);
    foreach ($saved as $key=>$value) {
        if ($value !== null) $config->setOption($key, (string)$value);
        else { $stmt=$db->prepare('DELETE FROM site_options WHERE key_name=?'); $stmt->bind_param('s',$key); $stmt->execute(); }
    }
}
it_done();
