<?php
require __DIR__.'/integration_helpers.php';
it_require_db();
$db=Infra\Database::getInstance()->getConnection(); $cfg=Infra\ConfigManager::getInstance();
$users=new Domain\UserManager(); $chat=new Domain\ChatManager(); $ids=[]; $backup=[];
$beforeJob=(int)$db->query('SELECT COALESCE(MAX(id),0) n FROM llm_jobs')->fetch_assoc()['n'];
$count=LLM\ImageGenerator::todayCount();
try {
    foreach(['owner','bot','other'] as $name){$$name=$users->createUser('it_user_modes_'.bin2hex(random_bytes(6)),bin2hex(random_bytes(24)),'user','Modes '.$name);$ids[]=$$name;}
    foreach(['ai_use_queue'=>'1','ai_worker_mode'=>'cron','ai_bot_user_id'=>(string)$bot,'ai_enabled'=>'1','ai_live_confirm'=>'0','ai_routerai_key'=>'it-dummy','ai_image_llm_caption'=>'0','ai_image_daily_limit'=>'0','bot_last_manual_draw'=>'0','bot_last_autodraw'=>'0'] as $key=>$value){$backup[$key]=$cfg->getOption($key,null);$cfg->setOption($key,$value);}
    $llm=new class extends LLM\LLMManager {
        public array $instructions=[];
        public function liveTextBounded(string $instruction,?string $mustContain,int $deadlineSec,int $timeoutSec=10,?string $trustedTask=null,?array $actionContext=null):?string {
            $this->instructions[]=$instruction;
            return str_contains($instruction,'одну минуту') ? 'Даю минуту на пожелания ответом с цитатой. Затем рисую сама, без подтверждения.' : null;
        }
    };
    $commands=[];foreach((new Domain\BotCommandManager())->getActive() as $row)$commands[$row['handler_type']]=$row;
    $command=$commands['image_chat'];$artist=new LLM\LyraArtist($llm);$registry=LLM\CommandInteractions::registry();
    $ordinary=$chat->addMessage($bot,'Modes bot','Привет, как дела?');
    $reply=$chat->addMessage($owner,'Modes owner','А ты приняла согласие?',[$ordinary]);
    check(!LLM\CommandInteractionContinuation::routeMessage(['message_id'=>$reply,'user_id'=>$owner],$registry),'ordinary quoted greeting does not create a drawing draft');
    $chain=$chat->addMessage($bot,'Modes bot','Не создаю черновик.');
    $reply2=$chat->addMessage($owner,'Modes owner','черновик чего?',[$chain]);
    check(!LLM\CommandInteractionContinuation::routeMessage(['message_id'=>$reply2,'user_id'=>$owner],$registry),'ordinary follow-up cannot enter a phantom draft loop');

    $source=$chat->addMessage($owner,'Modes owner',$command['command_prefix']);
    $payload=['message_id'=>$source,'user_id'=>$owner,'username'=>'Modes owner','message'=>$command['command_prefix'],'command'=>$command];
    $calls=0;$generator=static function()use(&$calls):string{$calls++;return '/upload/lyra/it_modes_'.bin2hex(random_bytes(5)).'.jpg';};
    check($artist->handleDrawChat($command,$payload,null,$generator) && $calls===0,'manual chat drawing announces collection without generating yet');
    $announcement=$chat->findBotReplyTo($source);
    check(str_contains($announcement['message'],'минуту') && !str_contains($announcement['raw_message'],'[[command:'),'collection announcement has a clear deadline and no command buttons');
    $row=$db->query('SELECT id,payload,TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),run_after) delay FROM llm_jobs WHERE id>'.$beforeJob.' ORDER BY id DESC LIMIT 1')->fetch_assoc();$job=(int)$row['id'];$next=json_decode($row['payload'],true);
    check($row['delay']>=58 && $row['delay']<=60 && isset($next['drawing_chat_window']),'drawing is durably scheduled one minute later');
    check(LLM\DrawingSchedule::automaticBlocked(),'pending collection blocks automatic drawing');
    $artist->handleDrawChat($command,$payload,null,$generator);
    check((int)$db->query('SELECT COUNT(*) n FROM llm_jobs WHERE id>'.$beforeJob)->fetch_assoc()['n']===1,'replayed command does not duplicate collection or drawing');
    $wish=$chat->addMessage($other,'Modes other','Добавь воздушный шар',[(int)$announcement['id']]);
    check(LLM\CommandInteractionContinuation::routeMessage(['message_id'=>$wish,'user_id'=>$other],$registry),'another participant can submit a wish without a draft or confirmation');
    $wishes=LLM\DrawingChatCollection::wishes($next);
    check(str_contains($wishes,'воздушный шар'),'collection retains wishes as scene data');
    $late=$chat->addMessage($other,'Modes other','Слишком позднее пожелание');$db->query('UPDATE chat_messages SET created_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 120 SECOND) WHERE id='.$late);
    check(!str_contains(LLM\DrawingChatCollection::wishes($next),'Слишком позднее'),'wishes after the deadline are excluded');
    try{$artist->handleDrawChat($command,$next,null,$generator);check(false,'early generation must wait');}catch(LLM\DrawingBusy $e){check($calls===0,'drawing cannot start before collection deadline');}
    $next['drawing_chat_window']['until']=time()-1;
    $artist->handleDrawChat($command,$next,static fn()=> 'A mint pony carries a colorful balloon.',$generator);
    (new LLM\JobQueue())->complete([$job]);
    check($calls===1,'manual chat drawing starts without confirmation when the minute ends');
    $picture=$db->query('SELECT id,message FROM chat_messages WHERE user_id='.$bot.' ORDER BY id DESC LIMIT 1')->fetch_assoc();
    check(str_contains($picture['message'],'![рисунок]') && !str_contains($picture['message'],'[[command:'),'completed chat drawing has no action buttons');
    $response=$chat->addMessage($owner,'Modes owner','Спасибо за рисунок',[(int)$picture['id']]);
    check(!LLM\CommandInteractionContinuation::routeMessage(['message_id'=>$response,'user_id'=>$owner],$registry),'reply to a finished picture remains ordinary conversation');

    $cfg->setOption('bot_last_manual_draw','0');$cfg->setOption('bot_last_autodraw','0');
    $artist->handleDrawChat($command,['auto'=>true],static fn()=> 'A mint pony reads a book.',$generator);
    check($calls===2 && count($llm->instructions)===1,'automatic drawing skips collection and confirmations');
    $direct=$commands['image'];$source=$chat->addMessage($owner,'Modes owner',$direct['command_prefix'].' замок');
    $artist->handleDraw($direct,['message_id'=>$source,'user_id'=>$owner,'username'=>'Modes owner','message'=>$direct['command_prefix'].' замок'],$generator);
    check($calls===3,'a complete direct drawing request starts immediately');

    $format=LLM\DrawingCommand::interactionRegistry()['drawing']['continuation']['format'];
    $notice=$format('clarifying',['code'=>'ambiguous_choice','_action_context'=>['actor_id'=>$owner,'data'=>[]]]);
    check(!str_contains($notice,'Описание собрано') && !str_contains($notice,'Опиши рисунок'),'unavailable selection notice does not invent an empty draft');
    $source=$chat->addMessage($owner,'Modes owner',$command['command_prefix'].' снова');$payload['message_id']=$source;$payload['message']=$command['command_prefix'].' снова';
    $artist->handleDrawChat($command,$payload,null,$generator);
    $row=$db->query('SELECT payload FROM llm_jobs WHERE id>'.$beforeJob.' ORDER BY id DESC LIMIT 1')->fetch_assoc();$next=json_decode($row['payload'],true);$next['drawing_chat_window']['until']=time()-1;
    $chat->editMessage($source,$owner,'передумал');
    check(!$artist->handleDrawChat($command,$next,null,$generator) && $calls===3,'editing the source invalidates the delayed drawing');
    $repair=new class extends LLM\LLMManager {
        public int $calls=0;
        public function liveTextBounded(string $instruction,?string $mustContain,int $deadlineSec,int $timeoutSec=10,?string $trustedTask=null,?array $actionContext=null):?string {return ++$this->calls<3 ? null : 'Даю минуту на пожелания, затем нарисую сама.';}
        public function lastLiveReplyIssue(): ?array {return ['text'=>'@собеседник, нажми кнопку','errors'=>['address'=>'Вымышленный адресат']];}
    };
    $text=LLM\DrawingCommand::liveReply($repair,'Объяви сбор пожеланий','Запасной текст',time()+30,['actor_id'=>$owner,'data'=>[]]);
    check($repair->calls===3 && $text!=='Запасной текст','rejected speech gets two model repairs before a template fallback');
} finally {
    foreach($ids as $id){$db->query('DELETE FROM command_interactions WHERE owner_id='.$id);$db->query('DELETE FROM chat_messages WHERE user_id='.$id);$users->deleteUser($id);}
    $db->query('DELETE FROM llm_jobs WHERE id>'.$beforeJob);
    (new Core\FileCache('imagegen'))->set(gmdate('Y-m-d'),['count'=>$count]);
    foreach($backup as $key=>$value){if($value!==null)$cfg->setOption($key,(string)$value);else{$q=$db->prepare('DELETE FROM site_options WHERE key_name=?');$q->bind_param('s',$key);$q->execute();}}
}
it_done();
