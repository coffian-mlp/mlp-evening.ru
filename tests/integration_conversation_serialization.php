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
class SerializationFakeProvider implements LLM\LLMProviderInterface {
 public int $calls=0;public ?Closure $callback=null;public ?string $reply=null;
 public function withTimeout(int $t):static{return$this;}
 public function askChat(array $c,string $p):?string{$this->calls++;if($this->callback)($this->callback)($this->calls,$c);return $this->reply??'Синтетический ответ номер '.$this->calls.'.';}
}
try{
 $uid=$mk();$bot=$mk();$observer=$mk();foreach(['ai_enabled'=>1,'ai_bot_user_id'=>$bot,'ai_primary_provider'=>'routerai','ai_routerai_key'=>'synthetic_no_network','ai_fast_model'=>'synthetic','ai_memory_enabled'=>1,'ai_memory_recall_enabled'=>0,'ai_evening_summary_enabled'=>1,'ai_return_memory_enabled'=>1,'ai_memory_block_limit'=>8000,'ai_online_in_context'=>0,'ai_reactions'=>0,'ai_memory_auto'=>0,'ai_auto_draw'=>0,'ai_lifelike'=>0,'ai_greeting_cooldown'=>0,'ai_debug_log'=>0]as$k=>$v)$set($k,$v);
 for($i=0;$i<25;$i++){$at=gmdate('Y-m-d H:i:s',time()-1500+$i*10);$s=$db->prepare('INSERT INTO chat_messages(user_id,username,message,created_at) VALUES(?,?,?,?)');$n='Synthetic';$t='Синтетический источник '.$i;$s->bind_param('isss',$uid,$n,$t,$at);$s->execute();$mids[]=(int)$db->insert_id;}
 $llm=new LLM\LLMManager();$fake=new SerializationFakeProvider();foreach(['providers','fastProviders']as$p)(new ReflectionProperty(LLM\LLMManager::class,$p))->setValue($llm,[$fake]);
 $store=new Store();$frame=$store->frame($bot);$keys[]=$frame['key'];$store->create($frame);$cursor=max($mids);$q=new LLM\JobQueue();$worker=new LLM\BotWorker();(new ReflectionProperty($worker,'llm'))->setValue($worker,$llm);
 $run=function($method)use($worker){try{if($method==='conversationMemoryStep')(new Store())->purge();(new ReflectionMethod($worker,$method))->invoke($worker);return null;}catch(Throwable $e){return$e;}};
 $seed=function()use($db,$frame,$cursor,$store,$bot){$h=(new LLM\MemorySourceReader())->conversation($store->frame($bot)['rows'],$frame['first_id'],$cursor,$bot)['hash'];$s=$db->prepare("UPDATE bot_conversation_summaries SET summary_text='Синтетический старый конспект',source_hash=?,cursor_id=?,revision=revision+1,attempts=0,last_attempt=NULL,disabled=0 WHERE session_key=?");$s->bind_param('sis',$h,$cursor,$frame['key']);$s->execute();return$store->record($frame['key']);};
 $status=fn($id)=>$db->query("SELECT status,attempts,run_after>UTC_TIMESTAMP() deferred FROM llm_jobs WHERE id=$id")->fetch_assoc();$count=fn()=>(int)$db->query("SELECT COUNT(*) FROM chat_messages WHERE user_id=$bot")->fetch_row()[0];
 $due=function($id)use($db){$db->query("UPDATE llm_jobs SET run_after=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE id=$id");};

 if(in_array($case,['all','R01'],true)){
  $set('ai_return_memory_enabled',0);$r=$seed();
  $change=function($text)use($db,$mids){return Domain\MemoryEpisodeManager::change('chat',[$mids[0]],function()use($db,$mids,$text){$s=$db->prepare('UPDATE chat_messages SET message=?,conversation_revision=conversation_revision+1 WHERE id=?');$s->bind_param('si',$text,$mids[0]);$s->execute();return true;});};
  $change('Временная прикладная правка');$change('Синтетический источник 0');$ok($store->summary($store->frame($bot),$bot)===null,'R01 application edit and restoration are detected without hooks');
  $ok(!$store->publish($frame,$r,'Недопустимый старый конспект',$cursor,$r['source_hash'],$bot,time()),'R01 stale generation cannot publish after source hook');
  $r=$seed();$state=dirname(__DIR__).'/publication-probe-state.json';file_put_contents($state,json_encode(['key'=>$frame['key'],'revision'=>(int)$r['revision'],'cursor'=>$cursor,'hash'=>$r['source_hash'],'bot'=>$bot]));
  $pipes=[];$child=proc_open([PHP_BINARY,__DIR__.'/helpers/conversation_publication_probe.php',$state],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);$line=trim(fgets($pipes[1]));$written=false;$db->query('SET SESSION innodb_lock_wait_timeout=1');
  if($line==='ready'){$written=$change('Разрешённая правка во время публикации');fwrite($pipes[0],"continue\n");$line=trim(fgets($pipes[1]));}
  $err=stream_get_contents($pipes[2]);foreach($pipes as$p)fclose($p);$exit=proc_close($child);unlink($state);$db->query('SET SESSION innodb_lock_wait_timeout=50');$ok($written&&$exit===0&&$err===''&&$line==='done:1'&&$store->summary($store->frame($bot),$bot)===null,'R01 source writer succeeds during publication; next read rejects stale result');
  $change('Прикладная правка после commit');$ok($store->record($frame['key'])['summary_text']==='','R01 retried source writer clears published summary');$change('Синтетический источник 0');
  $r=$seed();$db->query("UPDATE bot_conversation_summaries SET summary_text='',source_hash='',cursor_id=0 WHERE session_key='{$frame['key']}'");$r=$store->record($frame['key']);$hash=(new LLM\MemorySourceReader())->conversation($store->frame($bot)['rows'],$frame['first_id'],$cursor,$bot)['hash'];$change('Правка во время первой генерации');$change('Синтетический источник 0');$ok(!$store->publish($frame,$r,'Недопустимая первая генерация',$cursor,$hash,$bot,time()),'R01 source hook fences empty in-flight generation');
 }

 if(in_array($case,['all','locks'],true)){
  $db->query("UPDATE chat_messages SET created_at=UTC_TIMESTAMP() WHERE id=$cursor");$r=$seed();$peer->begin_transaction();$s=$peer->prepare('SELECT session_key FROM bot_conversation_summaries WHERE session_key=? FOR UPDATE');$s->bind_param('s',$frame['key']);$s->execute();$s->get_result()->fetch_all();
  $app=Infra\Database::getInstance()->getConnection();$app->query('SET SESSION innodb_lock_wait_timeout=1');
  foreach(['edit'=>fn()=>(new Domain\ChatManager())->editMessage($cursor,$uid,'Изменённый текст'),'delete'=>fn()=>(new Domain\ChatManager())->deleteMessage($cursor,$uid),'consent'=>fn()=>Domain\Privacy::setAccountConsent($uid,false,false),'erase'=>fn()=>Domain\AccountErasure::eraseDue($uid)]as$name=>$work){$start=microtime(true);$error=null;try{$work();}catch(Throwable $e){$error=$e;}$ok($error===null&&microtime(true)-$start<0.8,"locks $name never waits for summary row");}
  $app->query('SET SESSION innodb_lock_wait_timeout=50');$peer->rollback();Domain\Privacy::setAccountConsent($uid,true,false);Domain\Privacy::unlockAccount($uid);$db->query("UPDATE chat_messages SET is_deleted=0 WHERE id=$cursor");
 }
 if(in_array($case,['all','observation'],true)){
  $r=$seed();$peer->query("UPDATE chat_messages SET message='Временная правка' WHERE id={$mids[0]}");$store->summary($store->frame($bot),$bot);$peer->query("UPDATE chat_messages SET message='Синтетический источник 0' WHERE id={$mids[0]}");$ok($store->summary($store->frame($bot),$bot)===null,'observation raw edit observed before restoration clears summary');
  $r=$seed();$peer->query("UPDATE account_privacy SET state='withdrawn' WHERE user_id=$uid");$store->summary($store->frame($bot),$bot);$peer->query("UPDATE account_privacy SET state='active' WHERE user_id=$uid");$ok($store->summary($store->frame($bot),$bot)===null,'observation raw consent observed before restoration clears summary');
  $r=$seed();$peer->query("UPDATE chat_messages SET message='Ненаблюдённая правка' WHERE id={$mids[0]}");$peer->query("UPDATE chat_messages SET message='Синтетический источник 0' WHERE id={$mids[0]}");$ok($store->summary($store->frame($bot),$bot)!==null,'known limit raw A-B-A between locked observations is not detected');
  $r=$seed();$db->query("CREATE TEMPORARY TABLE serial_source_copy AS SELECT * FROM chat_messages WHERE id={$mids[0]}");$db->query("DELETE FROM chat_messages WHERE id={$mids[0]}");$store->summary($store->frame($bot),$bot);$db->query('INSERT INTO chat_messages SELECT * FROM serial_source_copy');$db->query('DROP TEMPORARY TABLE serial_source_copy');$ok($store->summary($store->frame($bot),$bot)===null,'observation delete observed before exact reinsert never revives summary');
 }
 if(in_array($case,['all','R10'],true)){
  $set('ai_evening_summary_enabled',1);$r=$seed();$old=hash('sha256','busy_'.bin2hex(random_bytes(4)));$free=hash('sha256','free_'.bin2hex(random_bytes(4)));$keys[]=$old;$keys[]=$free;
  foreach([$old,$free]as$k){$s=$db->prepare("INSERT INTO bot_conversation_summaries(session_key,first_id,started_at,expires_at,summary_text,source_hash,cursor_id) VALUES(?,1,UTC_TIMESTAMP()-INTERVAL 30 HOUR,UTC_TIMESTAMP()-INTERVAL 20 HOUR,'Старый конспект','synthetic',1)");$s->bind_param('s',$k);$s->execute();}
  $peer->begin_transaction();$s=$peer->prepare('SELECT session_key FROM bot_conversation_summaries WHERE session_key=? FOR UPDATE');$s->bind_param('s',$old);$s->execute();$s->get_result()->fetch_all();
  for($i=0;$i<60;$i++){try{$store->retireUnresolved($frame['key']);}catch(mysqli_sql_exception $e){if($e->getCode()!==3572)throw$e;}}
  $exists=$db->query("SHOW TABLES LIKE 'bot_conversation_invalidations'")->num_rows;$growth=$exists?(int)$db->query('SELECT COUNT(*) FROM bot_conversation_invalidations')->fetch_row()[0]:0;
  $ok($growth===0,'R10 repeated busy invalidation creates no receipt backlog');$ok($store->record($free)['summary_text']==='','R10 busy old session cannot starve a free later session');$peer->rollback();
 }
 if(in_array($case,['all','R11'],true)){
  $set('ai_evening_summary_enabled',0);foreach(['ai_reply_min_gap'=>0,'ai_debounce_window'=>0,'ai_reply_mode'=>'always']as$k=>$v)$set($k,$v);
  foreach([0,1]as$flag){$db->query("DELETE FROM chat_messages WHERE user_id=$bot");$set('ai_return_memory_enabled',$flag);$store->arrival($uid,30*86400);$fake->calls=0;
   $ack=new class extends LLM\JobQueue{public int $target=0;public bool $once=true;public $child=null;public array $pipes=[];public function complete(array $ids):void{if($this->once&&in_array($this->target,$ids,true)){$this->once=false;$code='require "autoload.php";$d=Infra\\Database::getInstance()->getConnection();$d->begin_transaction();$d->query("SELECT id FROM llm_jobs WHERE id='.$this->target.' FOR UPDATE")->fetch_all();echo "locked\\n";flush();usleep(1500000);$d->commit();';$this->child=proc_open([PHP_BINARY,'-r',$code],[1=>['pipe','w'],2=>['pipe','w']],$this->pipes);if(trim(fgets($this->pipes[1]))!=='locked')throw new RuntimeException('Ack barrier missing');$d=Infra\Database::getInstance()->getConnection();$d->query('SET SESSION innodb_lock_wait_timeout=1');try{parent::complete($ids);}finally{$d->query('SET SESSION innodb_lock_wait_timeout=50');}return;}parent::complete($ids);}};
   (new ReflectionProperty($worker,'queue'))->setValue($worker,$ack);$before=$count();$batch=[];foreach([1,2]as$n){$jobs[]=$id=$ack->enqueue('mention',['user_id'=>$uid,'username'=>'Synthetic','message'=>'@Синтетик, вопрос '.$n],-1);$batch[]=$id;}$ack->target=$batch[0];$run('reactive');
   if(is_resource($ack->child)){$err=stream_get_contents($ack->pipes[2]);foreach($ack->pipes as$p)fclose($p);$exit=proc_close($ack->child);if($exit||$err!=='')throw new RuntimeException('Ack child failed');}
   $first=$count();$ok($first===$before+1,"R11 flags$flag first response committed");foreach($batch as$id)$due($id);$run('reactive');
   $ok($count()===$first&&$fake->calls===1,"R11 flags$flag processing job is not reclaimed after ACK failure");$all=true;foreach($batch as$id)$all=$all&&$status($id)['status']==='processing';$ok($all,"R11 flags$flag ACK failure keeps baseline processing state for reaper");
  }
  (new ReflectionProperty($worker,'queue'))->setValue($worker,$q);
  $set('ai_return_memory_enabled',0);$fake->reply='Синтетический одинаковый ответ.';$db->query("DELETE FROM chat_messages WHERE user_id=$bot");$replyIds=[];
  for($i=0;$i<2;$i++){$jobs[]=$id=$q->enqueue('greeting',['user_id'=>$observer,'username'=>'Observer'],-1);$run('reactive');$payload=json_decode($db->query("SELECT payload FROM llm_jobs WHERE id=$id")->fetch_row()[0],true);$replyIds[]=$payload['reply_message_id']??0;}
  $ok($count()===1&&$replyIds===[0,0],'R11 flags0 keeps legacy duplicate suppression without task receipts');$fake->reply=null;
 }
 if(in_array($case,['all','parity'],true)){
  $db->query("UPDATE bot_conversation_summaries SET summary_text='',source_hash='',cursor_id=0,disabled=1");$db->query('DELETE FROM bot_memory_arrivals');$db->query('DELETE FROM llm_jobs');
  foreach(['ai_enabled'=>0,'ai_evening_summary_enabled'=>0,'ai_return_memory_enabled'=>0,'ai_memory_auto'=>0,'ai_image_auto_interval'=>0]as$k=>$v)$set($k,$v);
  $read=function($mode){$pipes=[];$p=proc_open([PHP_BINARY,__DIR__.'/helpers/conversation_tick_probe.php',$mode],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$s=stream_get_contents($pipes[1]);$e=stream_get_contents($pipes[2]);foreach($pipes as$x)fclose($x);if(proc_close($p)||$e!=='')throw new RuntimeException('Tick probe failed');return json_decode(trim($s),true,512,JSON_THROW_ON_ERROR);};
  $baseline=$read('baseline');$current=$read('current');echo json_encode(['tick_baseline'=>$baseline,'tick_current'=>$current]),"\n";$ok($current['max']<=$baseline['min']+1,'parity empty flags0 tick costs at most baseline plus one SQL');
  $set('ai_evening_summary_enabled',1);$set('ai_memory_enabled',0);$jobs[]=$paused=$q->enqueue('evening_summary',[],0);$run('conversationMemoryStep');$ok((int)$db->query("SELECT COUNT(*) FROM llm_jobs WHERE id=$paused")->fetch_row()[0]===0,'parity global disable also consumes queued summary without generation');
 }
}finally{
 $peer->rollback();$third->rollback();Domain\Privacy::unlockAccount($uid??0);
 foreach($jobs as$id)$db->query('DELETE FROM llm_jobs WHERE id='.(int)$id);$db->query("DELETE FROM llm_jobs WHERE type='evening_summary'");
 foreach($users as$id){$db->query("DELETE FROM bot_memory_arrivals WHERE user_id=$id");$db->query("DELETE FROM chat_messages WHERE user_id=$id");foreach(['privacy_consent_events','account_privacy']as$t)$db->query("DELETE FROM $t WHERE user_id=$id");$db->query("DELETE FROM users WHERE id=$id");}
 foreach($keys as$k){$s=$db->prepare('DELETE FROM bot_conversation_summaries WHERE session_key=?');$s->bind_param('s',$k);$s->execute();}
 foreach($opts as$k=>$v){if($v===null){$s=$db->prepare('DELETE FROM site_options WHERE key_name=?');$s->bind_param('s',$k);$s->execute();}else$cfg->setOption($k,$v);}$cfg->flushCache();$peer->close();$third->close();
}
echo "$total checks, $fail failures\n";echo $fail?'FAIL':'ALL PASS';echo"\n";exit((int)($fail>0));
