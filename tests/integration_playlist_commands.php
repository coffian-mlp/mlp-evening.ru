<?php
require_once __DIR__.'/integration_helpers.php';
if ((it_config()['db']['host'] ?? '') !== 'db') it_skip('requires isolated db');
$db=it_require_db();
use Domain\ChatManager;
use Domain\CommandInteractionManager;
use Domain\EpisodeManager;
use Domain\UserManager;
use Infra\ConfigManager;
use LLM\LLMManager;
use LLM\PlaylistCommand;
class PlaylistCommandFixtureLlm extends LLMManager {
    public array $live=[];
    public ?string $reply=null;
    public bool $natural=true;
    public ?int $candidate=null;
    public $onSearch=null;
    public int $searchCalls=0;
    public function __construct(private int $fixtureBot) {}
    public function liveTextBounded(string $instruction,?string $mustContain,int $deadlineSec,int $timeoutSec=10,?string $trustedTask=null):?string{$this->live[]=$instruction;if($this->natural) return 'С радостью помогу! '.$instruction;return $this->reply;}
    public function botSay(string $text,array $quotedIds=[]){return (new ChatManager())->addMessage($this->fixtureBot,'MLP361 fixture bot',$text,$quotedIds);}
    public function generateSearchQueryUtility(array $context,string $prompt,int $deadlineSec,int $timeoutSec=8):?string{return null;}
    public function generateSearchUtility(array $context,string $prompt,?int $deadlineSec=null):?string{++$this->searchCalls;if($this->onSearch)($this->onSearch)();return $this->candidate ? json_encode(['content'=>json_encode(['candidates'=>[['episode_id'=>$this->candidate,'evidence'=>'Matched description','source_url'=>'https://example.org/fixture']]]),'sources'=>[['url'=>'https://example.org/fixture']]]) : null;}
    public function generateBoundedUtility(array $context,string $prompt,int $deadlineSec,int $timeoutSec=20):?string{return json_encode(['verified'=>[$this->candidate]]);}
}
$config=ConfigManager::getInstance();$oldBot=$config->getOption('ai_bot_user_id','0');$oldRate=$config->getOption('chat_rate_limit','5');
$u=(new UserManager())->createUser('it_c361_'.bin2hex(random_bytes(5)),'Test361!Password','user');
$bot=(new UserManager())->createUser('it_b361_'.bin2hex(random_bytes(5)),'Test361!Password','user');
$episodeIds=[];$messages=[];$interactionIds=[];$commandStates=[];
try{
 $config->setOption('ai_bot_user_id',(string)$bot);$config->setOption('chat_rate_limit','0');
 foreach(['хочу','передумал','желания','топ','плейлист'] as $prefix){$quoted=$db->real_escape_string('/'.$prefix);$row=$db->query("SELECT id,is_active FROM bot_commands WHERE command_prefix='$quoted'")->fetch_assoc();if($row){$commandStates[]=$row;$db->query('UPDATE bot_commands SET is_active=1 WHERE id='.(int)$row['id']);}else{$db->query("INSERT INTO bot_commands(command_prefix,description,handler_type,system_prompt,is_active) VALUES('$quoted','test','playlist','',1)");$commandStates[]=['id'=>$db->insert_id,'is_active'=>null];}}
 for($i=0;$i<2;$i++){$db->query("INSERT INTO episode_list(TITLE,LENGTH) VALUES('MLP361 C fixture $i',1)");$episodeIds[]=$db->insert_id;}
 $fake=new PlaylistCommandFixtureLlm($bot);$handler=new PlaylistCommand($fake);$chat=new ChatManager();
 $run=function(string $text)use($chat,$u,$handler,&$messages){$mid=$chat->addMessage($u,'fixture actor',$text);$messages[]=$mid;$payload=['message_id'=>$mid,'user_id'=>$u,'message'=>$text,'command'=>['handler_type'=>'playlist']];$handler->handle($payload);return [$mid,$payload];};
 [$mid,$payload]=$run('!хочу '.$episodeIds[0]);
 check(count((new EpisodeManager())->getUserWishes($u))===1,'exact command mutates actual wish');
 check(str_contains($chat->findBotReplyTo($mid)['raw_message'],'С радостью помогу!'),'valid live phrasing published, not fallback');
 $fake->natural=false;
 $reply=$chat->findBotReplyTo($mid);$handler->handle($payload);check($chat->findBotReplyTo($mid)['id']===$reply['id'],'job retry no duplicate reply');
 $spoof=$payload;$spoof['user_id']=$bot;check($handler->handle($spoof)===false,'source author forged rejected');
 $liveBefore=count($fake->live);$searchBefore=$fake->searchCalls;
 foreach (['!хочу 99999999', '/хочу серию 99999999', '/хочу S99E99'] as $missingText) {[$missingMid]=$run($missingText);check(str_contains($chat->findBotReplyTo($missingMid)['raw_message']??'','Эпизод не найден'),'missing exact reference truthful refusal');}
 check($fake->searchCalls===$searchBefore,'missing ID/code never search LLM');check(count((new EpisodeManager())->getUserWishes($u))===1,'missing references never create wishes');check(count($fake->live)===$liveBefore+3,'missing exact refusals still use live reply path');
 $fake->candidate=$episodeIds[1];[$searchMid,$searchPayload]=$run('/хочу описание неизвестной истории');
 $proposal=$chat->findBotReplyTo($searchMid);preg_match('/\[\[command:(\d+)\]\]/',$proposal['raw_message']??'',$match);$interaction=(int)($match[1]??0);$interactionIds[]=$interaction;
 check($interaction>0,'description creates bound generic interaction');
 check(str_contains($proposal['raw_message'],'Выбери эпизод кнопкой'),'model outage truthful proposal fallback');
 check(count((new EpisodeManager())->getUserWishes($u))===1,'proposal does not spend vote');
 $im=new CommandInteractionManager(PlaylistCommand::interactionRegistry());
 $db->query("UPDATE bot_commands SET is_active=0 WHERE command_prefix='/хочу'");$registry=PlaylistCommand::interactionRegistry();check($registry['episode_wish']['permission']($u,['episode_id'=>$episodeIds[1]])===false,'disabled command denies pending button');
 $db->query("UPDATE bot_commands SET is_active=1 WHERE command_prefix='/хочу'");
 $out=$im->consume($interaction,'episode_'.$episodeIds[1],$u);check($out['status']==='accepted','confirmed candidate actual domain accepted');
 $handler->handleInteractionReply(['interaction_id'=>$interaction,'user_id'=>$u]);$result=$im->getResult($interaction,$u);check($result['reply_message_id']>0,'interaction outcome reply bound');
 $handler->handleInteractionReply(['interaction_id'=>$interaction,'user_id'=>$u]);check($im->getResult($interaction,$u)['reply_message_id']===$result['reply_message_id'],'interaction reply retry no duplicate');
 [$cancelMid,$cancelPayload]=$run('!передумал '.$episodeIds[1]);check(count((new EpisodeManager())->getUserWishes($u))===1,'cancel own actual wish');
 $fake->onSearch=function()use($db,&$messages){$last=end($messages);$db->query("UPDATE chat_messages SET message='edited command',edited_at=UTC_TIMESTAMP() WHERE id=".(int)$last);};
 [$editedMid]=$run('/хочу другая неизвестная история');check($chat->findBotReplyTo($editedMid)===null,'edited during search creates no proposal');
 $db->query("UPDATE bot_commands SET is_active=0 WHERE command_prefix='/хочу'");
 [$disabledMid]=$run('/хочу '.$episodeIds[1]);check($chat->findBotReplyTo($disabledMid)===null,'runtime command activation respected');
 check(count($fake->live)>=4,'normal reply path requests live formulation');
 $oldAI=$config->getOption('ai_enabled','0');$oldMode=$config->getOption('ai_worker_mode','auto');$oldQueue=$config->getOption('ai_use_queue','0');$oldHeartbeat=$config->getOption('bot_worker_heartbeat','0');
 $config->setOption('ai_enabled','0');$config->setOption('ai_worker_mode','inline');$config->setOption('ai_use_queue','0');$config->setOption('bot_worker_heartbeat','0');
 try {
  $ai0Mid=$chat->addMessage($u,'fixture actor','!желания');$messages[]=$ai0Mid;
  $actual=new LLMManager();check($actual->processTrigger('dynamic_command',['message_id'=>$ai0Mid,'user_id'=>$u,'message'=>'!желания','command'=>['handler_type'=>'playlist']])===true,'actual dispatcher executes with AI0');
  check($chat->findBotReplyTo($ai0Mid)!==null,'AI0 direct command factual fallback posted');
  $jobBefore=(int)$db->query('SELECT COALESCE(MAX(id),0) AS n FROM llm_jobs')->fetch_assoc()['n'];
  LLM\PlaylistCommand::queueInteractionReply($interaction,$u);
  $job=$db->query('SELECT * FROM llm_jobs WHERE id>'.$jobBefore.' ORDER BY id DESC LIMIT 1')->fetch_assoc();check($job && $job['type']==='dynamic_command','interaction API notification enqueue only even AI0+inline+stale worker');
  if($job)$db->query('DELETE FROM llm_jobs WHERE id='.(int)$job['id']);
 }finally{$config->setOption('ai_enabled',$oldAI);$config->setOption('ai_worker_mode',$oldMode);$config->setOption('ai_use_queue',$oldQueue);$config->setOption('bot_worker_heartbeat',$oldHeartbeat);}
}finally{
 $db->query('DELETE FROM command_interactions WHERE owner_id='.(int)$u);
 $db->query('DELETE FROM episode_wish_events WHERE user_id='.(int)$u);$db->query('DELETE FROM episode_wishes WHERE user_id='.(int)$u);$db->query('DELETE FROM episode_wish_locks WHERE user_id='.(int)$u);
 $db->query('DELETE FROM chat_messages WHERE user_id IN ('.(int)$u.','.(int)$bot.')');
 foreach($episodeIds as $id)$db->query('DELETE FROM episode_list WHERE ID='.(int)$id);
 foreach($commandStates as $row){if($row['is_active']===null)$db->query('DELETE FROM bot_commands WHERE id='.(int)$row['id']);else$db->query('UPDATE bot_commands SET is_active='.(int)$row['is_active'].' WHERE id='.(int)$row['id']);}
 $db->query('DELETE FROM users WHERE id IN ('.(int)$u.','.(int)$bot.')');
 $config->setOption('ai_bot_user_id',$oldBot);$config->setOption('chat_rate_limit',$oldRate);
}
it_done();
