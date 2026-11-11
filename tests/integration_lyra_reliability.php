<?php
/** MLP-459: recall degradation and automatic drawing admission, without external calls. */
require_once __DIR__.'/integration_helpers.php';
$connection=it_require_db();$connection->close();$db=Infra\Database::getInstance()->getConnection();
$cfg=Infra\ConfigManager::getInstance();$saved=[];$users=[];$messages=[];$jobs=[];$total=0;$fail=0;
$group=$argv[1]??'all';$run=fn($name)=>$group==='all'||$group===$name;
$ok=function($value,$label)use(&$total,&$fail){$total++;if(!$value)$fail++;echo($value?'[OK] ':'[FAIL] ').$label."\n";};
$set=function($key,$value)use($cfg,&$saved){if(!array_key_exists($key,$saved))$saved[$key]=$cfg->getOptionDetails($key)['value']??null;$cfg->setOption($key,(string)$value);};
$add=function($uid,$text,$deleted=0)use($db,&$messages){$s=$db->prepare("INSERT INTO chat_messages(user_id,username,message,is_deleted) VALUES(?,'Synthetic',?,?)");$s->bind_param('isi',$uid,$text,$deleted);$s->execute();return$messages[]=(int)$db->insert_id;};
$initialJobId=(int)$db->query('SELECT COALESCE(MAX(id),0) FROM llm_jobs')->fetch_row()[0];
$queue=new LLM\JobQueue();$job=function($type,$payload=[],$delay=3600)use($queue,&$jobs){return$jobs[]=$queue->enqueue($type,$payload,$delay);};
try{
 foreach(['Owner','Bot']as$i=>$nick)$users[]=(new Domain\UserManager())->createUser('it459_'.bin2hex(random_bytes(6)),bin2hex(random_bytes(20)),'user',$nick);
 [$owner,$bot]=$users;
 foreach(['ai_enabled'=>1,'ai_bot_user_id'=>$bot,'ai_owner_user_id'=>$owner,'ai_memory_enabled'=>1,'ai_memory_recall_enabled'=>1,'ai_memory_recall_expand'=>0,'ai_memory_block_limit'=>8000,'ai_image_auto_interval'=>900,'bot_last_manual_draw'=>0,'bot_last_autodraw'=>0,'ai_debug_log'=>0]as$k=>$v)$set($k,$v);
 $ctx=[['role'=>'user','content'=>'Синтетический вопрос']];$store=new Domain\MemoryEpisodeManager();$reader=new LLM\MemorySourceReader();$profile=hash('sha256','synthetic459');$vector=array_fill(0,512,0.0);$vector[0]=1;
 $make=fn($query)=>new LLM\MemoryRetriever($store,$reader,$profile,$bot,$query);
 if($run('budget')){
  $remaining=0;$sqlMs=0;$retriever=$make(function($q,$deadline)use(&$remaining,&$sqlMs,$db,$vector){$remaining=$deadline-microtime(true);$sqlMs=(int)$db->query('SELECT @@SESSION.max_execution_time')->fetch_row()[0];usleep(1050000);return['vector'=>$vector];});
  $before=$db->query('SELECT @@SESSION.max_execution_time')->fetch_row()[0];$out=$retriever->recall($ctx,'синтетическая тема',8000);
  $ok($remaining>1.1&&$remaining<=1.21,'query embedding receives up to 1.2 seconds');
  $ok($sqlMs>2300&&$sqlMs<=2500,'SQL receives the 2.5 second total budget');
  $ok($out['metadata']['reason']==='no_current_sources','embedding slower than one second still completes recall');
  $ok($db->query('SELECT @@SESSION.max_execution_time')->fetch_row()[0]===$before,'session SQL limit is restored');
 }
 if($run('logging')){
  $path=tempnam(sys_get_temp_dir(),'it459_');$previous=ini_set('error_log',$path);
  try{
   foreach([new RuntimeException('PRIVATE_SENTINEL'),new mysqli_sql_exception('PRIVATE_SENTINEL',3024)]as$error){
    file_put_contents($path,'');$out=$make(fn()=>throw $error)->recall($ctx,'PRIVATE_QUERY_SENTINEL',8000);
    $log=file_get_contents($path);$ok(substr_count($log,'MemoryRetriever:')===1,'one application log entry per failed search');
    $ok(!str_contains($log,'PRIVATE_')&&str_contains($log,'stages_ms')&&str_contains($log,get_class($error)),'failure log has class/timing without query or exception text');
    $ok($out['metadata']['reason']===($error instanceof mysqli_sql_exception?'deadline':'search_error'),'SQL execution timeout is classified independently of elapsed embedding time');
   }
   file_put_contents($path,'');$out=$make(function()use($db,$vector){$db->query('SET SESSION max_execution_time=20');$db->query('SELECT /*+ MAX_EXECUTION_TIME(20) */ SLEEP(0.02) FROM information_schema.columns LIMIT 100');return['vector'=>$vector];})->recall($ctx,'синтетическая тема',8000);
   $ok($out['metadata']['reason']==='deadline'&&($out['metadata']['error_class']??'')==='mysqli_sql_exception','real MySQL max_execution_time interruption is a deadline');
  }finally{ini_set('error_log',$previous);unlink($path);}
 }
 if($run('guidance')){
  $llm=new LLM\LLMManager();$calls=0;$empty=$make(function()use(&$calls,$vector){$calls++;return['vector'=>$vector];});(new ReflectionProperty($llm,'memoryRetriever'))->setValue($llm,$empty);
  $out=$llm->recallOrdinaryContext($ctx,'синтетическая тема',[$owner]);
  $ok(count($out)===count($ctx)+1&&end($out)['role']==='system'&&str_starts_with(end($out)['content'],'Поиск исторической памяти'),'ordinary owner reply receives missing recall guidance');
  foreach(['deadline','search_error','no_current_sources','memory_budget']as$reason)$ok(method_exists(LLM\MemoryRecallBlock::class,'missing')&&count(LLM\MemoryRecallBlock::missing($ctx,$reason))===2,'missing guidance for '.$reason);
  foreach(['ok','owner_unavailable','no_query_topic']as$reason)$ok(method_exists(LLM\MemoryRecallBlock::class,'missing')&&LLM\MemoryRecallBlock::missing($ctx,$reason)===$ctx,'no guidance for '.$reason);
  $set('ai_memory_recall_enabled',0);$before=$calls;$n=(int)$db->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_row()[1];$out=$llm->recallOrdinaryContext($ctx,'синтетическая тема',[$owner]);$sql=(int)$db->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_row()[1]-$n-1;
  $ok($out===$ctx&&$calls===$before&&$sql===0,'recall disabled is byte-identical with zero SQL/provider calls');
 }
 if($run('priority')||$run('spacing')){
  $set('bot_last_manual_draw',0);$set('bot_last_autodraw',0);$add($owner,'Текущая беседа');
  $worker=new LLM\BotWorker();$schedule=new ReflectionMethod($worker,'autoDrawSchedule');
  if($run('priority')){
   foreach(['mention','greeting']as$type){
    $set('bot_last_autodraw',0);
    $id=$job($type,['user_id'=>$owner,'username'=>'Owner','message'=>'Synthetic']);
    // Old delayed work must also block automatic drawing; no implicit age cutoff.
    $db->query("UPDATE llm_jobs SET created_at=UTC_TIMESTAMP()-INTERVAL 1 HOUR WHERE id=$id");
    $ok(LLM\DrawingSchedule::automaticBlocked(),$type.' with future run_after blocks admission');
    $started=false;LLM\DrawingSchedule::run(true,function()use(&$started){$started=true;return true;});$ok(!$started,$type.' blocks a previously queued automatic drawing before generation');
    $calls=0;(new LLM\LyraArtist(new class extends LLM\LLMManager {public function botSay(string $text,array $quotedIds=[]){return false;}}))->handleDrawChat(['handler_type'=>'image_chat'],['auto'=>true],function()use(&$calls){$calls++;return 'Synthetic scene';},function()use(&$calls){$calls++;return '/upload/lyra/synthetic.jpg';});$ok($calls===0,$type.' blocks the native artist before director and image calls');
    $schedule->invoke($worker);$ok((int)$cfg->getOption('bot_last_autodraw',0)===0,$type.' prevents schedule marker/enqueue');
    $db->query("UPDATE llm_jobs SET status='processing' WHERE id=$id");$ok(LLM\DrawingSchedule::automaticBlocked(),$type.' processing blocks automatic drawing');
    $db->query("UPDATE llm_jobs SET status='done' WHERE id=$id");
   }
   $set('ai_image_auto_interval',0);$n=(int)$db->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_row()[1];$schedule->invoke($worker);$sql=(int)$db->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_row()[1]-$n-1;$ok($sql===0,'auto interval zero preserves zero extra SQL');
  }
  if($run('spacing')){
   $set('bot_last_autodraw',0);$set('ai_image_auto_interval',900);
   $ok(!LLM\DrawingSchedule::automaticBlocked(),'no previous bot drawing has no spacing restriction');
   $picture=$add($bot,'![рисунок](/upload/lyra/synthetic459.jpg)');
   $add($owner,'Удалённая реплика',1);$add($owner,'Удалённая картинка ![картинка](https://example.invalid/deleted.jpg)',1);
   $add($owner,'https://example.invalid/photo.png');$add($owner,'![картинка](https://example.invalid/image.jpg)');
   for($i=0;$i<8;$i++)$add($i%2?$owner:$bot,'Сообщение '.$i);
   $add($bot,'Картинка бота ![картинка](https://example.invalid/bot.jpg)');$ok(LLM\DrawingSchedule::automaticBlocked(),'a bot image does not count towards the message threshold');
   $add($owner,'Одиннадцатое сообщение');
   $ok(LLM\DrawingSchedule::automaticBlocked(),'eleven live messages including participant images remain below threshold');
   $schedule->invoke($worker);$ok((int)$cfg->getOption('bot_last_autodraw',0)===0,'insufficient chat spacing prevents enqueue');
   $add($owner,'Подпись участника ![картинка](https://example.invalid/twelfth.jpg)');$ok(!LLM\DrawingSchedule::automaticBlocked(),'a participant image with caption is the twelfth live message and allows automatic drawing');
   $picture2=$add($bot,'Подпись\n![рисунок](/upload/lyra/synthetic459-2.jpg)');$ok(LLM\DrawingSchedule::automaticBlocked(),'a newer manual or automatic bot drawing resets spacing');
   $db->query("UPDATE chat_messages SET is_deleted=1 WHERE id=$picture2");$ok(!LLM\DrawingSchedule::automaticBlocked(),'deleted bot drawing does not reset live spacing');
   $otherPicture=$add($owner,'![рисунок](/upload/lyra/synthetic459-3.jpg)');$ok(!LLM\DrawingSchedule::automaticBlocked(),'a participant image never becomes the last bot drawing');
   $set('bot_last_manual_draw',time());$started=false;LLM\DrawingSchedule::run(false,function()use(&$started){$started=true;return true;});$ok($started,'manual drawing admission retains its behavior');
  }
 }
 if($run('timeout'))$ok(LLM\ImageGenerator::TIMEOUT===150,'image request timeout is 150 seconds');
}finally{
 $db->query("DELETE FROM llm_jobs WHERE id>$initialJobId AND type='dynamic_command' AND JSON_EXTRACT(payload,'$.auto')=true");
 foreach($jobs as$id)$db->query('DELETE FROM llm_jobs WHERE id='.(int)$id);
 foreach($messages as$id)$db->query('DELETE FROM chat_messages WHERE id='.(int)$id);
 foreach($users as$id){foreach(['privacy_consent_events','account_privacy']as$t)$db->query("DELETE FROM $t WHERE user_id=$id");$db->query("DELETE FROM users WHERE id=$id");}
 foreach($saved as$k=>$v){if($v===null){$s=$db->prepare('DELETE FROM site_options WHERE key_name=?');$s->bind_param('s',$k);$s->execute();}else$cfg->setOption($k,$v);}$cfg->flushCache();
}
echo "$total checks, $fail failures\n";echo$fail?"FAIL\n":"ALL PASS\n";exit((int)($fail>0));
