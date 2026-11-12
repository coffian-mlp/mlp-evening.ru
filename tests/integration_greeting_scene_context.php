<?php
/** MLP-460: native greeting and drawing paths, synthetic database only. */
require_once __DIR__.'/integration_helpers.php';
$c=it_require_db();$c->close();$db=Infra\Database::getInstance()->getConnection();$cfg=Infra\ConfigManager::getInstance();
$saved=[];$users=[];$mids=[];$eventId=null;$summaryKey=null;$memoryId=null;$count=0;$failed=0;
$ok=function($v,$n)use(&$count,&$failed){$count++;$failed+=(int)!$v;echo($v?'[OK] ':'[FAIL] ').$n."\n";};
$set=function($k,$v)use($cfg,&$saved){if(!array_key_exists($k,$saved))$saved[$k]=$cfg->getOptionDetails($k)['value']??null;$cfg->setOption($k,(string)$v);};
class Context460Provider implements LLM\LLMProviderInterface {
 public array $requests=[];
 public function askChat(array $c,string $p):?string{$this->requests[]=[$c,$p];return 'Привет, Synthetic! Рада тебя видеть, хорошего дня!';}
}
class Context460Chat extends Domain\ChatManager {
 public array $rows=[];
 public function getMessages($limit=50,$beforeId=null){return array_slice($this->rows,-$limit);}
}
class Context460LLM extends LLM\LLMManager {
 public array $utility=[];
 public function botSay(string $text,array $quotedIds=[]){return false;}
 public function generateUtility(array $context,string $systemPrompt,?int $deadlineSec=null,?int $timeoutSec=null):?string{$this->utility[]=[$context,$systemPrompt];return 'Synthetic ponies fly colourful balloons above the clouds.';}
}
try{
 foreach(['Human','Bot','Other']as$n){$users[]=$u=(new Domain\UserManager())->createUser('it460_'.bin2hex(random_bytes(6)),bin2hex(random_bytes(24)),'user','Synthetic'.$n);Domain\Privacy::setAccountConsent($u,true,false);Domain\Privacy::unlockAccount($u);}
 [$human,$bot,$other]=$users;
 foreach(['ai_enabled'=>1,'ai_primary_provider'=>'routerai','ai_routerai_key'=>'synthetic','ai_bot_user_id'=>$bot,'ai_memory_enabled'=>0,'ai_evening_summary_enabled'=>0,'ai_return_memory_enabled'=>0,'ai_online_in_context'=>0,'ai_reactions'=>0,'ai_debug_log'=>0,'ai_greeting_cooldown'=>0,'ai_image_llm_caption'=>0,'ai_image_daily_limit'=>100,'bot_last_manual_draw'=>0]as$k=>$v)$set($k,$v);
 $llm=new Context460LLM();$chat=new Context460Chat();$provider=new Context460Provider();
 foreach(['providers'=>[$provider],'fastProviders'=>[$provider],'chatManager'=>$chat]as$k=>$v)(new ReflectionProperty(LLM\LLMManager::class,$k))->setValue($llm,$v);
 $now=time();$row=fn($uid,$age,$text,$id)=>['id'=>$id,'user_id'=>$uid,'username'=>'Synthetic','raw_message'=>$text,'created_at'=>gmdate('Y-m-d H:i:s',$now-$age),'is_deleted'=>0];
 $chat->rows=[$row($human,36000,'OLD_TOPIC_SENTINEL',10),$row($bot,60,'OLD_BOT_SENTINEL',11)];
 $ordinaryBefore=$llm->buildReplyContext(24);
 $llm->processTrigger('greeting',['user_id'=>$human,'username'=>'Synthetic']);$request=end($provider->requests);$encoded=json_encode($request[0],JSON_UNESCAPED_UNICODE);
 $ok(!str_contains($encoded,'OLD_TOPIC_SENTINEL')&&!str_contains($encoded,'OLD_BOT_SENTINEL'),'native idle greeting drops stale human and recent automatic bot text');
 $ok(str_contains($encoded,'[Время]'),'greeting explicitly uses current system time');
 $ok($llm->buildReplyContext(24)===$ordinaryBefore&&str_contains(json_encode($ordinaryBefore),'OLD_TOPIC_SENTINEL'),'ordinary reply context is unchanged with memory features off');
 $chat->rows[]=$row($human,20,'LIVE_TOPIC_SENTINEL',12);
 $ordinaryLive=$llm->buildReplyContext(24);
 $llm->processTrigger('greeting',['user_id'=>$human,'username'=>'Synthetic']);$request=end($provider->requests);$native=$request[0];array_pop($native);
 $ok($native===$ordinaryLive,'live greeting preserves all previous context byte for byte');
 // Protected return preparation must survive the new greeting history policy.
 $set('ai_memory_enabled',1);$set('ai_return_memory_enabled',1);$set('ai_memory_recall_enabled',0);$set('ai_memory_block_limit',8000);
 $memoryId=(new Domain\BotMemoryManager())->add('dossier',$human,'Любит синий чайник.','manual',$human);
 $db->query("SELECT GET_LOCK('bot_worker',0)");(new Domain\ConversationMemoryStore())->arrival($human,30*86400);
 $chat->rows=[$row($human,36000,'OLD_TOPIC_SENTINEL',10)];
 try{$llm->processTrigger('greeting',['user_id'=>$human,'username'=>'Synthetic']);}finally{$db->query("SELECT RELEASE_LOCK('bot_worker')");}
 $request=end($provider->requests);$ok(count($request[0])===2&&str_starts_with($request[0][0]['content'],LLM\ConversationMemoryPolicy::RETURN_MARKER),'return greeting keeps its protected single anchor and final instruction');
 $ok(str_contains($request[0][0]['content'],'синий чайник'),'return greeting retains a selected consented anchor');
 $ok(str_contains(end($request[0])['content'],'60–200')&&!str_contains(json_encode($request[0]),'OLD_TOPIC_SENTINEL'),'return instruction and stale history isolation both survive');
 // An ongoing event can preserve a summary frame after 90 minutes of silence.
 $set('ai_evening_summary_enabled',1);$set('ai_return_memory_enabled',0);
 $db->query("INSERT INTO events(title,start_time,duration_minutes)VALUES('Synthetic long stream',UTC_TIMESTAMP()-INTERVAL 125 MINUTE,240)");$eventId=(int)$db->insert_id;
 $db->query("INSERT INTO chat_messages(user_id,username,message,created_at)VALUES($human,'Synthetic','Синтетическая прошлая тема.',UTC_TIMESTAMP()-INTERVAL 120 MINUTE)");$mids[]=$cursor=(int)$db->insert_id;
 $store=new Domain\ConversationMemoryStore();$frame=$store->frame($bot);$summaryKey=$frame['key'];$store->create($frame);
 $hash=(new LLM\MemorySourceReader())->conversation($frame['rows'],$frame['first_id'],$cursor,$bot)['hash'];
 $stmt=$db->prepare("UPDATE bot_conversation_summaries SET summary_text='Синтетический старый конспект.',source_hash=?,cursor_id=? WHERE session_key=?");$stmt->bind_param('sis',$hash,$cursor,$summaryKey);$stmt->execute();
 (new ReflectionProperty(LLM\LLMManager::class,'chatManager'))->setValue($llm,new Domain\ChatManager());
 $db->query("SELECT GET_LOCK('bot_worker',0)");
 try{
  $ordinarySummary=$llm->conversationContext($llm->buildReplyContext(24),[$human]);
  $ok(str_contains(json_encode($ordinarySummary,JSON_UNESCAPED_UNICODE),'Синтетический старый конспект.'),'ordinary replies retain the valid event-extended summary');
  $llm->processTrigger('greeting',['user_id'=>$human,'username'=>'Synthetic']);$request=end($provider->requests);
  $ok(!str_contains(json_encode($request[0],JSON_UNESCAPED_UNICODE),'Синтетический старый конспект.'),'idle greeting excludes an event-extended summary of the past conversation');
  $set('ai_return_memory_enabled',1);$store->arrival($human,30*86400);$llm->processTrigger('greeting',['user_id'=>$human,'username'=>'Synthetic']);$request=end($provider->requests);
  $ok(str_starts_with($request[0][0]['content'],LLM\ConversationMemoryPolicy::RETURN_MARKER)&&str_contains($request[0][0]['content'],'синий чайник')&&!str_contains(json_encode($request[0],JSON_UNESCAPED_UNICODE),'Синтетический старый конспект.'),'composite summary+return preserves the selected return anchor after idle');
 }finally{$db->query("SELECT RELEASE_LOCK('bot_worker')");}
 (new ReflectionProperty(LLM\LLMManager::class,'chatManager'))->setValue($llm,$chat);
 $set('ai_evening_summary_enabled',0);
 $set('ai_memory_enabled',0);$set('ai_return_memory_enabled',0);
 $insert=function($uid,$text,$deleted=0)use($db,&$mids){$s=$db->prepare("INSERT INTO chat_messages(user_id,username,message,is_deleted)VALUES(?,'Synthetic',?,?)");$s->bind_param('isi',$uid,$text,$deleted);$s->execute();return$mids[]=(int)$db->insert_id;};
 for($i=1;$i<=7;$i++)$insert($bot,'SCENE_'.$i.' ![рисунок](/upload/lyra/synthetic_'.$i.'.jpg)');
 $insert($other,'HUMAN_IMAGE ![рисунок](/upload/lyra/synthetic_other.jpg)');$insert($bot,'DELETED_IMAGE ![рисунок](/upload/lyra/synthetic_deleted.jpg)',1);$insert($bot,'ORDINARY_BOT');
 if(!method_exists(Domain\ChatManager::class,'recentBotDrawings'))$ok(false,'recent drawing source is missing');
 else{
  $recent=(new Domain\ChatManager())->recentBotDrawings($bot);
  $ok(count($recent)===5&&str_contains($recent[0]['message'],'SCENE_7')&&str_contains($recent[4]['message'],'SCENE_3'),'drawing history selects last five own visible drawings in descending order');
  $ok(!str_contains(json_encode($recent),'HUMAN_IMAGE')&&!str_contains(json_encode($recent),'DELETED_IMAGE')&&!str_contains(json_encode($recent),'ORDINARY_BOT'),'human images, tombstones and normal bot replies are excluded');
 }
 $chat->rows=[$row($human,20,'Гонка воздушных шаров',20),$row($bot,10,'Старая комната ![рисунок](/upload/lyra/old.jpg)',21)];
 $artist=new LLM\LyraArtist($llm);$generator=fn()=>null;
 // Exercise both actual director entry points without image calls or publishing.
 foreach([false,true]as$auto){
  $artist->handleDrawChat(['handler_type'=>'image_chat'],['auto'=>$auto,'username'=>'Synthetic'],null,$generator);
  $task=end($llm->utility);$wire=json_encode($task,JSON_UNESCAPED_UNICODE);
  $ok(is_array($task)&&str_contains($wire,'SCENE_7')&&!str_contains($wire,'Старая комната')&&str_contains($wire,'Гонка воздушных шаров'),($auto?'automatic':'manual').' native director gets separate history and clean topic');
 }
}catch(Throwable $e){$ok(false,'unexpected '.get_class($e).': '.$e->getMessage());}
finally{
 if($eventId!==null)$db->query("DELETE FROM events WHERE id=$eventId");
 if($summaryKey!==null){$stmt=$db->prepare('DELETE FROM bot_conversation_summaries WHERE session_key=?');$stmt->bind_param('s',$summaryKey);$stmt->execute();}
 if($memoryId!==null)$db->query("DELETE FROM bot_memory WHERE id=$memoryId");
 $db->query("SELECT RELEASE_LOCK('bot_worker')");foreach($users as$u){$db->query("DELETE FROM bot_memory_arrivals WHERE user_id=$u");$db->query("DELETE FROM chat_messages WHERE user_id=$u");$db->query("DELETE FROM llm_jobs WHERE JSON_EXTRACT(payload,'$.user_id')=$u");$db->query("DELETE FROM privacy_consent_events WHERE user_id=$u");$db->query("DELETE FROM account_privacy WHERE user_id=$u");$db->query("DELETE FROM users WHERE id=$u");}
 foreach($saved as$k=>$v){if($v===null){$s=$db->prepare('DELETE FROM site_options WHERE key_name=?');$s->bind_param('s',$k);$s->execute();}else$cfg->setOption($k,$v);}$cfg->flushCache();
}
echo $failed?"FAIL: $failed/$count\n":"ALL PASS ($count)\n";exit($failed?1:0);
