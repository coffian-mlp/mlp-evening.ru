<?php
namespace LLM {
    function time(): int { return $GLOBALS['drawing_inline_clock']; }
    function sleep(int $seconds): int {
        $db=\Infra\Database::getInstance()->getConnection();
        $GLOBALS['drawing_inline_status']=$db->query("SELECT status FROM llm_jobs WHERE JSON_EXTRACT(payload,'$.drawing_chat_window') IS NOT NULL ORDER BY id DESC LIMIT 1")->fetch_assoc()['status'] ?? null;
        $GLOBALS['drawing_inline_clock']+=$seconds;
        return 0;
    }
}
namespace {
require __DIR__.'/integration_helpers.php';
it_require_db();$db=Infra\Database::getInstance()->getConnection();$cfg=Infra\ConfigManager::getInstance();$users=new Domain\UserManager();$chat=new Domain\ChatManager();
$GLOBALS['drawing_inline_clock']=time();$ids=[];$backup=[];
$beforeJob=(int)$db->query('SELECT COALESCE(MAX(id),0) n FROM llm_jobs')->fetch_assoc()['n'];
try {
    foreach(['owner','bot'] as $name){$$name=$users->createUser('it_user_inlinechat_'.bin2hex(random_bytes(6)),bin2hex(random_bytes(24)),'user','Inline '.$name);$ids[]=$$name;}
    foreach(['ai_use_queue'=>'0','ai_worker_mode'=>'inline','ai_enabled'=>'1','ai_live_confirm'=>'0','ai_routerai_key'=>'it-dummy','ai_bot_user_id'=>(string)$bot] as $key=>$value){$backup[$key]=$cfg->getOption($key,null);$cfg->setOption($key,$value);}
    $command=null;foreach((new Domain\BotCommandManager())->getActive() as $row)if($row['handler_type']==='image_chat')$command=$row;
    $llm=new class extends LLM\LLMManager {
        public array $calls=[];
        public function processTrigger($type,$data=[]) {$this->calls[]=['type'=>$type,'data'=>$data,'time'=>LLM\time()];return true;}
    };
    $source=$chat->addMessage($owner,'Inline owner',$command['command_prefix']);
    $start=LLM\time();
    (new LLM\LyraArtist($llm))->handleDrawChat($command,['message_id'=>$source,'user_id'=>$owner,'username'=>'Inline owner','message'=>$command['command_prefix']]);
    check(count($llm->calls)===1 && $llm->calls[0]['time']-$start===60,'inline mode starts drawing after one minute without needing a worker or confirmation');
    check(isset($llm->calls[0]['data']['drawing_chat_window']),'inline execution receives the same collection window as queued execution');
    check($GLOBALS['drawing_inline_status']==='processing','delayed inline drawing stays reserved and cannot also be claimed by a worker');
    $rows=$db->query('SELECT status FROM llm_jobs WHERE id>'.$beforeJob)->fetch_all(MYSQLI_ASSOC);
    check(count($rows)===1 && $rows[0]['status']==='done','inline completion leaves no abandoned pending drawing');
    $reply=$chat->findBotReplyTo($source);
    check(!str_contains($reply['raw_message'],'[[command:') && str_contains($reply['message'],'минуту'),'inline collection announcement has no action buttons');
} finally {
    foreach($ids as $id){$db->query('DELETE FROM chat_messages WHERE user_id='.$id);$users->deleteUser($id);}
    $db->query('DELETE FROM llm_jobs WHERE id>'.$beforeJob);
    foreach($backup as $key=>$value){if($value!==null)$cfg->setOption($key,(string)$value);else{$q=$db->prepare('DELETE FROM site_options WHERE key_name=?');$q->bind_param('s',$key);$q->execute();}}
}
it_done();
}
