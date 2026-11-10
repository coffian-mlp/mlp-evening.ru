<?php
ob_start();require_once __DIR__.'/integration_helpers.php';
use Domain\ConversationMemoryStore as Store;
use LLM\ConversationMemory as Memory;
use LLM\ConversationMemoryPolicy as Policy;
$db=it_require_db();$cfg=Infra\ConfigManager::getInstance();$cases=isset($argv[1])?[$argv[1]]:['R01','R02','R03','R04','R05','R06','R07','R08'];
$tag='it_cmfix_'.bin2hex(random_bytes(5));$users=[];$mids=[];$keys=[];$opts=[];$jobs=[];$total=0;$failed=0;
$ok=function($condition,$name)use(&$total,&$failed){$total++;if(!$condition)$failed++;echo($condition?'PASS ':'FAIL ').$name."\n";};
$set=function($k,$v)use($cfg,&$opts){if(!array_key_exists($k,$opts))$opts[$k]=$cfg->getOption($k,null);$cfg->setOption($k,(string)$v);};
$peer=new mysqli('db',Infra\Env::get('DB_USER'),Infra\Env::get('DB_PASS'),Infra\Env::get('DB_NAME'));$peer->set_charset('utf8mb4');
$mk=function($name)use($db,$tag,&$users){$login=$tag.$name;$s=$db->prepare("INSERT INTO users(login,nickname,password_hash,role) VALUES(?,?,'synthetic','user')");$s->bind_param('ss',$login,$login);$s->execute();$id=(int)$db->insert_id;$users[]=$id;Domain\Privacy::setAccountConsent($id,true,false);Domain\Privacy::unlockAccount($id);return$id;};
$store=new Store();$now=time();$children=[];
// Low-level context assertions run on the same connection as a publishing worker.
$workerConnection=Infra\Database::getInstance()->getConnection();
if(!(int)$workerConnection->query("SELECT GET_LOCK('bot_worker',0)")->fetch_row()[0])throw new RuntimeException('Test worker slot unavailable');
try{
 $uid=$mk('_human');$bot=$mk('_bot');
 foreach(['ai_enabled'=>1,'ai_bot_user_id'=>$bot,'ai_primary_provider'=>'routerai','ai_routerai_key'=>'synthetic_no_network','ai_fast_model'=>'synthetic_fast','ai_memory_enabled'=>1,'ai_memory_recall_enabled'=>0,'ai_evening_summary_enabled'=>1,'ai_return_memory_enabled'=>1,'ai_return_memory_days'=>14,'ai_memory_block_limit'=>8000,'ai_online_in_context'=>0,'ai_reactions'=>0,'ai_memory_auto'=>0,'ai_auto_draw'=>0,'ai_image_auto_interval'=>0,'bot_worker_heartbeat'=>0,'ai_proactive_interval'=>PHP_INT_MAX,'bot_last_proactive'=>time(),'ai_debug_log'=>1]as$k=>$v)$set($k,$v);
 for($i=0;$i<25;$i++){$at=gmdate('Y-m-d H:i:s',$now-1500+$i*10);$text='Синтетический источник '.$i;$s=$db->prepare('INSERT INTO chat_messages(user_id,username,message,created_at) VALUES(?,?,?,?)');$s->bind_param('isss',$uid,$tag,$text,$at);$s->execute();$mids[]=(int)$db->insert_id;}
 $llm=new class extends LLM\LLMManager{public function generateConversationSummary(string $p,float $d):?string{return 'Синтетический конспект о чае.';}};
 $frame=$store->frame($bot);$keys[]=$frame['key'];$store->create($frame);$cursor=max($mids);
 $seed=function()use($db,$store,$frame,$bot,$cursor){$hash=(new LLM\MemorySourceReader())->conversation($store->frame($bot)['rows'],$frame['first_id'],$cursor,$bot)['hash'];$s=$db->prepare("UPDATE bot_conversation_summaries SET summary_text='Синтетический сохранённый конспект',source_hash=?,cursor_id=?,attempts=0,disabled=0,last_attempt=NULL,revision=revision+1 WHERE session_key=?");$s->bind_param('sis',$hash,$cursor,$frame['key']);$s->execute();return$store->record($frame['key']);};
 $cleared=function($record)use($store,$frame){$r=$store->record($frame['key']);return$r['summary_text']===''&&$r['source_hash']===''&&(int)$r['cursor_id']===0&&(int)$r['revision']>(int)$record['revision'];};
 foreach($cases as$case){
 if($case==='R01'){
  $mutations=[
   ['edit',"UPDATE chat_messages SET message='Прямая синтетическая правка' WHERE id={$mids[0]}","UPDATE chat_messages SET message='Синтетический источник 0' WHERE id={$mids[0]}"],
   ['delete',"UPDATE chat_messages SET is_deleted=1 WHERE id={$mids[0]}","UPDATE chat_messages SET is_deleted=0 WHERE id={$mids[0]}"],
   ['withdraw',"UPDATE account_privacy SET state='withdrawn' WHERE user_id=$uid","UPDATE account_privacy SET state='active' WHERE user_id=$uid"],
   ['deleted_account',"UPDATE account_privacy SET state='deleted' WHERE user_id=$uid","UPDATE account_privacy SET state='active' WHERE user_id=$uid"],
   ['erase_source',"UPDATE chat_messages SET user_id=NULL WHERE id={$mids[0]}","UPDATE chat_messages SET user_id=$uid WHERE id={$mids[0]}"]
  ];
  foreach($mutations as[$name,$change,$restore])foreach(['read','schedule','publish']as$path){
   $r=$seed();$peer->query($change);
   try{if($path==='read')$store->summary($store->frame($bot),$bot);elseif($path==='schedule')Memory::schedule($llm);else$store->publish($frame,$r,'Недопустимый поздний результат',$cursor,$r['source_hash'],$bot,time());
    $ok($cleared($r),"R01 $name $path clears persisted derivative with revision");
   }finally{$peer->query($restore);}
   $ok($store->summary($store->frame($bot),$bot)===null,"R01 $name $path restoring consent/source cannot revive old summary");
   $db->query("DELETE FROM llm_jobs WHERE type='evening_summary'");
  }
  $stale=$seed();$newer=$seed();$store->invalidate($frame['key'],(int)$stale['revision']);$retained=$store->record($frame['key']);$ok($retained['summary_text']!==''&&(int)$retained['revision']===(int)$newer['revision'],'R01 stale invalidation cannot erase a newer summary revision');
  $r=$seed();$store->invalidate($frame['key'],(int)$r['revision']);$empty=$store->record($frame['key']);$peer->query("UPDATE chat_messages SET message='Изменение во время первой генерации' WHERE id={$mids[0]}");
  $store->publish($frame,$empty,'Недопустимая первая генерация',$cursor,$r['source_hash'],$bot,time());$ok((int)$store->record($frame['key'])['revision']>(int)$empty['revision'],'R01 rejected first completion advances even empty derivative revision');
  $peer->query("UPDATE chat_messages SET message='Синтетический источник 0' WHERE id={$mids[0]}");$accepted=$store->publish($frame,$empty,'Устаревшая генерация после восстановления',$cursor,$r['source_hash'],$bot,time());$ok(!$accepted,'R01 restoring source cannot publish the invalidated generation revision');
  $r=$seed();$db->query('UPDATE chat_messages SET created_at=UTC_TIMESTAMP()-INTERVAL 20 HOUR WHERE id IN ('.implode(',',$mids).')');Memory::schedule($llm);
  $ok($cleared($r), 'R01 unresolved expired session is cleared');
  foreach($mids as$i=>$id){$at=gmdate('Y-m-d H:i:s',$now-1500+$i*10);$s=$db->prepare('UPDATE chat_messages SET created_at=? WHERE id=?');$s->bind_param('si',$at,$id);$s->execute();}
 }
 if($case==='R02'){
  $errorFile=dirname(__DIR__).'/reviewfix-error.log';$previous=ini_get('error_log');ini_set('error_log',$errorFile);
  try{foreach([Policy::SUMMARY_MARKER,Policy::RETURN_MARKER,LLM\MemoryRecallBlock::MARKER]as$marker)foreach(['image','exception','truncated','retry']as$mode){
   $caseFile=$errorFile.'-'.substr(hash('sha256',$marker.$mode),0,12);ini_set('error_log',$caseFile);file_put_contents($caseFile,'');$fake=new class($mode) implements LLM\LLMProviderInterface{public int $n=0;public function __construct(private string $mode){}public function askChat(array $c,string $p):?string{$this->n++;$x='SYNTHETIC_PRIVATE_CANARY';if($this->mode==='image')return$x.' ![фото](https://example.test/a.png)';if($this->mode==='truncated')throw new LLM\TruncatedResponseException($x,$x);if($this->mode==='retry'&&$this->n===1)return'случайное random слово';throw new RuntimeException('OpenAI HTTP Error 400: '.$x);}};
   foreach(['providers','fastProviders']as$p)(new ReflectionProperty(LLM\LLMManager::class,$p))->setValue($llm,[$fake]);
   $llm->withAddressedMessages([],fn()=>$llm->generateReply([['role'=>'user','content'=>$marker.' protected synthetic data']]));
   clearstatcache(true,$caseFile);$log=file_get_contents($caseFile);$ok(str_contains($log,'askWithFallback')||str_contains($log,'LLM Provider'),"R02 $mode rejection sink exercised");$ok($fake->n>0,"R02 $mode fake provider invoked");
   $ok(!str_contains($log,'SYNTHETIC_PRIVATE_CANARY'),"R02 $mode protected error log excludes derivative/provider body");unlink($caseFile);
  }
  $set('ai_evening_summary_enabled',0);$workerFile=$errorFile.'-worker';ini_set('error_log',$workerFile);file_put_contents($workerFile,'');
  $broken=new class extends LLM\LLMManager{public function processTrigger($type,$data=[]){throw new RuntimeException('SYNTHETIC_PRIVATE_CANARY');}};
  $silent=new class implements LLM\LLMProviderInterface{public function askChat(array $c,string $p):?string{return null;}};foreach(['providers','fastProviders']as$p)(new ReflectionProperty(LLM\LLMManager::class,$p))->setValue($broken,[$silent]);
  $worker=new LLM\BotWorker();(new ReflectionProperty($worker,'llm'))->setValue($worker,$broken);$jobs[]=(new LLM\JobQueue())->enqueue('greeting',['user_id'=>$uid,'username'=>$tag],-1);$worker->tick();
  $log=file_get_contents($workerFile);$ok((str_contains($log,'reactive error')||str_contains($log,'job unavailable'))&&!str_contains($log,'SYNTHETIC_PRIVATE_CANARY'),'R02 worker rejection log excludes exception body');unlink($workerFile);
  $facadeFile=$errorFile.'-summary';ini_set('error_log',$facadeFile);file_put_contents($facadeFile,'');
  $nativeFake=new class implements LLM\LLMProviderInterface{public function withTimeout(int $t):static{return$this;}public function askChat(array $c,string $p):?string{throw new RuntimeException('SYNTHETIC_PRIVATE_CANARY');}};
  $facadeLLM=new LLM\LLMManager();foreach(['providers','fastProviders']as$p)(new ReflectionProperty(LLM\LLMManager::class,$p))->setValue($facadeLLM,[$nativeFake]);$thrown=false;$result=null;
  try{$result=$facadeLLM->generateConversationSummary('Синтетические защищённые данные',microtime(true)+20);}catch(Throwable $e){$thrown=true;}
  $ok(!$thrown&&$result===null,'R02 bounded summary error becomes a safe failure');$log=file_get_contents($facadeFile);$ok(str_contains($log,'RuntimeException')&&!str_contains($log,'SYNTHETIC_PRIVATE_CANARY'),'R02 bounded summary logs only failure class');unlink($facadeFile);
  }finally{ini_set('error_log',$previous);if(is_file($errorFile))unlink($errorFile);}
 }
 if($case==='R03'){
  Domain\Privacy::lockAccount($uid);Domain\Privacy::unlockAccount($uid);(new Domain\OnlineManager())->touchUser($uid);
  $lock='privacy-user-'.$uid;$free=(int)$peer->query("SELECT GET_LOCK('$lock',0)")->fetch_row()[0];$ok($free===1,'R03 post-response touch releases its account lock before dispatch');if($free)$peer->query("SELECT RELEASE_LOCK('$lock')");Domain\Privacy::unlockAccount($uid);
  Domain\Privacy::lockAccount($uid);(new Domain\OnlineManager())->touchUser($uid);$held=(int)$peer->query("SELECT GET_LOCK('$lock',0)")->fetch_row()[0];$ok($held===0,'R03 external account lock is preserved');Domain\Privacy::unlockAccount($uid);
 }
 if($case==='R04'){
  foreach(['ai_evening_summary_enabled','ai_memory_enabled']as$flag){$set($flag,1);$r=$seed();Memory::enabled('ai_evening_summary_enabled');$peer->query("UPDATE site_options SET value='0' WHERE key_name='$flag'");
   $accepted=$store->publish($frame,$r,'Поздний конспект после выключения',$cursor,$r['source_hash'],$bot,time());$ok(!$accepted&&$store->record($frame['key'])['summary_text']!=='Поздний конспект после выключения',"R04 fresh $flag overrides cached enabled value");$set($flag,1);
  }
  $set('ai_evening_summary_enabled',0);$store->arrival($uid,30*86400);$ctx=$llm->conversationContext([['role'=>'user','content'=>'Вопрос']],[$uid]);Memory::enabled('ai_return_memory_enabled');$peer->query("UPDATE site_options SET value='0' WHERE key_name='ai_return_memory_enabled'");
  $max=(new Domain\ChatManager())->latestMessageId();$sent=$llm->botSay('Защищённый поздний ответ после выключения');$ok(!$sent&&(new Domain\ChatManager())->latestMessageId()===$max,'R04 returning delivery reads database flags in publication transaction');$llm->finishConversationMemory(false);$set('ai_return_memory_enabled',1);
 }
 if($case==='R05'){
  $set('ai_evening_summary_enabled',1);$r=$seed();$pipes=[];$code='require "autoload.php";$d=Infra\\Database::getInstance()->getConnection();$d->begin_transaction();$d->query("SELECT id FROM chat_messages WHERE id='.$mids[0].' FOR UPDATE");echo "locked\\n";flush();usleep(1500000);$d->commit();';
  $proc=proc_open([PHP_BINARY,'-r',$code],[1=>['pipe','w'],2=>['pipe','w']],$pipes);if(trim(fgets($pipes[1]))!=='locked')throw new RuntimeException('Synthetic locker failed');$start=microtime(true);
  try{$accepted=$store->publish($frame,$r,'Результат после срока',$cursor,$r['source_hash'],$bot,time(),$start+0.2);}catch(Throwable $e){$accepted=false;}
  $elapsed=microtime(true)-$start;stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);foreach($pipes as$p)fclose($p);$exit=proc_close($proc);
  $ok($accepted&&$elapsed<0.8,'R05 nonlocking source validation does not wait for a source writer');$ok($exit===0&&$err==='','R05 lock peer completed normally');
  $r=$seed();$expired=microtime(true)-1;$accepted=$store->publish($frame,$r,'Просроченный результат',$cursor,$r['source_hash'],$bot,time(),$expired);$ok(!$accepted,'R05 expired publication budget is rejected before CAS');
  $frozen=time();foreach($mids as$i=>$id){$at=gmdate('Y-m-d H:i:s',$frozen-5422+$i);$s=$db->prepare('UPDATE chat_messages SET created_at=? WHERE id=?');$s->bind_param('si',$at,$id);$s->execute();}
  $near=$store->frame($bot,$frozen);$keys[]=$near['key'];$store->create($near);$nr=$store->record($near['key']);$hash=(new LLM\MemorySourceReader())->conversation($near['rows'],$near['first_id'],$cursor,$bot)['hash'];
  sleep(3);$accepted=$store->publish($near,$nr,'Результат после закрытия сессии',$cursor,$hash,$bot,$frozen,microtime(true)+20);
  $ok(!$accepted&&$store->record($near['key'])['summary_text']==='','R05 publication rechecks real session time rather than frozen caller clock');
  foreach($mids as$i=>$id){$at=gmdate('Y-m-d H:i:s',$now-1500+$i*10);$s=$db->prepare('UPDATE chat_messages SET created_at=? WHERE id=?');$s->bind_param('si',$at,$id);$s->execute();}

 }
 if($case==='R06'){
  $usageStart=(int)$db->query('SELECT COALESCE(MAX(id),0) FROM llm_usage')->fetch_row()[0];
  $stateFile=dirname(__DIR__).'/reviewfix-replay-state.json';file_put_contents($stateFile,json_encode(['uid'=>$uid,'bot'=>$bot,'first'=>min($mids),'last'=>max($mids)]));$pipes=[];$proc=proc_open([PHP_BINARY,__DIR__.'/helpers/conversation_replay_probe.php','--date=2026-11-07','--allow-model-calls'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
  $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);foreach($pipes as$p)fclose($p);$exit=proc_close($proc);unlink($stateFile);
  $ok($exit===0&&str_contains($out,'REPLAY_READ_ONLY_PASS'),'R06 actual replay fake adapter writes no usage and rejects SQL writes');
  $ok(str_contains($out,'"input_tokens":1000')&&str_contains($out,'"output_tokens":200')&&str_contains($out,'"estimated_rub":0.0722'),'R06 replay reports in-memory tokens and 43/146 RUB estimate');
  file_put_contents($stateFile,json_encode(['uid'=>$uid,'bot'=>$bot,'first'=>min($mids),'last'=>max($mids)]));$pipes=[];putenv('MLP_REPLAY_FAKE_FAIL=1');
  try{$proc=proc_open([PHP_BINARY,__DIR__.'/helpers/conversation_replay_probe.php','--date=2026-11-07','--allow-model-calls'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);}finally{putenv('MLP_REPLAY_FAKE_FAIL');}
  $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);foreach($pipes as$p)fclose($p);$exit=proc_close($proc);unlink($stateFile);
  $ok($exit===0&&str_contains($out,'REPLAY_READ_ONLY_PASS')&&!str_contains($out.$err,'SYNTHETIC_PRIVATE_CANARY'),'R06 replay failure stays read-only and cannot print provider body');
  $db->query("DELETE FROM site_options WHERE key_name='it_replay_should_not_write'");
  $db->query("DELETE FROM llm_usage WHERE id>$usageStart AND provider='routerai' AND model='z-ai/glm-5.3-flashx' AND task='evening_summary'");
 }
 if($case==='R07'){
  $set('ai_evening_summary_enabled',0);$ctx=[['role'=>'user','content'=>'Текущий вопрос']];$store->arrival($uid,30*86400);$first=$llm->conversationContext($ctx,[$uid]);$ok($first!==$ctx,'R07 returning context claimed');
  $sent=$llm->botSay('Синтетический ответ с воспоминанием');$ok((bool)$sent,'R07 chat response persisted');
  // Simulate loss of the post-publication finish step, then stale recovery after process death.
  (new ReflectionProperty(LLM\LLMManager::class,'conversationArrival'))->setValue($llm,null);$db->query("UPDATE bot_memory_arrivals SET claimed_at=UTC_TIMESTAMP()-INTERVAL 91 SECOND WHERE user_id=$uid");
  $next=$llm->conversationContext($ctx,[$uid]);$ok($next===$ctx,'R07 published response cannot reopen returning memory after missing post-step');
  $receipt=$db->query("SELECT * FROM bot_memory_arrivals WHERE user_id=$uid")->fetch_assoc();$ok($receipt['state']==='done'&&(int)($receipt['delivery_message_id']??0)===(int)$sent,'R07 durable token/message receipt exists');$llm->finishConversationMemory(false);
  $store->arrival($uid,30*86400);$token=$store->claimArrival($uid);$db->query("UPDATE bot_memory_arrivals SET claimed_at=UTC_TIMESTAMP()-INTERVAL 91 SECOND WHERE user_id=$uid");$ok($store->claimArrival($uid)===$token,'R07 genuinely unpublished stale claim is recoverable');$store->finishArrival($uid,$token,false);
 }
 if($case==='R08'){
  $ok(Policy::clean('Случайное ｒａｎｄｏｍ слово')===null,'R08 summary rejects normalized fullwidth Latin');$ok(Policy::clean('Русский текст。')==='Русский текст.','R08 accepted summary uses normalized typography');
  $fake=new class implements LLM\LLMProviderInterface{public int $n=0;public function askChat(array $c,string $p):?string{return++$this->n===1?'Случайное ｒａｎｄｏｍ слово':'Исправленная русская реплика.';}};foreach(['providers','fastProviders']as$p)(new ReflectionProperty(LLM\LLMManager::class,$p))->setValue($llm,[$fake]);
  $text=$llm->withAddressedMessages([],fn()=>$llm->generateReply([['role'=>'user','content'=>Policy::RETURN_MARKER.' Данные.']]));$ok($text==='Исправленная русская реплика.'&&$fake->n===2,'R08 returning reply checks normalized output before language retry');
 }
 }
}finally{
 $workerConnection->query("SELECT RELEASE_LOCK('bot_worker')");
 foreach($jobs as$id)$db->query('DELETE FROM llm_jobs WHERE id='.(int)$id);
 Domain\Privacy::unlockAccount($uid??0);foreach($users as$id){$db->query("DELETE FROM bot_memory_arrivals WHERE user_id=$id");$db->query("DELETE FROM chat_messages WHERE user_id=$id");foreach(['privacy_consent_events','account_privacy']as$t)$db->query("DELETE FROM $t WHERE user_id=$id");$db->query("DELETE FROM users WHERE id=$id");}
 foreach($mids as$id)$db->query('DELETE FROM chat_messages WHERE id='.$id);foreach($keys as$k){$s=$db->prepare('DELETE FROM bot_conversation_summaries WHERE session_key=?');$s->bind_param('s',$k);$s->execute();}
 $db->query("DELETE FROM llm_jobs WHERE type='evening_summary'");foreach($opts as$k=>$v){if($v===null){$s=$db->prepare('DELETE FROM site_options WHERE key_name=?');$s->bind_param('s',$k);$s->execute();}else$cfg->setOption($k,$v);}$cfg->flushCache();$peer->close();
}
echo "$total checks, $failed failures\n";echo$failed?"FAIL\n":"ALL PASS\n";exit((int)($failed>0));
