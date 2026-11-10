<?php
ob_start();
require_once __DIR__.'/integration_helpers.php';
use Infra\ConfigManager;
use Domain\ConversationMemoryStore as Store;
use LLM\ConversationMemory as Memory;
use LLM\ConversationMemoryPolicy as Policy;
$db=it_require_db();$cfg=ConfigManager::getInstance();$tag='it_cm_'.bin2hex(random_bytes(4));$users=[];$messages=[];$keys=[];$jobs=[];$options=[];$episodes=[];
$set=function($k,$v)use($cfg,&$options){if(!array_key_exists($k,$options))$options[$k]=$cfg->getOption($k,null);$cfg->setOption($k,(string)$v);};
$mk=function($suffix)use($db,$tag,&$users){$login=$tag.$suffix;$s=$db->prepare("INSERT INTO users(login,nickname,password_hash,role,created_at) VALUES(?,?,?,'user',UTC_TIMESTAMP())");$pass=password_hash('synthetic_memory_password',PASSWORD_DEFAULT);$s->bind_param('sss',$login,$login,$pass);$s->execute();$id=(int)$db->insert_id;$users[]=$id;Domain\Privacy::setAccountConsent($id,true,false);return $id;};
$ins=function($uid,$text,$at)use($db,$tag,&$messages){$s=$db->prepare('INSERT INTO chat_messages(user_id,username,message,created_at) VALUES(?,?,?,?)');$date=gmdate('Y-m-d H:i:s',$at);$s->bind_param('isss',$uid,$tag,$text,$date);$s->execute();$id=(int)$db->insert_id;$messages[]=$id;return $id;};
$store=new Store();$now=time();
// Low-level context assertions run on the same connection as a publishing worker.
$workerConnection=Infra\Database::getInstance()->getConnection();
if(!(int)$workerConnection->query("SELECT GET_LOCK('bot_worker',0)")->fetch_row()[0])throw new RuntimeException('Test worker slot unavailable');
try{
 $human=$mk('_human');$other=$mk('_other');$bot=$mk('_bot');
 foreach(['ai_enabled'=>1,'ai_bot_user_id'=>$bot,'ai_primary_provider'=>'routerai','ai_routerai_key'=>'synthetic_no_network','ai_fast_model'=>'synthetic_fast','ai_memory_enabled'=>1,'ai_memory_recall_enabled'=>0,'ai_evening_summary_enabled'=>0,'ai_return_memory_enabled'=>0,'ai_return_memory_days'=>14,'ai_memory_block_limit'=>8000,'ai_debug_log'=>1,'ai_online_in_context'=>0,'ai_reactions'=>0]as$k=>$v)$set($k,$v);
 $llm=new class extends LLM\LLMManager {public int $calls=0;public $during=null;public string $answer='Участники обсуждают чай и шутят о потерянном чайнике.';public function generateConversationSummary(string $payload,float $deadline):?string{$this->calls++;if($this->during)($this->during)();return $this->answer;}};
 for($i=0;$i<24;$i++)$ins($human,'Синтетическая беседа о чае '.$i,$now-1500+$i*10);
 $set('ai_evening_summary_enabled',1);check(!Memory::schedule($llm,$now),'24 settled messages do not trigger generation');$set('ai_evening_summary_enabled',0);
 $ins($human,'Синтетическая беседа о чае 24',$now-1260);
 $frame=$store->frame($bot,$now);check($frame!==null,'live weekday conversation resolved');$keys[]=$frame['key'];
 $ctx=[['role'=>'user','content'=>'Текущий вопрос']];
 check($llm->conversationContext($ctx,[$human])===$ctx,'both flags off preserve exact bytes for non-owner');
 check(!Memory::schedule($llm,$now)&&$llm->calls===0,'disabled path cannot schedule or call generator');
 $set('ai_evening_summary_enabled',1);
 check(Memory::schedule($llm,$now),'25 settled human messages schedule background work');
 $q=new LLM\JobQueue();$batch=$q->claimConversationSummary();$jobs=array_merge($jobs,array_column($batch,'id'));check(count($batch)===1,'dedicated claim takes one summary');
 check(!$q->claimDue(50),'reactive claim does not take summary');
 $llm->answer=str_repeat('я',1840).'. '.str_repeat('ю',136).'.';
 check(Memory::process($llm,$batch[0]['data'],$now),'fake generator publishes checked summary');$q->complete($jobs);
 check($llm->calls===1,'one cheap attempt');
 $r=$store->record($frame['key']);check((int)$r['cursor_id']===end($messages),'published cursor covers exactly included sources');
 $out=$llm->conversationContext($ctx,[$other]);check(str_contains(json_encode($out,JSON_UNESCAPED_UNICODE),Policy::SUMMARY_MARKER),'summary available to consented non-owner');
 $blocks=array_values(array_filter($out,static fn($m)=>str_starts_with($m['content']??'',Policy::SUMMARY_MARKER)));check(count($blocks)===1&&mb_strlen($blocks[0]['content'])<=2000&&str_ends_with($blocks[0]['content'],str_repeat('я',1840).'.'),'injected final summary includes header and only complete body sentences');
 check($llm->calls===1,'reply reads summary without generation');
 check($llm->conversationContext($ctx,[$human,0])===$ctx,'guest actor cannot inherit another actor consent');
 $db->query('UPDATE chat_messages SET is_deleted=1 WHERE id='.$messages[0]);check($store->summary($store->frame($bot,$now),$bot)===null,'direct deletion of old covered source invalidates summary');$db->query('UPDATE chat_messages SET is_deleted=0 WHERE id='.$messages[0]);
 check($llm->recallOrdinaryContext($ctx,'чай',[$other])===$ctx,'ordinary phase2 non-owner gate retained');
 $db->query("UPDATE chat_messages SET message='Синтетическая правка без hook' WHERE id=".$messages[0]);
 check($store->summary($store->frame($bot,$now),$bot)===null,'direct edit invalidates cumulative range hash');
 $db->query("UPDATE chat_messages SET message='Синтетическая беседа о чае 0' WHERE id=".$messages[0]);
 check($store->summary($store->frame($bot,$now),$bot)===null,'restoring a source does not revive an invalidated derivative');
 Domain\Privacy::setAccountConsent($other,false,false);
 check($llm->conversationContext($ctx,[$other])===$ctx,'withdrawn actor never receives summary');
 Domain\Privacy::setAccountConsent($other,true,false);
 $late=$ins($other,'Ещё одна синтетическая тема',$now-5);
 Domain\Privacy::setAccountConsent($other,false,false);
 check($store->summary($store->frame($bot,$now),$bot)===null,'withdrawal of unsettled session author rejects whole summary');
 Domain\Privacy::setAccountConsent($other,true,false);
 // Regenerate after invalidation, then race an old source edit with API completion.
 $db->query("UPDATE bot_conversation_summaries SET last_attempt=NULL WHERE session_key='".$frame['key']."'");
 check(Memory::schedule($llm,$now),'invalidated summary schedules reconstruction');$b=$q->claimConversationSummary();$jobs=array_merge($jobs,array_column($b,'id'));
 $llm->during=function()use($db,$messages){$db->query("UPDATE chat_messages SET message='Правка во время генерации' WHERE id=".$messages[0]);};
 check(!Memory::process($llm,$b[0]['data'],$now),'late API result cannot publish after direct edit');$q->complete(array_column($b,'id'));$llm->during=null;
 check($store->record($frame['key'])['summary_text']==='','rejected completion leaves no stale text');
 $db->query("UPDATE bot_conversation_summaries SET attempts=48,last_attempt=NULL WHERE session_key='".$frame['key']."'");$store->invalidate($frame['key']);
 check(!Memory::schedule($llm,$now),'48 attempts permanently disable invalidated session');
 check((int)$store->record($frame['key'])['disabled']===1,'exhausted reconstruction remains unavailable');
 // Reactive pending/processing gate, including unscheduled lifelike jobs.
 $jid=$q->enqueue('mention',['user_id'=>$human,'message'=>'Синтетический вопрос'],500);$jobs[]=$jid;
 check($q->hasConversationReactiveBacklog(),'delayed reactive blocks background');$db->query("UPDATE llm_jobs SET status='processing' WHERE id=$jid");check($q->hasConversationReactiveBacklog(),'claimed reactive blocks background');$q->complete([$jid]);
 // Arrival is captured before last_seen is overwritten, and only once across two touches.
 $set('ai_return_memory_enabled',1);$db->query("UPDATE users SET last_seen=UTC_TIMESTAMP()-INTERVAL 14 DAY WHERE id=$human");
 $store->purge();$touch=(new Domain\OnlineManager())->touchUser($human);check($touch['gap']>=14*86400,'absence captured before touch');
 $token=$db->query("SELECT token FROM bot_memory_arrivals WHERE user_id=$human")->fetch_row()[0];
 $again=(new Domain\OnlineManager())->touchUser($human);check($again['gap']<5,'second tab sees fresh presence');check($token===$db->query("SELECT token FROM bot_memory_arrivals WHERE user_id=$human")->fetch_row()[0],'second tab cannot replace arrival token');
 check($store->reserveReturnGreeting($human)&&!$store->reserveReturnGreeting($human),'return greeting reservation prevents duplicate tabs');
 $claim=$store->claimArrival($human);check($claim===$token&&$store->claimArrival($human)===null,'arrival has exclusive claim');$store->finishArrival($human,$claim,false);check($store->claimArrival($human)===$token,'failed delivery can retry');$messages[]=$store->publishArrival($human,$token,$bot,$tag,'Синтетическая доставка с квитанцией');check($store->claimArrival($human)===null,'successful receipt consumes arrival');
 $db->query("DELETE FROM bot_memory_arrivals WHERE user_id=$other");$db->query("UPDATE users SET last_seen=NULL WHERE id=$other");(new Domain\OnlineManager())->touchUser($other);check($store->claimArrival($other)===null,'first presence is not return');
 $db->query("UPDATE users SET last_seen=UTC_TIMESTAMP()-INTERVAL 13 DAY WHERE id=$other");(new Domain\OnlineManager())->touchUser($other);check($store->claimArrival($other)===null,'absence below threshold not return');
 // Dossier and real indexed participation, using fake vectors only.
 $memory=new Domain\BotMemoryManager();$dossier=$memory->add('dossier',$human,'Синтетический интерес к чайникам','manual',$human);
 $historical=$ins($human,'Синтетическая шутка о чайнике',$now-30*86400);
 $reader=new LLM\MemorySourceReader();$builder=new LLM\MemoryEpisodeBuilder($bot);$index=new Domain\MemoryEpisodeManager();$profile=$llm->conversationRecallProfile();$source=(new Domain\ChatManager())->memorySourceRows([$historical])[0];$ep=$builder->chat([$source],$profile)[0];$record=$index->register($ep,$reader->current(...));$episodes[]=(int)$record['id'];$v=array_fill(0,512,0.0);$v[0]=1;$index->publish($ep,$record,'синтетическая тема',$v,$reader->current(...));
 $store->arrival($human,30*86400);$set('ai_evening_summary_enabled',0);
 $fake=new class implements LLM\LLMProviderInterface {public array $seen=[];public function askChat(array $messagesContext,string $systemPrompt):?string{$this->seen[]=$messagesContext;return 'Рада снова видеть! Помню шутку про чайник.';}};
 foreach(['providers','fastProviders']as$p)(new ReflectionProperty(LLM\LLMManager::class,$p))->setValue($llm,[$fake]);
 // Freeze the indexing profile after replacing the actual network adapter with a fake response provider.
 $profileLLM=new class($profile) extends LLM\LLMManager {public function __construct(private string $profile){parent::__construct();}public function conversationRecallProfile():string{return $this->profile;}};
 foreach(['providers','fastProviders']as$p)(new ReflectionProperty(LLM\LLMManager::class,$p))->setValue($profileLLM,[$fake]);
 $out=$profileLLM->conversationContext($ctx,[$human]);$encoded=json_encode($out,JSON_UNESCAPED_UNICODE);
 check(str_contains($encoded,'Синтетическая шутка о чайнике')&&!str_contains($encoded,'Синтетический интерес'),'returned non-owner receives one own episode joke instead of the full dossier');
 $messages[]=$profileLLM->botSay('Синтетическая опубликованная реплика возвращения');$profileLLM->finishConversationMemory(true);
 check($profileLLM->conversationContext($ctx,[$human])===$ctx,'second answer has no duplicate return block');
 $store->arrival($human,30*86400);check($profileLLM->conversationContext($ctx,[$human,$other])===$ctx,'mixed burst cannot consume an arbitrary target');
 $logsBefore=(int)$db->query('SELECT COALESCE(MAX(id),0) FROM llm_debug_log')->fetch_row()[0];
 $out=$profileLLM->conversationContext($ctx,[$human]);$profileLLM->generateReply($out);$profileLLM->finishConversationMemory(true);
 $logged=$db->query("SELECT request,response FROM llm_debug_log WHERE id>$logsBefore ORDER BY id DESC LIMIT 1")->fetch_assoc();
 check($logged&&!str_contains(json_encode($logged,JSON_UNESCAPED_UNICODE),'Синтетический интерес'),'new source and reply text omitted from debug');
 $flagCtx=$profileLLM->buildReplyContext(24);$set('ai_return_memory_enabled',0);$set('ai_evening_summary_enabled',0);
 check($profileLLM->conversationContext($flagCtx,[$human])===$flagCtx,'disabled actual built context byte parity');
 $nested=['outer'=>json_encode(['messages'=>$out],JSON_UNESCAPED_UNICODE)];$found=false;$redacted=LLM\LlmDebugLog::redactRecall($nested,$found);
 check($found&&!str_contains(json_encode($redacted,JSON_UNESCAPED_UNICODE),'Синтетический интерес'),'recursive serialized debug boundary');
 foreach(['漢字','слово random']as$bad){check(Policy::clean($bad)===null,'foreign script rejected without transcript exceptions');}
 check($llm->calls===2,'no external model or embedding call in context tests');
 $db->query('DELETE FROM bot_memory WHERE id='.(int)$dossier);
}finally{
 $workerConnection->query("SELECT RELEASE_LOCK('bot_worker')");
 foreach($jobs as$id)$db->query('DELETE FROM llm_jobs WHERE id='.(int)$id);
 foreach($keys as$k){$s=$db->prepare('DELETE FROM bot_conversation_summaries WHERE session_key=?');$s->bind_param('s',$k);$s->execute();}
 foreach($episodes as$id)$db->query('DELETE FROM memory_episodes WHERE id='.(int)$id);
 foreach($messages as$id)$db->query('DELETE FROM chat_messages WHERE id='.(int)$id);
 foreach($users as$id){$db->query("DELETE FROM bot_memory_arrivals WHERE user_id=$id");$db->query("DELETE FROM bot_memory WHERE user_id=$id OR created_by=$id");$db->query("DELETE FROM privacy_consent_events WHERE user_id=$id");$db->query("DELETE FROM account_privacy WHERE user_id=$id");$db->query("DELETE FROM users WHERE id=$id");}
 foreach($options as$k=>$v){if($v===null){$s=$db->prepare('DELETE FROM site_options WHERE key_name=?');$s->bind_param('s',$k);$s->execute();}else $cfg->setOption($k,$v);}$cfg->flushCache();
}
it_done();
