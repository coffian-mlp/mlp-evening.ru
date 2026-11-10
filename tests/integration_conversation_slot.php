<?php
ob_start();require_once __DIR__.'/integration_helpers.php';
use Domain\ConversationMemoryStore as Store;
use LLM\ConversationMemory as Memory;
use LLM\ConversationMemoryPolicy as Policy;
date_default_timezone_set('UTC');$probe=it_require_db();$probe->close();$db=Infra\Database::getInstance()->getConnection();$cfg=Infra\ConfigManager::getInstance();$case=$argv[1]??'all';$total=0;$fail=0;$opts=[];$users=[];$mids=[];$jobs=[];$keys=[];
$ok=function($x,$n)use(&$total,&$fail){$total++;if(!$x)$fail++;echo($x?'PASS ':'FAIL ').$n."\n";};
$set=function($k,$v)use($cfg,&$opts){if(!array_key_exists($k,$opts))$opts[$k]=$cfg->getOption($k,null);$cfg->setOption($k,(string)$v);};
$peer=new mysqli('db',Infra\Env::get('DB_USER'),Infra\Env::get('DB_PASS'),Infra\Env::get('DB_NAME'));$third=new mysqli('db',Infra\Env::get('DB_USER'),Infra\Env::get('DB_PASS'),Infra\Env::get('DB_NAME'));
$mk=function()use($db,&$users){$s=$db->prepare("INSERT INTO users(login,nickname,password_hash,role) VALUES(?,?,'synthetic','user')");$n='it_cont_'.bin2hex(random_bytes(6));$s->bind_param('ss',$n,$n);$s->execute();$users[]=$id=(int)$db->insert_id;Domain\Privacy::setAccountConsent($id,true,false);Domain\Privacy::unlockAccount($id);return$id;};
class SlotSuiteFakeProvider implements LLM\LLMProviderInterface {
 public int $calls=0;public ?Closure $callback=null;
 public function withTimeout(int $t):static{return$this;}
 public function askChat(array $c,string $p):?string{$this->calls++;if($this->callback)($this->callback)($this->calls,$c);return 'Рада снова видеть. Помню синтетическую тему.';}
}
try{
 $uid=$mk();$bot=$mk();$observer=$mk();foreach(['ai_enabled'=>1,'ai_bot_user_id'=>$bot,'ai_primary_provider'=>'routerai','ai_routerai_key'=>'synthetic_no_network','ai_fast_model'=>'synthetic','ai_memory_enabled'=>1,'ai_memory_recall_enabled'=>0,'ai_evening_summary_enabled'=>1,'ai_return_memory_enabled'=>1,'ai_memory_block_limit'=>8000,'ai_online_in_context'=>0,'ai_reactions'=>0,'ai_memory_auto'=>0,'ai_auto_draw'=>0,'ai_lifelike'=>0,'ai_greeting_cooldown'=>0,'ai_debug_log'=>0]as$k=>$v)$set($k,$v);
 for($i=0;$i<25;$i++){$at=gmdate('Y-m-d H:i:s',time()-1500+$i*10);$s=$db->prepare('INSERT INTO chat_messages(user_id,username,message,created_at) VALUES(?,?,?,?)');$n='Synthetic';$t='Синтетический источник '.$i;$s->bind_param('isss',$uid,$n,$t,$at);$s->execute();$mids[]=(int)$db->insert_id;}
 $llm=new LLM\LLMManager();$fake=new SlotSuiteFakeProvider();foreach(['providers','fastProviders']as$p)(new ReflectionProperty(LLM\LLMManager::class,$p))->setValue($llm,[$fake]);
 $store=new Store();$frame=$store->frame($bot);$keys[]=$frame['key'];$store->create($frame);$cursor=max($mids);$q=new LLM\JobQueue();$worker=new LLM\BotWorker();(new ReflectionProperty($worker,'llm'))->setValue($worker,$llm);
 $run=function($method)use($worker){try{if($method==='conversationMemoryStep')(new Store())->purge();(new ReflectionMethod($worker,$method))->invoke($worker);return null;}catch(Throwable $e){return$e;}};
 $seed=function()use($db,$frame,$cursor,$store,$bot){$h=(new LLM\MemorySourceReader())->conversation($store->frame($bot)['rows'],$frame['first_id'],$cursor,$bot)['hash'];$s=$db->prepare("UPDATE bot_conversation_summaries SET summary_text='Синтетический старый конспект',source_hash=?,cursor_id=?,revision=revision+1,attempts=0,last_attempt=NULL,disabled=0 WHERE session_key=?");$s->bind_param('sis',$h,$cursor,$frame['key']);$s->execute();return$store->record($frame['key']);};
 $status=fn($id)=>$db->query("SELECT status,attempts,run_after>UTC_TIMESTAMP() deferred FROM llm_jobs WHERE id=$id")->fetch_assoc();$count=fn()=>(int)$db->query("SELECT COUNT(*) FROM chat_messages WHERE user_id=$bot")->fetch_row()[0];
 $due=function($id)use($db){$db->query("UPDATE llm_jobs SET run_after=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE id=$id");};



 $actor=$mk();$actors=[$actor,$observer];
 foreach(['ai_use_queue'=>0,'ai_worker_mode'=>'inline','ai_delay_min'=>0,'ai_delay_max'=>0,'ai_proactive_interval'=>PHP_INT_MAX,'bot_last_proactive'=>time(),'ai_image_auto_interval'=>0,'ai_poll_participation'=>0,'ai_spontaneous_enabled'=>0]as$k=>$v)$set($k,$v);
 for($i=0;$i<40;$i++){$id=$actors[$i%2];$db->query("INSERT INTO chat_messages(user_id,username,message,created_at)VALUES($id,'Synthetic','Нейтральная текущая реплика',UTC_TIMESTAMP())");}
 $state=dirname(__DIR__).'/slot-probe-state.json';file_put_contents($state,json_encode(['uid'=>$uid,'bot'=>$bot,'actors'=>$actors]));
 $child=function($mode)use($state){$pipes=[];$p=proc_open([PHP_BINARY,__DIR__.'/helpers/conversation_slot_probe.php',$state,$mode],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);return[$p,$pipes];};
 $finish=function($p,$pipes){fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);if($code||$err!=='')throw new RuntimeException('Slot child failed: '.$err);return json_decode(trim($out),true,512,JSON_THROW_ON_ERROR);};
 $protected=fn()=>(int)$db->query("SELECT COUNT(*) FROM chat_messages WHERE user_id=$bot AND message LIKE 'Защищённый%'")->fetch_row()[0];
 $reset=function($returned)use($db,$uid,$bot,$actors,$set,$seed,$store){Domain\Privacy::setAccountConsent($uid,true,false);Domain\Privacy::unlockAccount($uid);$db->query("DELETE FROM chat_messages WHERE user_id=$bot");$db->query("DELETE FROM llm_jobs WHERE type IN ('greeting','evening_summary')");$db->query('DELETE FROM bot_memory_arrivals');$set('ai_evening_summary_enabled',$returned?0:1);$set('ai_return_memory_enabled',$returned?1:0);$seed();if($returned)foreach($actors as$id)$store->arrival($id,30*86400);};
 try{
 foreach([false,true]as$returned){$kind=$returned?'return':'summary';
  $reset($returned);[$p,$pipes]=$child('inline-pair');$out=$finish($p,$pipes);echo json_encode(['case'=>$kind.'-inline-pair']+$out),"\n";$ok(count($out['prepared'])===2,'slot '.$kind.' inline pair actually generates two ordinary replies');$ok($protected()===0,'slot '.$kind.' inline has zero protected replies');
  $reset($returned);$set('ai_evening_summary_enabled',0);$set('ai_return_memory_enabled',0);[$p,$pipes]=$child('inline-pair');$out=$finish($p,$pipes);$ok($protected()===0&&count($out['prepared'])===2,'slot '.$kind.' negative control keeps ordinary parallel replies');
  $reset($returned);$jobs[]=$job=$q->enqueue('greeting',['user_id'=>$actor,'username'=>'SyntheticB'],-1);[$p,$pipes]=$child('worker');$ready=json_decode(trim(fgets($pipes[1])),true,512,JSON_THROW_ON_ERROR);if(empty($ready['ready']))throw new RuntimeException('Worker barrier missing');
  try{$ok(!empty($ready[$kind]),'slot '.$kind.' real worker prepares protected context');$start=microtime(true);[$ip,$io]=$child('inline');$inline=$finish($ip,$io);$ok($protected()===0&&!array_filter($inline['prepared'],fn($r)=>$r['summary']||$r['return'])&&microtime(true)-$start<2,'slot '.$kind.' concurrent inline never waits for owner');$jobs[]=$second=$q->enqueue('greeting',['user_id'=>$observer,'username'=>'SyntheticC'],-1);[$sp,$so]=$child('second-worker');$other=$finish($sp,$so);$ok(!$other['prepared'],'slot '.$kind.' second cron never prepares protected data');}finally{fwrite($pipes[0],"continue\n");}
  $final=$finish($p,$pipes);$ok($protected()===1,'slot '.$kind.' at most one protected publication after withdrawal');$ok($status($job)['status']==='done','slot '.$kind.' protected job completes under owner lock');$db->query("DELETE FROM llm_jobs WHERE id=$second");
 }
 $reset(false);[$p,$pipes]=$child('connection-loss');$loss=$finish($p,$pipes);echo json_encode(['case'=>'connection-loss']+$loss),"\n";$ok($loss['same_connection_object']&&$loss['query_still_fails'],'slot database never reconnects after losing its connection');$ok($loss['publication_failed']&&$protected()===0,'slot lost connection cannot publish protected response');$ok((int)$db->query("SELECT IS_FREE_LOCK('bot_worker')")->fetch_row()[0]===1,'slot disconnect releases global worker lock');
 $set('ai_evening_summary_enabled',0);$set('ai_return_memory_enabled',0);$llm->finishConversationMemory(false);$n=(int)$db->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_row()[1];$ctx=[['role'=>'user','content'=>'Вопрос']];$out=$llm->conversationContext($ctx,[$actor]);$cost=(int)$db->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_row()[1]-$n-1;$ok($out===$ctx&&$cost===0,'slot flags0 adds zero SQL');
 $set('ai_evening_summary_enabled',1);$n=(int)$db->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_row()[1];$out=$llm->conversationContext($ctx,[$actor]);$cost=(int)$db->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_row()[1]-$n-1;echo json_encode(['inline_enabled_sql'=>$cost]),"\n";$ok($out===$ctx&&$cost===1,'slot enabled non-owner performs exactly one ownership SQL');
 }finally{unlink($state);}
}finally{
 $peer->rollback();$third->rollback();Domain\Privacy::unlockAccount($uid??0);
 foreach($jobs as$id)$db->query('DELETE FROM llm_jobs WHERE id='.(int)$id);$db->query("DELETE FROM llm_jobs WHERE type='evening_summary'");
 foreach($users as$id){$db->query("DELETE FROM bot_memory_arrivals WHERE user_id=$id");$db->query("DELETE FROM chat_messages WHERE user_id=$id");foreach(['privacy_consent_events','account_privacy']as$t)$db->query("DELETE FROM $t WHERE user_id=$id");$db->query("DELETE FROM users WHERE id=$id");}
 foreach($keys as$k){$s=$db->prepare('DELETE FROM bot_conversation_summaries WHERE session_key=?');$s->bind_param('s',$k);$s->execute();}
 foreach($opts as$k=>$v){if($v===null){$s=$db->prepare('DELETE FROM site_options WHERE key_name=?');$s->bind_param('s',$k);$s->execute();}else$cfg->setOption($k,$v);}$cfg->flushCache();$peer->close();$third->close();
}
echo "$total checks, $fail failures\n";echo $fail?'FAIL':'ALL PASS';echo"\n";exit((int)($fail>0));
