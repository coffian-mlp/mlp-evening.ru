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
class ObservationFakeProvider implements LLM\LLMProviderInterface {
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
 $llm=new LLM\LLMManager();$fake=new ObservationFakeProvider();foreach(['providers','fastProviders']as$p)(new ReflectionProperty(LLM\LLMManager::class,$p))->setValue($llm,[$fake]);
 $store=new Store();$frame=$store->frame($bot);$keys[]=$frame['key'];$store->create($frame);$cursor=max($mids);$q=new LLM\JobQueue();$worker=new LLM\BotWorker();(new ReflectionProperty($worker,'llm'))->setValue($worker,$llm);
 $run=function($method)use($worker){try{if($method==='conversationMemoryStep')(new Store())->purge();(new ReflectionMethod($worker,$method))->invoke($worker);return null;}catch(Throwable $e){return$e;}};
 $seed=function()use($db,$frame,$cursor,$store,$bot){$h=(new LLM\MemorySourceReader())->conversation($store->frame($bot)['rows'],$frame['first_id'],$cursor,$bot)['hash'];$s=$db->prepare("UPDATE bot_conversation_summaries SET summary_text='Синтетический старый конспект',source_hash=?,cursor_id=?,revision=revision+1,attempts=0,last_attempt=NULL,disabled=0 WHERE session_key=?");$s->bind_param('sis',$h,$cursor,$frame['key']);$s->execute();return$store->record($frame['key']);};
 $status=fn($id)=>$db->query("SELECT status,attempts,run_after>UTC_TIMESTAMP() deferred FROM llm_jobs WHERE id=$id")->fetch_assoc();$count=fn()=>(int)$db->query("SELECT COUNT(*) FROM chat_messages WHERE user_id=$bot")->fetch_row()[0];
 $due=function($id)use($db){$db->query("UPDATE llm_jobs SET run_after=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE id=$id");};

 if(in_array($case,['all','schema'],true)){
  $ok((int)$db->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()")->fetch_row()[0]===0,'schema migration installs no triggers');
  $ok((int)$db->query("SELECT COUNT(*) FROM site_options WHERE key_name='ai_conversation_epoch'")->fetch_row()[0]===0,'schema no global conversation counter');
  $ok($db->query("SHOW COLUMNS FROM bot_conversation_summaries LIKE 'source_epoch'")->num_rows===0,'schema no unused epoch column');
 }
 if(in_array($case,['all','observed'],true)){
  $set('ai_return_memory_enabled',0);$set('ai_evening_summary_enabled',1);$r=$seed();$peer->query("UPDATE account_privacy SET state='withdrawn' WHERE user_id=$uid");$text=$store->summary($store->frame($bot),$bot);$a=$store->record($frame['key']);$ok($text===null&&$a['summary_text']===''&&$a['source_hash']===''&&(int)$a['cursor_id']===0,'observed read mismatch clears persisted derivative under its row lock');
  $peer->query("UPDATE account_privacy SET state='active' WHERE user_id=$uid");$ok($store->summary($store->frame($bot),$bot)===null,'observed source restoration cannot revive cleared summary');
  $r=$seed();$peer->begin_transaction();$s=$peer->prepare('SELECT session_key FROM bot_conversation_summaries WHERE session_key=? FOR UPDATE');$s->bind_param('s',$frame['key']);$s->execute();$s->get_result()->fetch_all();$third->query("UPDATE account_privacy SET state='withdrawn' WHERE user_id=$uid");$ctx=[['role'=>'user','content'=>'Вопрос наблюдателя']];$start=microtime(true);$out=$llm->conversationContext($ctx,[$observer]);$ok($out===$ctx&&microtime(true)-$start<0.8,'observed busy summary is denied without waiting');$peer->rollback();$run('conversationMemoryStep');$a=$store->record($frame['key']);$ok($a['summary_text']===''&&$a['source_hash']===''&&(int)$a['cursor_id']===0,'observed next tick retries current bad signature after row release');$third->query("UPDATE account_privacy SET state='active' WHERE user_id=$uid");$ok($store->summary($store->frame($bot),$bot)===null,'observed restored source remains empty after worker cleanup');
 }
 if(in_array($case,['all','cycle'],true)){
  $set('ai_evening_summary_enabled',1);$r=$seed();$set('ai_evening_summary_enabled',0);$run('conversationMemoryStep');$a=$store->record($frame['key']);$ok($a['summary_text']===''&&(int)$a['revision']>(int)$r['revision'],'cycle disabled tick clears derivative in its own transaction');$set('ai_evening_summary_enabled',1);$ok($store->summary($store->frame($bot),$bot)===null,'cycle re-enabling after cleanup cannot revive old derivative');
  $r=$seed();$set('ai_memory_enabled',0);$run('conversationMemoryStep');$ok($store->record($frame['key'])['summary_text']==='','cycle global disabled tick clears summary');$set('ai_memory_enabled',1);
  $set('ai_return_memory_enabled',1);$store->arrival($uid,30*86400);$set('ai_return_memory_enabled',0);$run('conversationMemoryStep');$ok((int)$db->query("SELECT COUNT(*) FROM bot_memory_arrivals WHERE user_id=$uid")->fetch_row()[0]===0,'cycle return disabled tick clears arrival');
  $r=$seed();$peer->begin_transaction();$s=$peer->prepare('SELECT session_key FROM bot_conversation_summaries WHERE session_key=? FOR UPDATE');$s->bind_param('s',$frame['key']);$s->execute();$s->get_result()->fetch_all();$start=microtime(true);$set('ai_evening_summary_enabled',0);$peer->rollback();$ok(microtime(true)-$start<0.8&&(int)$cfg->getOption('ai_evening_summary_enabled')===0,'cycle administrative disable never waits for summary row');
 }
 if(in_array($case,['all','parity'],true)){
  $set('ai_evening_summary_enabled',0);$set('ai_return_memory_enabled',0);foreach(['ai_use_queue'=>1,'ai_worker_mode'=>'cron','ai_greeting_cooldown'=>0]as$k=>$v)$set($k,$v);$db->query("UPDATE chat_messages SET created_at=UTC_TIMESTAMP() WHERE id=$cursor");
  $state=dirname(__DIR__).'/paths-probe-state.json';file_put_contents($state,json_encode(['uid'=>$uid,'bot'=>$bot,'message'=>$cursor]));$admin=new mysqli('db','root',Infra\Env::get('DB_ROOT_PASS'),Infra\Env::get('DB_NAME'));$triggers=$admin->query('SHOW TRIGGERS')->fetch_all(MYSQLI_ASSOC);$ddl=[];
  foreach($triggers as$t){$name=$t['Trigger'];$ddl[]=$admin->query('SHOW CREATE TRIGGER `'.$name.'`')->fetch_assoc()['SQL Original Statement'];$admin->query('DROP TRIGGER `'.$name.'`');}
  $read=function($mode)use($state){$pipes=[];$p=proc_open([PHP_BINARY,__DIR__.'/helpers/conversation_paths_probe.php',$mode,$state],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);foreach($pipes as$x)fclose($x);if(proc_close($p)||$err!=='')throw new RuntimeException('Paths probe failed');return json_decode(trim($out),true,512,JSON_THROW_ON_ERROR);};
  try{$base=$read('baseline');foreach($ddl as$sql)$admin->query($sql);$current=$read('current');echo json_encode(['paths_baseline'=>$base,'paths_current'=>$current]),"\n";foreach($base as$name=>$x)$ok($x===$current[$name],"parity flags0 $name has no additional SQL");}finally{unlink($state);$admin->close();}
 }
 if(in_array($case,['all','site-lock'],true)){
  $set('ai_evening_summary_enabled',0);$set('ai_return_memory_enabled',0);$db->query("UPDATE chat_messages SET created_at=UTC_TIMESTAMP() WHERE id=$cursor");$peer->begin_transaction();$peer->query('SELECT key_name FROM site_options ORDER BY key_name FOR UPDATE')->fetch_all();$app=Infra\Database::getInstance()->getConnection();$app->query('SET SESSION innodb_lock_wait_timeout=1');$start=microtime(true);$res=false;try{$res=(new Domain\ChatManager())->editMessage($cursor,$uid,'Правка без настроек');}catch(mysqli_sql_exception $e){}finally{$peer->rollback();$app->query('SET SESSION innodb_lock_wait_timeout=50');}$ok($res&&microtime(true)-$start<0.8,'site-lock flags0 source edit does not write or lock site_options');
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
