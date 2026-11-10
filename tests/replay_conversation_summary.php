<?php
/** Explicit paid replay, excluded from run_all. Output contains IDs and numeric measurements only. */
require_once dirname(__DIR__).'/autoload.php';
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$ranges=['2026-09-19'=>[30062,31261],'2026-10-17'=>[34272,35472],'2026-11-07'=>[37823,38822]];
$args=getopt('',['date:','allow-model-calls']);$date=$args['date']??'';
if(!isset($ranges[$date])||!array_key_exists('allow-model-calls',$args)||getenv('MLP_ALLOW_SUMMARY_REPLAY')!=='1'){
 echo "Replay is disabled. Requires --date=2026-09-19|2026-10-17|2026-11-07 --allow-model-calls and MLP_ALLOW_SUMMARY_REPLAY=1. No model was called.\n";exit(2);
}
$db=Infra\Database::getInstance()->getConnection();
$db->query('SET SESSION TRANSACTION READ ONLY');
$db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
$db->begin_transaction(MYSQLI_TRANS_START_READ_ONLY);
$measurements=[];
try {
    Domain\LlmUsage::withoutDatabase(function()use($ranges,$date,&$measurements){
[$first,$last]=$ranges[$date];$chat=new Domain\ChatManager();$raw=$chat->memorySourceRows(range($first,$last));
$reader=new LLM\MemorySourceReader();$llm=new LLM\LLMManager();$bot=$llm->getBotUserId();$snapshot=$reader->conversation($raw,$first,$last,$bot);
if($snapshot['blocked'])throw new RuntimeException('One or more source authors lack current consent; replay refused');
$summary='';$cursor=$first-1;$calls=0;$lastAttempt=0;$sessionKey='';
$events=Domain\EventManager::expandOccurrences((new Domain\EventManager())->getAllRaw(),1,strtotime($date.' 15:00:00 UTC'));
foreach($raw as$tick){
 $clock=strtotime($tick['created_at'].' UTC')+LLM\ConversationMemoryPolicy::SETTLE;
 $available=array_values(array_filter($raw,static fn($r)=>strtotime($r['created_at'].' UTC')<=$clock));
 $session=LLM\ConversationMemoryPolicy::session($available,$events,$bot,$clock);if(!$session)continue;
 if($sessionKey!==$session['key']){$sessionKey=$session['key'];$summary='';$cursor=$session['first_id']-1;$calls=0;$lastAttempt=0;}
 $rows=array_values(array_filter($available,static fn($r)=>(int)$r['id']>=$session['first_id']));
 $pending=array_filter($rows,static fn($r)=>(int)$r['id']>$cursor&&(int)$r['user_id']!==$bot&&!$r['is_deleted']&&strtotime($r['created_at'].' UTC')<=$clock-LLM\ConversationMemoryPolicy::SETTLE);
 if(count($pending)<25||$calls>=48||$clock-$lastAttempt<120)continue;
 $batch=LLM\ConversationMemoryPolicy::batch($rows,$cursor,$bot,$clock);if(!$batch['humans'])continue;
 $payload=json_encode(['previous_summary'=>$summary,'messages'=>$batch['rows']],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$start=microtime(true);$lastAttempt=$clock;
 $measurementStart=count($measurements);
 try{$result=$llm->withAddressedMessages([],fn()=>$llm->generateConversationSummary($payload,$start+20));}
 catch(Throwable $e){error_log('Conversation replay unavailable: '.get_class($e));$result=null;}
 $calls++;
 $clean=$result===null?null:LLM\ConversationMemoryPolicy::clean($result,LLM\ConversationMemoryPolicy::summaryLatin($llm->conversationAllowedLatin(),$summary,$batch['rows']));
 $current=$reader->conversation($chat->memorySourceRows(range($first,$last)),$first,$last,$bot);
 if($current['blocked']||!hash_equals($snapshot['hash'],$current['hash']))throw new RuntimeException('Source changed during replay; result rejected');
 $attemptUsage=array_slice($measurements,$measurementStart);$input=0;$output=0;$known=(bool)$attemptUsage;$priced=$known;
 foreach($attemptUsage as$m){$known=$known&&$m['input_tokens']!==null&&$m['output_tokens']!==null;$priced=$priced&&$m['provider']==='routerai'&&$m['model']==='z-ai/glm-5.3-flashx';$input+=(int)$m['input_tokens'];$output+=(int)$m['output_tokens'];}
 $cost=$known&&$priced?round(($input*43+$output*146)/1000000,8):null;
 echo json_encode(['input_tokens'=>$input,'output_tokens'=>$output,'http_attempts'=>count($attemptUsage),'usage_known'=>$known,'estimated_rub'=>$cost,'date'=>$date,'attempt'=>$calls,'first_id'=>$session['first_id'],'cursor'=>$batch['cursor'],'input_chars'=>mb_strlen($payload),'output_chars'=>$clean===null?0:mb_strlen($clean),'summary_sha256'=>$clean===null?'':hash('sha256',$clean),'elapsed_ms'=>(int)((microtime(true)-$start)*1000),'accepted'=>$clean!==null],JSON_THROW_ON_ERROR)."\n";
 if($clean!==null){$summary=$clean;$cursor=$batch['cursor'];}
}
    },static function(array $row)use(&$measurements){$measurements[]=$row;});
} finally {
    $db->rollback();
    $db->query('SET SESSION TRANSACTION READ WRITE');
}
