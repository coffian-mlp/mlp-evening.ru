<?php
require_once __DIR__ . '/integration_helpers.php';
if ((it_config()['db']['host'] ?? '') !== 'db') it_skip('isolated Docker database required');
it_require_db();

$db = Infra\Database::getInstance()->getConnection();
$config = Infra\ConfigManager::getInstance();
$saved = [];
foreach (['ai_enabled','ai_bot_user_id','ai_use_queue','ai_worker_mode','ai_delay_min','ai_delay_max','ai_command_dedup_window'] as $key) $saved[$key] = $config->getOption($key);
$base = (int)$db->query('SELECT COALESCE(MAX(id),0) n FROM llm_jobs')->fetch_assoc()['n'];
$user = (new Domain\UserManager())->createUser('it_inline_reply_' . bin2hex(random_bytes(5)), bin2hex(random_bytes(12)), 'user');
try {
    foreach (['ai_enabled'=>'1','ai_bot_user_id'=>(string)$user,'ai_use_queue'=>'0','ai_worker_mode'=>'inline','ai_delay_min'=>'0','ai_delay_max'=>'0','ai_command_dedup_window'=>'0'] as $key=>$value) $config->setOption($key,$value);
    $payload = ['username'=>'Fixture','message'=>'/fixture','command'=>['handler_type'=>'text','command_prefix'=>'/fixture']];
    $llm = new class extends LLM\LLMManager {
        public int $calls = 0;
        public bool $available = true;
        public function isEnabled() { return true; }
        public function processTrigger($type, $data = []) {
            $this->calls++;
            return $this->available ? $this->botSay('Persisted inline answer', []) : false;
        }
    };
    $method = new ReflectionMethod(LLM\BotDispatch::class,'inlineReply');
    $queue = new LLM\JobQueue();
    $latest = static function () use ($db): array {
        $row = $db->query('SELECT * FROM llm_jobs ORDER BY id DESC LIMIT 1')->fetch_assoc();
        $row['data'] = json_decode($row['payload'],true); $row['id'] = (int)$row['id'];
        return $row;
    };
    $method->invoke(null,$payload,0,$llm);
    $job = $latest();
    check($job['status']==='done' && $job['data']['prepared_reply']['text']==='Persisted inline answer' && $job['data']['reply_message_id']>0,'inline success commits text and delivery receipt');
    LLM\CommandReplyDelivery::processJob($llm,$queue,$job,true);
    check($llm->calls===1 && (int)$db->query('SELECT COUNT(*) n FROM chat_messages WHERE user_id='.$user)->fetch_assoc()['n']===1,'replaying completed inline reply neither regenerates nor duplicates');

    $llm->available=false;
    $method->invoke(null,$payload,0,$llm);$job=$latest();
    check($job['status']==='pending' && !isset($job['data']['prepared_reply']),'model outage leaves recoverable inline intent instead of premature done');
    $llm->available=true;LLM\CommandReplyDelivery::processJob($llm,$queue,$job,true);
    $job=$latest();check($job['status']==='done' && $llm->calls===3,'worker can recover failed inline generation');

    $db->query('UPDATE users SET is_banned=1 WHERE id='.$user);
    $method->invoke(null,$payload,0,$llm);$job=$latest();
    check($job['status']==='pending' && isset($job['data']['prepared_reply']) && !isset($job['data']['reply_message_id']),'publication failure preserves generated text without a false receipt');
    $calls=$llm->calls;$db->query('UPDATE users SET is_banned=0 WHERE id='.$user);
    LLM\CommandReplyDelivery::processJob($llm,$queue,$job,true);$job=$latest();
    check($job['status']==='done' && $llm->calls===$calls,'publication retry uses saved answer without another model request');

    $config->setOption('ai_enabled','0');
    LLM\BotDispatch::dispatch('dynamic_command',$payload);$job=$latest();
    check($job['status']==='pending' && (int)$job['attempts']===0,'actual inline dispatcher retains paused text command');
    $payload['command']['handler_type']='schedule';
    LLM\BotDispatch::dispatch('dynamic_command',$payload);$job=$latest();
    check($job['status']==='pending' && $job['data']['command']['handler_type']==='schedule','actual inline dispatcher routes schedule through durable delivery');
    foreach (['ban','mute','memory_add','todo','image','poll'] as $handler) {
        check(!LLM\CommandReplyDelivery::handles(['type'=>'dynamic_command','data'=>['command'=>['handler_type'=>$handler]]]),$handler.' action is excluded from read-only replay');
    }
} finally {
    $db->query('DELETE FROM llm_jobs WHERE id>'.$base);
    $db->query('DELETE FROM chat_messages WHERE user_id='.$user);
    (new Domain\UserManager())->deleteUser($user);
    foreach ($saved as $key=>$value) {
        if ($value!==null) $config->setOption($key,(string)$value);
        else { $stmt=$db->prepare('DELETE FROM site_options WHERE key_name=?');$stmt->bind_param('s',$key);$stmt->execute(); }
    }
}
it_done();
