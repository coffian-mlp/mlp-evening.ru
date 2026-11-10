<?php
ob_start();require_once __DIR__.'/integration_helpers.php';
use Domain\ConversationMemoryStore as Store;
use LLM\ConversationMemory as Memory;
use LLM\ConversationMemoryPolicy as Policy;
$db=it_require_db();$cfg=Infra\ConfigManager::getInstance();$case=$argv[1]??'all';$total=0;$fail=0;$opts=[];$users=[];$mids=[];$jobs=[];$keys=[];
$ok=function($x,$n)use(&$total,&$fail){$total++;if(!$x)$fail++;echo($x?'PASS ':'FAIL ').$n."\n";};
$set=function($k,$v)use($cfg,&$opts){if(!array_key_exists($k,$opts))$opts[$k]=$cfg->getOption($k,null);$cfg->setOption($k,(string)$v);};
$peer=new mysqli('db',Infra\Env::get('DB_USER'),Infra\Env::get('DB_PASS'),Infra\Env::get('DB_NAME'));$third=new mysqli('db',Infra\Env::get('DB_USER'),Infra\Env::get('DB_PASS'),Infra\Env::get('DB_NAME'));
$mk=function()use($db,&$users){$s=$db->prepare("INSERT INTO users(login,nickname,password_hash,role) VALUES(?,?,'synthetic','user')");$n='it_cont_'.bin2hex(random_bytes(6));$s->bind_param('ss',$n,$n);$s->execute();$users[]=$id=(int)$db->insert_id;Domain\Privacy::setAccountConsent($id,true,false);Domain\Privacy::unlockAccount($id);return$id;};
class ContentionFakeProvider implements LLM\LLMProviderInterface {
 public int $calls=0;public ?Closure $callback=null;
 public function withTimeout(int $t):static{return$this;}
 public function askChat(array $c,string $p):?string{$this->calls++;if($this->callback)($this->callback)($this->calls,$c);return 'Рада снова видеть. Помню синтетическую тему.';}
}
// Low-level context assertions run on the same connection as a publishing worker.
$workerConnection=Infra\Database::getInstance()->getConnection();
if(!(int)$workerConnection->query("SELECT GET_LOCK('bot_worker',0)")->fetch_row()[0])throw new RuntimeException('Test worker slot unavailable');
try{
 $uid=$mk();$bot=$mk();$observer=$mk();foreach(['ai_enabled'=>1,'ai_bot_user_id'=>$bot,'ai_primary_provider'=>'routerai','ai_routerai_key'=>'synthetic_no_network','ai_fast_model'=>'synthetic','ai_memory_enabled'=>1,'ai_memory_recall_enabled'=>0,'ai_evening_summary_enabled'=>1,'ai_return_memory_enabled'=>1,'ai_memory_block_limit'=>8000,'ai_online_in_context'=>0,'ai_reactions'=>0,'ai_memory_auto'=>0,'ai_auto_draw'=>0,'ai_lifelike'=>0,'ai_greeting_cooldown'=>0,'ai_debug_log'=>0]as$k=>$v)$set($k,$v);
 for($i=0;$i<25;$i++){$at=gmdate('Y-m-d H:i:s',time()-1500+$i*10);$s=$db->prepare('INSERT INTO chat_messages(user_id,username,message,created_at) VALUES(?,?,?,?)');$n='Synthetic';$t='Синтетический источник '.$i;$s->bind_param('isss',$uid,$n,$t,$at);$s->execute();$mids[]=(int)$db->insert_id;}
 $llm=new LLM\LLMManager();$fake=new ContentionFakeProvider();foreach(['providers','fastProviders']as$p)(new ReflectionProperty(LLM\LLMManager::class,$p))->setValue($llm,[$fake]);
 $store=new Store();$frame=$store->frame($bot);$keys[]=$frame['key'];$store->create($frame);$cursor=max($mids);$q=new LLM\JobQueue();$worker=new LLM\BotWorker();(new ReflectionProperty($worker,'llm'))->setValue($worker,$llm);
 $run=function($method)use($worker){try{if($method==='conversationMemoryStep')(new Store())->purge();(new ReflectionMethod($worker,$method))->invoke($worker);return null;}catch(Throwable $e){return$e;}};
 $seed=function()use($db,$frame,$cursor,$store,$bot){$h=(new LLM\MemorySourceReader())->conversation($store->frame($bot)['rows'],$frame['first_id'],$cursor,$bot)['hash'];$s=$db->prepare("UPDATE bot_conversation_summaries SET summary_text='Синтетический старый конспект',source_hash=?,cursor_id=?,revision=revision+1,attempts=0,last_attempt=NULL,disabled=0 WHERE session_key=?");$s->bind_param('sis',$h,$cursor,$frame['key']);$s->execute();return$store->record($frame['key']);};
 $status=fn($id)=>$db->query("SELECT status,attempts,run_after>UTC_TIMESTAMP() deferred FROM llm_jobs WHERE id=$id")->fetch_assoc();$count=fn()=>(int)$db->query("SELECT COUNT(*) FROM chat_messages WHERE user_id=$bot")->fetch_row()[0];
 $due=function($id)use($db){$db->query("UPDATE llm_jobs SET run_after=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE id=$id");};
 if(in_array($case,['all','R01'],true)){
  $set('ai_return_memory_enabled',0);$r=$seed();$peer->begin_transaction();$s=$peer->prepare('SELECT session_key FROM bot_conversation_summaries WHERE session_key=? FOR UPDATE');$s->bind_param('s',$frame['key']);$s->execute();$s->get_result()->fetch_all();
  $third->query("UPDATE account_privacy SET state='withdrawn' WHERE user_id=$uid");$ctx=[['role'=>'user','content'=>'Вопрос наблюдателя']];$start=microtime(true);$during=$llm->conversationContext($ctx,[$observer]);
  $ok($during===$ctx&&microtime(true)-$start<0.8,'R01 contended invalidation hides summary promptly');
  $again=$llm->conversationContext($ctx,[$observer]);$ok($again===$ctx,'R01 busy summary remains unavailable until retry');$peer->rollback();
  $set('ai_evening_summary_enabled',0);$run('conversationMemoryStep');$after=$store->record($frame['key']);$ok($after['summary_text']===''&&$after['source_hash']===''&&(int)$after['cursor_id']===0&&(int)$after['revision']>(int)$r['revision'],'R01 next worker tick clears deferred derivative even with flags off');
  $third->query("UPDATE account_privacy SET state='active' WHERE user_id=$uid");$set('ai_evening_summary_enabled',1);$old=$seed();$peer->begin_transaction();$s->execute();$s->get_result()->fetch_all();$third->query("UPDATE chat_messages SET message='Прямая правка' WHERE id={$mids[0]}");$llm->conversationContext($ctx,[$observer]);$third->query("UPDATE chat_messages SET message='Синтетический источник 0' WHERE id={$mids[0]}");$peer->rollback();
  $new=$seed();$store->purge();$after=$store->record($frame['key']);$ok($after['summary_text']!==''&&(int)$after['revision']===(int)$new['revision'],'R01 cleanup preserves a newer valid rebuild');
  $r=$seed();$peer->begin_transaction();$s->execute();$s->get_result()->fetch_all();$third->query("UPDATE account_privacy SET state='withdrawn' WHERE user_id=$uid");$llm->conversationContext($ctx,[$observer]);$peer->rollback();
  $jobs[]=$backlog=$q->enqueue('mention',['user_id'=>$observer,'message'=>'Синтетический backlog'],3600);$run('conversationMemoryStep');$after=$store->record($frame['key']);$ok($after['summary_text']===''&&(int)$after['revision']>(int)$r['revision'],'R01 cleanup runs on next tick despite reactive backlog');$db->query("DELETE FROM llm_jobs WHERE id=$backlog");$third->query("UPDATE account_privacy SET state='active' WHERE user_id=$uid");
  $r=$seed();$peer->begin_transaction();$s->execute();$s->get_result()->fetch_all();$third->query("UPDATE account_privacy SET state='withdrawn' WHERE user_id=$uid");$llm->conversationContext($ctx,[$observer]);$peer->rollback();
  $appDb=Infra\Database::getInstance()->getConnection();$appDb->begin_transaction();$store->purge();$appDb->rollback();$set('ai_evening_summary_enabled',0);$run('conversationMemoryStep');$after=$store->record($frame['key']);$ok($after['summary_text']===''&&$llm->conversationContext($ctx,[$observer])===$ctx,'R01 caller rollback is retried and next disabled tick clears text');$third->query("UPDATE account_privacy SET state='active' WHERE user_id=$uid");
 }
 if(in_array($case,['all','R09'],true)){
  $set('ai_evening_summary_enabled',0);$set('ai_return_memory_enabled',1);$store->arrival($uid,30*86400);$fake->calls=0;
  $fake->callback=function($n)use($peer){if($n===1){$peer->begin_transaction();$peer->query("SELECT value FROM site_options WHERE key_name='ai_return_memory_enabled' FOR UPDATE")->fetch_all();}};
  $jobs[]=$first=$q->enqueue('greeting',['user_id'=>$uid,'username'=>'Synthetic'],-1);$jobs[]=$second=$q->enqueue('greeting',['user_id'=>$observer,'username'=>'Observer'],-1);$error=$run('reactive');
  $ok($error===null&&$status($first)['status']==='done','R09 option row lock never causes returning publication contention');$ok($status($second)['status']==='done'&&$fake->calls===2&&$count()===1,'R09 both native batch tasks finish with legacy duplicate suppression');$peer->rollback();$fake->callback=null;
  foreach([3572,1205,1213]as$code){
   $broken=new class($code) extends LLM\LLMManager{public int $calls=0;public function __construct(private int $code){}public function isEnabled(){return true;}public function processTrigger($t,$d=[]){$this->calls++;throw new mysqli_sql_exception('synthetic contention',$this->code);}};
   (new ReflectionProperty($worker,'llm'))->setValue($worker,$broken);$jobs[]=$id=$q->enqueue('greeting',['user_id'=>$observer,'username'=>'Observer'],-1);$error=$run('reactive');$a=$status($id);
   $ok($error instanceof mysqli_sql_exception&&$a['status']==='processing'&&(int)$a['attempts']===0,"R09 native SQL $code retains baseline processing state without retry");$run('reactive');$db->query("UPDATE llm_jobs SET claimed_at=UTC_TIMESTAMP()-INTERVAL 601 SECOND WHERE id=$id");$q->failStaleAny(LLM\JobQueue::REACTIVE_TYPES,600);$ok($broken->calls===1&&$status($id)['status']==='failed',"R09 native SQL $code is closed by reaper without a second call");
  }
  (new ReflectionProperty($worker,'llm'))->setValue($worker,$llm);$set('ai_evening_summary_enabled',1);$r=$seed();$db->query("UPDATE bot_conversation_summaries SET summary_text='',source_hash='',cursor_id=0 WHERE session_key='{$frame['key']}'");$peer->begin_transaction();$peer->query("SELECT session_key FROM bot_conversation_summaries WHERE session_key='{$frame['key']}' FOR UPDATE")->fetch_all();$fake->calls=0;$jobs[]=$id=$q->enqueue('evening_summary',['session_key'=>$frame['key'],'revision'=>(int)$r['revision']],-1);$error=$run('conversationMemoryStep');$ok($error===null&&$status($id)['status']==='done'&&$fake->calls===0,'R09 summary row contention skips publication and completes task');$peer->rollback();
  $set('ai_evening_summary_enabled',0);$store->arrival($uid,30*86400);$peer->begin_transaction();$peer->query("SELECT user_id FROM bot_memory_arrivals WHERE user_id=$uid FOR UPDATE")->fetch_all();$protected=false;$fake->callback=function($n,$c)use(&$protected){$protected=str_contains(json_encode($c),LLM\ConversationMemoryPolicy::RETURN_MARKER);};$jobs[]=$id=$q->enqueue('greeting',['user_id'=>$uid,'username'=>'Synthetic'],-1);$start=microtime(true);$error=$run('reactive');$ok($error===null&&$status($id)['status']==='done'&&!$protected&&microtime(true)-$start<0.8,'R09 busy arrival degrades to ordinary context and never leaves processing');$peer->rollback();$fake->callback=null;
 }
}finally{
 $workerConnection->query("SELECT RELEASE_LOCK('bot_worker')");
 $peer->rollback();$third->rollback();Domain\Privacy::unlockAccount($uid??0);
 foreach($jobs as$id)$db->query('DELETE FROM llm_jobs WHERE id='.(int)$id);$db->query("DELETE FROM llm_jobs WHERE type='evening_summary'");
 foreach($users as$id){$db->query("DELETE FROM bot_memory_arrivals WHERE user_id=$id");$db->query("DELETE FROM chat_messages WHERE user_id=$id");foreach(['privacy_consent_events','account_privacy']as$t)$db->query("DELETE FROM $t WHERE user_id=$id");$db->query("DELETE FROM users WHERE id=$id");}
 foreach($keys as$k){$s=$db->prepare('DELETE FROM bot_conversation_summaries WHERE session_key=?');$s->bind_param('s',$k);$s->execute();}
 foreach($opts as$k=>$v){if($v===null){$s=$db->prepare('DELETE FROM site_options WHERE key_name=?');$s->bind_param('s',$k);$s->execute();}else$cfg->setOption($k,$v);}$cfg->flushCache();$peer->close();$third->close();
}
echo "$total checks, $fail failures\n";echo $fail?'FAIL':'ALL PASS';echo"\n";exit((int)($fail>0));
