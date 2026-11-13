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
class LifecycleFakeProvider implements LLM\LLMProviderInterface {
 public int $calls=0;public ?Closure $callback=null;
 public function withTimeout(int $t):static{return$this;}
 public function askChat(array $c,string $p):?string{$this->calls++;if($this->callback)($this->callback)($this->calls,$c);return 'Рада снова видеть. Помню синтетическую тему.';}
}
try{
 $uid=$mk();$bot=$mk();$observer=$mk();foreach(['ai_enabled'=>1,'ai_bot_user_id'=>$bot,'ai_primary_provider'=>'routerai','ai_routerai_key'=>'synthetic_no_network','ai_fast_model'=>'synthetic','ai_memory_enabled'=>1,'ai_memory_recall_enabled'=>0,'ai_evening_summary_enabled'=>1,'ai_return_memory_enabled'=>1,'ai_memory_block_limit'=>8000,'ai_online_in_context'=>0,'ai_reactions'=>0,'ai_memory_auto'=>0,'ai_auto_draw'=>0,'ai_lifelike'=>0,'ai_greeting_cooldown'=>0,'ai_debug_log'=>0]as$k=>$v)$set($k,$v);
 for($i=0;$i<25;$i++){$at=gmdate('Y-m-d H:i:s',time()-1500+$i*10);$s=$db->prepare('INSERT INTO chat_messages(user_id,username,message,created_at) VALUES(?,?,?,?)');$n='Synthetic';$t='Синтетический источник '.$i;$s->bind_param('isss',$uid,$n,$t,$at);$s->execute();$mids[]=(int)$db->insert_id;}
 $llm=new LLM\LLMManager();$fake=new LifecycleFakeProvider();foreach(['providers','fastProviders']as$p)(new ReflectionProperty(LLM\LLMManager::class,$p))->setValue($llm,[$fake]);
 $store=new Store();$frame=$store->frame($bot);$keys[]=$frame['key'];$store->create($frame);$cursor=max($mids);$q=new LLM\JobQueue();$worker=new LLM\BotWorker();(new ReflectionProperty($worker,'llm'))->setValue($worker,$llm);
 $run=function($method)use($worker){try{if($method==='conversationMemoryStep')(new Store())->purge();(new ReflectionMethod($worker,$method))->invoke($worker);return null;}catch(Throwable $e){return$e;}};
 $seed=function()use($db,$frame,$cursor,$store,$bot){$h=(new LLM\MemorySourceReader())->conversation($store->frame($bot)['rows'],$frame['first_id'],$cursor,$bot)['hash'];$s=$db->prepare("UPDATE bot_conversation_summaries SET summary_text='Синтетический старый конспект',source_hash=?,cursor_id=?,revision=revision+1,attempts=0,last_attempt=NULL,disabled=0 WHERE session_key=?");$s->bind_param('sis',$h,$cursor,$frame['key']);$s->execute();return$store->record($frame['key']);};
 $status=fn($id)=>$db->query("SELECT status,attempts,run_after>UTC_TIMESTAMP() deferred FROM llm_jobs WHERE id=$id")->fetch_assoc();$count=fn()=>(int)$db->query("SELECT COUNT(*) FROM chat_messages WHERE user_id=$bot")->fetch_row()[0];
 $due=function($id)use($db){$db->query("UPDATE llm_jobs SET run_after=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE id=$id");};


 $child=function($test,$args)use($uid,$bot,$cursor){$pipes=[];$p=proc_open(array_merge([PHP_BINARY,__DIR__.'/helpers/'.$test],$args),[1=>['pipe','w'],2=>['pipe','w']],$pipes);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);foreach($pipes as$x)fclose($x);if(proc_close($p)||$err!=='')throw new RuntimeException('Lifecycle child failed: '.$err);return json_decode(trim($out),true,512,JSON_THROW_ON_ERROR);};
 $state=dirname(__DIR__).'/lifecycle-state.json';
 if(in_array($case,['all','queued'],true)){
  file_put_contents($state,json_encode(['uid'=>$uid,'bot'=>$bot]));$base=$child('conversation_queue_probe.php',['baseline',$state]);$now=$child('conversation_queue_probe.php',['current',$state]);echo json_encode(['baseline'=>$base,'current'=>$now]),"\n";$ok($base['min']===37&&$base['max']===37&&$now['min']===39&&$now['max']===39&&$now['all_done']&&$now['fake_calls']===6,'queued native flags0 greeting adds directory and privacy lookups to baseline37 SQL');unlink($state);$cfg->flushCache();
 }
 if(in_array($case,['all','ordinary','return'],true)){
  foreach(['ordinary','return']as$kind){if($case!=='all'&&$case!==$kind)continue;
   $db->query("DELETE FROM chat_messages WHERE user_id=$bot");$jobs[]=$job=$q->enqueue('greeting',['user_id'=>$uid,'username'=>'Synthetic'],-1);$arrival=null;
   if($kind==='return'){$set('ai_return_memory_enabled',1);$store->arrival($uid,30*86400);$arrival=['user_id'=>$uid,'token'=>$store->claimArrival($uid)];}else{$set('ai_evening_summary_enabled',0);$set('ai_return_memory_enabled',0);}
   file_put_contents($state,json_encode(['uid'=>$uid,'bot'=>$bot,'job'=>$job,'text'=>'Синтетический одинаковый ответ','arrival'=>$arrival]));$r=$child('conversation_delivery_probe.php',[$state,$kind]);unlink($state);echo json_encode([$kind=>$r]),"\n";
   $ok($r['messages']===1,"$kind concurrent publication creates exactly one chat row");if($kind==='return'){$ok($r['peer']['lock_acquired']===0,'return duplicate lock remains held through receipt and COMMIT');$ok($r['token_peer']['result']===false,'return concurrent token publication is skipped before a second insert');$a=$db->query("SELECT state,delivery_message_id FROM bot_memory_arrivals WHERE user_id=$uid")->fetch_assoc();$ok($a['state']==='done'&&(int)$a['delivery_message_id']===(int)$r['result'],'return token and message receipt commit together');}
  }
 }
 if(in_array($case,['all','source'],true)){
  $set('ai_evening_summary_enabled',1);$db->query("UPDATE chat_messages SET created_at=UTC_TIMESTAMP() WHERE id=$cursor");$seed();$peer->begin_transaction();$s=$peer->prepare('SELECT session_key FROM bot_conversation_summaries WHERE session_key=? FOR UPDATE');$s->bind_param('s',$frame['key']);$s->execute();$s->get_result()->fetch_all();
   foreach(['edit'=>fn()=>(new Domain\ChatManager())->editMessage($cursor,$uid,'Прикладная правка'),'delete'=>fn()=>(new Domain\ChatManager())->deleteMessage($cursor,$uid),'restore'=>fn()=>(new Domain\ChatManager())->restoreMessage($cursor,$uid),'consent'=>fn()=>Domain\Privacy::setAccountConsent($uid,false,false)]as$name=>$work){$start=microtime(true);$error=null;$res=false;try{$res=$work();}catch(Throwable $e){$error=$e;}echo json_encode(['source'=>$name,'result'=>(bool)$res,'error'=>$error?get_class($error):'','code'=>$error?->getCode()??0,'seconds'=>microtime(true)-$start]),"\n";$ok($res&&$error===null&&microtime(true)-$start<0.8,"source $name succeeds promptly with summary row held");}
   $peer->rollback();Domain\Privacy::setAccountConsent($uid,true,false);Domain\Privacy::unlockAccount($uid);
 }
 if(in_array($case,['all','outer'],true)){
  $set('ai_evening_summary_enabled',1);foreach(['read','purge','invalidate','publish','schedule']as$path){$r=$seed();$peer->query("UPDATE account_privacy SET state='withdrawn' WHERE user_id=$uid");$db->begin_transaction();$out=null;try{if($path==='read')$out=$store->summary($store->frame($bot),$bot);elseif($path==='purge')$store->purge();elseif($path==='invalidate')$store->invalidate($frame['key']);elseif($path==='publish')$out=$store->publish($frame,$r,'Не публиковать',$cursor,$r['source_hash'],$bot,time());else$out=Memory::schedule($llm);$inside=$store->record($frame['key']);$ok(($out===null||$out===false)&&$inside['summary_text']===$r['summary_text']&&(int)$inside['revision']===(int)$r['revision'],"outer $path refuses observation and mutation inside native transaction");}finally{$db->rollback();}
   $run('conversationMemoryStep');$peer->query("UPDATE account_privacy SET state='active' WHERE user_id=$uid");$ok($store->summary($store->frame($bot),$bot)===null,"outer $path next own worker observation clears permanently");
  }
 }
 if(in_array($case,['all','consentABA'],true)){
  $set('ai_evening_summary_enabled',1);$db->query('SET timestamp='.(int)time());Domain\Privacy::setAccountConsent($uid,true,false);$r=$seed();$peer->begin_transaction();$s=$peer->prepare('SELECT session_key FROM bot_conversation_summaries WHERE session_key=? FOR UPDATE');$s->bind_param('s',$frame['key']);$s->execute();$s->get_result()->fetch_all();$error=null;try{Domain\Privacy::setAccountConsent($uid,false,false);Domain\Privacy::setAccountConsent($uid,true,false);}catch(Throwable $e){$error=$e;}finally{Domain\Privacy::unlockAccount($uid);$peer->rollback();$db->query('SET timestamp=DEFAULT');}$ok($error===null&&$store->summary($store->frame($bot),$bot)===null,'consentABA event id detects application A-B-A when hook is busy');
 }
 if(in_array($case,['all','flaglock'],true)){
  $set('ai_evening_summary_enabled',1);$r=$seed();$peer->begin_transaction();$peer->query("SELECT value FROM site_options WHERE key_name='ai_evening_summary_enabled' FOR UPDATE")->fetch_all();$start=microtime(true);$error=null;$res=false;try{$res=$store->publish($frame,$r,'Публикация при чужом lock настройки',$cursor,$r['source_hash'],$bot,time());}catch(Throwable $e){$error=$e;}finally{$peer->rollback();}$ok($res&&$error===null&&microtime(true)-$start<0.8,'flaglock fresh nonlocking flag read cannot cause SQL contention');
 }

 if(in_array($case,['all','chatABA'],true)){
  $set('ai_evening_summary_enabled',1);$db->query("UPDATE chat_messages SET is_deleted=0,created_at=UTC_TIMESTAMP() WHERE id=$cursor");$db->query('SET timestamp='.(int)time());$chat=new Domain\ChatManager();$chat->editMessage($cursor,$uid,'Одинаковое начало');$r=$seed();$peer->begin_transaction();$peer->query("SELECT session_key FROM bot_conversation_summaries WHERE session_key='{$frame['key']}' FOR UPDATE")->fetch_all();
  try{$a=$chat->editMessage($cursor,$uid,'Промежуточная правка');$b=$chat->editMessage($cursor,$uid,'Одинаковое начало');}finally{$peer->rollback();$db->query('SET timestamp=DEFAULT');}
  $retained=$store->record($frame['key'])['summary_text']!=='';$ok($a&&$b&&$retained&&$store->summary($store->frame($bot),$bot)===null,'chatABA same-clock edits are detected without a successful hook');
 }
 if(in_array($case,['all','callbacks'],true)){
  $ok(!method_exists(Infra\Transaction::class,'afterCommit'),'callbacks phase3 callback infrastructure is removed');
  $r=$seed();$peer->query("UPDATE account_privacy SET state='withdrawn' WHERE user_id=$uid");$inside=Infra\Transaction::run($db,fn()=>$store->summary($store->frame($bot),$bot));$ok($inside===null&&$store->record($frame['key'])['summary_text']===$r['summary_text'],'callbacks managed outer transaction also refuses summary observation');$run('conversationMemoryStep');$peer->query("UPDATE account_privacy SET state='active' WHERE user_id=$uid");
 }
 if(in_array($case,['all','erasure'],true)){
  $set('ai_evening_summary_enabled',1);$set('ai_return_memory_enabled',1);$extra=$mk();$db->query("INSERT INTO chat_messages(user_id,username,message,created_at) VALUES($extra,'Synthetic','Синтетический стираемый источник',UTC_TIMESTAMP())");$erasedMessage=(int)$db->insert_id;$store->arrival($extra,30*86400);Domain\Privacy::setAccountConsent($extra,false,false);$db->query("UPDATE account_privacy SET delete_after=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE user_id=$extra");$seed();$peer->begin_transaction();$peer->query("SELECT session_key FROM bot_conversation_summaries WHERE session_key='{$frame['key']}' FOR UPDATE")->fetch_all();$start=microtime(true);$res=Domain\AccountErasure::eraseDue($extra);$ok($res&&microtime(true)-$start<0.8,'erasure hard account transaction succeeds with summary row held');Domain\Privacy::unlockAccount($extra);$peer->rollback();$store->purge();$ok($store->record($frame['key'])['summary_text']===''&&(int)$db->query("SELECT COUNT(*) FROM bot_memory_arrivals WHERE user_id=$extra")->fetch_row()[0]===0,'erasure next tick clears anonymized source and pending arrival');
 }
}finally{
 $peer->rollback();$third->rollback();Domain\Privacy::unlockAccount($uid??0);if(isset($extra))Domain\Privacy::unlockAccount($extra);if(isset($erasedMessage))$db->query('DELETE FROM chat_messages WHERE id='.(int)$erasedMessage);
 foreach($jobs as$id)$db->query('DELETE FROM llm_jobs WHERE id='.(int)$id);$db->query("DELETE FROM llm_jobs WHERE type='evening_summary'");
 foreach($users as$id){$db->query("DELETE FROM bot_memory_arrivals WHERE user_id=$id");$db->query("DELETE FROM chat_messages WHERE user_id=$id");foreach(['privacy_consent_events','account_privacy']as$t)$db->query("DELETE FROM $t WHERE user_id=$id");$db->query("DELETE FROM users WHERE id=$id");}
 foreach($keys as$k){$s=$db->prepare('DELETE FROM bot_conversation_summaries WHERE session_key=?');$s->bind_param('s',$k);$s->execute();}
 foreach($opts as$k=>$v){if($v===null){$s=$db->prepare('DELETE FROM site_options WHERE key_name=?');$s->bind_param('s',$k);$s->execute();}else$cfg->setOption($k,$v);}$cfg->flushCache();$peer->close();$third->close();
}
echo "$total checks, $fail failures\n";echo $fail?'FAIL':'ALL PASS';echo"\n";exit((int)($fail>0));
