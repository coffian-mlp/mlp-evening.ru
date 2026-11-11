<?php
ob_start();
require_once __DIR__.'/integration_helpers.php';
$db=it_require_db();require_once dirname(__DIR__).'/migrate.php';migrate_database($db,dirname(__DIR__).'/migrations');
use LLM\MemoryRetriever;use LLM\MemoryEpisodeBuilder;use Domain\MemoryEpisodeManager;
$cfg=Infra\ConfigManager::getInstance();$backup=[];$users=[];$sources=[];$episodes=[];$memory=[];$marker='it_recall_'.bin2hex(random_bytes(5));
foreach(['ai_memory_recall_enabled','ai_memory_recall_expand','ai_memory_enabled','ai_owner_user_id','ai_bot_user_id','ai_memory_block_limit','ai_reply_min_gap','ai_enabled','ai_online_in_context','ai_debug_log']as$key){$r=$cfg->getOptionDetails($key);$backup[$key]=$r['value']??null;}
try{
 foreach(['Ли','Other','RecallBot']as$i=>$nick)$users[]=(new Domain\UserManager())->createUser($marker.'_'.$i,'Aa!'.bin2hex(random_bytes(12)),'user',$nick);
 [$owner,$other,$bot]=$users;
 foreach(['ai_memory_recall_enabled'=>1,'ai_memory_recall_expand'=>0,'ai_memory_enabled'=>1,'ai_owner_user_id'=>$owner,'ai_bot_user_id'=>$bot,'ai_memory_block_limit'=>8000,'ai_reply_min_gap'=>0,'ai_enabled'=>1,'ai_online_in_context'=>0,'ai_debug_log'=>1]as$k=>$v)$cfg->setOption($k,$v);
 $store=new MemoryEpisodeManager();$reader=new LLM\MemorySourceReader();$builder=new MemoryEpisodeBuilder($bot);$profile=hash('sha256',$marker);$vectors=[];
 foreach([['Вспоминали дело DL-6 и возражение адвоката','Феникс Phoenix Wright Ace Attorney DL-6',0,'2026-11-07 00:00:00'],['Обсуждали XX легион и двойника Омегона','Альфарий Alpharius Warhammer 40000',1,'2025-03-04 18:00:00']]as[$text,$tags,$dimension,$date]){
  $stmt=$db->prepare('INSERT INTO chat_messages(user_id,username,message,created_at) VALUES(?,?,?,?)');$nick='Ли';$stmt->bind_param('isss',$owner,$nick,$text,$date);$stmt->execute();$id=(int)$db->insert_id;$sources[]=$id;
  $row=(new Domain\ChatManager())->memorySourceRows([$id])[0];$episode=$builder->chat([$row],$profile)[0];$record=$store->register($episode,$reader->current(...));$v=array_fill(0,512,0.0);$v[$dimension]=1;$store->publish($episode,$record,$tags,$v,$reader->current(...));$episodes[]=(int)$record['id'];$vectors[$dimension]=$v;
 }
 $calls=0;$query=function($text,$deadline,$expand)use(&$calls,$vectors){$calls++;return['vector'=>$vectors[str_contains(mb_strtolower($text),'альфарий')?1:0],'tags'=>''];};
 $retriever=new MemoryRetriever($store,$reader,$profile,$bot,$query);$ctx=[['role'=>'user','content'=>'Текущий вопрос']];
 $out=$retriever->recall($ctx,'а Феникс?',8000);$focus=end($out['context'])['content'];
 check(str_contains($focus,'chat:'.$sources[0])&&str_contains($focus,'DL-6'),'Феникс retrieves known DL-6 source into focus on fake provider');
 $out=$retriever->recall($ctx,'ты альфарий',8000);check(str_contains(end($out['context'])['content'],'chat:'.$sources[1]),'альфарий retrieves known XX legion source');
 $orthogonal=array_fill(0,512,0.0);$orthogonal[10]=1;$exact=new MemoryRetriever($store,$reader,$profile,$bot,fn()=>['vector'=>$orthogonal,'tags'=>'']);
 $out=$exact->recall($ctx,'@Ли',8000);check(str_contains(json_encode($out['context']),'chat:'.$sources[0]),'short nickname resolves through exact identity channel without vector match');
 $out=$exact->recall($ctx,'2025-03-04',8000);check(str_contains(json_encode($out['context']),'chat:'.$sources[1]),'explicit date finds historical source');
 // A source mutation without invalidation must still be rejected by the hydration signature/hash.
 $db->query("UPDATE chat_messages SET message='Changed without invalidation' WHERE id=".$sources[0]);
 $out=$retriever->recall($ctx,'Феникс',8000);check(!str_contains(json_encode($out['metadata']['sources']),'chat:'.$sources[0]),'cached vector cannot return edited source with stale hash');
 $db->query("UPDATE chat_messages SET message='Вспоминали дело DL-6 и возражение адвоката' WHERE id=".$sources[0]);
 check((new Domain\ChatManager())->deleteMessage($sources[0],$owner,'user'),'source deletion succeeds');
 $out=$retriever->recall($ctx,'Феникс',8000);check(!str_contains(json_encode($out['metadata']['sources']),'chat:'.$sources[0]),'cached vector cannot return deleted source');
 Domain\Privacy::setAccountConsent($owner,false,false);
 $out=$retriever->recall($ctx,'альфарий',8000);check($out['context']===$ctx,'withdrawal erases recall even after matrix cache load');
 Domain\Privacy::setAccountConsent($owner,true,false);
 // Re-register the unchanged second episode after consent restore.
 $row=(new Domain\ChatManager())->memorySourceRows([$sources[1]])[0];$episode=$builder->chat([$row],$profile)[0];$record=$store->register($episode,$reader->current(...));$store->publish($episode,$record,'Альфарий Alpharius Warhammer',$vectors[1],$reader->current(...));$episodes[]=(int)$record['id'];
 // A rebuilt source must not inherit a cached vector for a previous revision.
 $staleVector=new MemoryRetriever($store,$reader,$profile,$bot,fn()=>['vector'=>$vectors[1],'tags'=>'']);
 $staleVector->recall($ctx,'semantic only',8000);
 $db->query("UPDATE chat_messages SET message='совершенно другая тема' WHERE id=".$sources[1]);
 $row=(new Domain\ChatManager())->memorySourceRows([$sources[1]])[0];$newEpisode=$builder->chat([$row],$profile)[0];$newRecord=$store->register($newEpisode,$reader->current(...));$newVector=array_fill(0,512,0.0);$newVector[10]=1;$store->publish($newEpisode,$newRecord,'другая тема',$newVector,$reader->current(...));
 check($staleVector->recall($ctx,'unmatchedneedle',8000)['context']===$ctx,'reindexed source cannot inherit cached cosine from previous revision');
 $db->query("UPDATE chat_messages SET message='Обсуждали XX легион и двойника Омегона' WHERE id=".$sources[1]);
 $row=(new Domain\ChatManager())->memorySourceRows([$sources[1]])[0];$episode=$builder->chat([$row],$profile)[0];$record=$store->register($episode,$reader->current(...));$store->publish($episode,$record,'Альфарий Alpharius Warhammer',$vectors[1],$reader->current(...));
 $llm=new LLM\LLMManager();(new ReflectionProperty($llm,'memoryRetriever'))->setValue($llm,new MemoryRetriever($store,$reader,$profile,$bot,$query));
 $cfg->setOption('ai_memory_recall_enabled',0);$before=$calls;check($llm->recallOrdinaryContext($ctx,'альфарий',[$owner])===$ctx&&$calls===$before,'off is byte-identical and makes no provider calls');
 $cfg->setOption('ai_memory_recall_enabled',1);
 foreach([[$other],[$owner,$other],[0],[]]as$actors)check($llm->recallOrdinaryContext($ctx,'альфарий',$actors)===$ctx&&$calls===$before,'non-owner or mixed burst stays legacy');
 $cfg->setOption('ai_memory_enabled',0);check($llm->recallOrdinaryContext($ctx,'альфарий',[$owner])===$ctx&&$calls===$before,'global memory switch prevents recall');$cfg->setOption('ai_memory_enabled',1);
 $out=$llm->recallOrdinaryContext($ctx,'альфарий',[$owner]);check(str_starts_with(end($out)['content'],LLM\MemoryRecallBlock::FOCUS),'verified owner receives focus');
 $debug=new ReflectionMethod($llm,'debugReplyRequest');$logged=$debug->invoke($llm,'system',array_merge($out,[['role'=>'user','content'=>'Reply instruction']]));
 check(isset($logged['memory_recall']['sources'])&&!str_contains(json_encode($logged['messages'],JSON_UNESCAPED_UNICODE),'Омегона'),'debug keeps numeric metadata and omits new source text even with reply instruction');
 $timeout=new MemoryRetriever($store,$reader,$profile,$bot,function($q,$deadline){usleep(max(0,(int)(($deadline-microtime(true))*1e6)));throw new RuntimeException('fake timeout');});
 $start=microtime(true);$out=$timeout->recall($ctx,'query',8000);check($out['context']===$ctx&&microtime(true)-$start<2.5,'timeout returns legacy within 2.5 seconds');check($out['metadata']['reason']==='search_error'||$out['metadata']['reason']==='deadline','degradation reason is metadata');
 $it=it_config()['db'];$peer=new mysqli($it['host'],$it['user'],$it['pass'],$it['name']);
 try{
  $peer->query('LOCK TABLES chat_messages WRITE');
  $locked=new MemoryRetriever($store,$reader,$profile,$bot,function()use($vectors){usleep(200000);return ['vector'=>$vectors[1],'tags'=>''];});
  $start=microtime(true);$lockedResult=$locked->recall($ctx,'unmatchedneedle',8000);$elapsed=microtime(true)-$start;
  check($lockedResult['context']===$ctx&&$elapsed<2.5,'real SQL lock degrades inside 2.5-second budget');echo 'RECALL_SQL_LOCK_SECONDS='.$elapsed."\n";
 }finally{$peer->query('UNLOCK TABLES');$peer->close();}
 $error=new MemoryRetriever($store,$reader,$profile,$bot,fn()=>throw new RuntimeException('fake failure'));check($error->recall($ctx,'query',8000)['context']===$ctx,'provider failure does not break reply');
 // Actual ordinary entry points capture the generated context, with no external model.
 $fake=new class implements LLM\LLMProviderInterface{public array $context=[];public function askChat(array $messagesContext,string $systemPrompt):?string{$this->context=$messagesContext;return 'Помню тот разговор об Омегоне.';}};
 foreach(['providers','fastProviders']as$property)(new ReflectionProperty($llm,$property))->setValue($llm,[$fake]);
 $inline=new class extends LLM\LLMManager{public array $sent=[];public function botSay(string $text,array $quotes=[]){$this->sent[]=$text;return true;}};
 foreach(['providers','fastProviders']as$property)(new ReflectionProperty(LLM\LLMManager::class,$property))->setValue($inline,[$fake]);
 (new ReflectionProperty(LLM\LLMManager::class,'memoryRetriever'))->setValue($inline,new MemoryRetriever($store,$reader,$profile,$bot,$query));
 $message='@'.$marker.'_2 ты альфарий';$inline->processTrigger('mention',['user_id'=>$owner,'username'=>'Ли','message'=>$message]);
 check(str_contains(json_encode($fake->context,JSON_UNESCAPED_UNICODE),LLM\MemoryRecallBlock::FOCUS),'inline ordinary reply invokes recall');
 $worker=new LLM\BotWorker();(new ReflectionProperty($worker,'llm'))->setValue($worker,$llm);
 $max=(int)$db->query('SELECT COALESCE(MAX(id),0) FROM chat_messages')->fetch_row()[0];
 (new ReflectionMethod($worker,'handleMentions'))->invoke($worker,[['id'=>0,'user_id'=>$owner,'username'=>'Ли','message'=>'ты альфарий','message_id'=>$sources[1]]]);
 check(str_contains(json_encode($fake->context,JSON_UNESCAPED_UNICODE),LLM\MemoryRecallBlock::FOCUS),'queued ordinary reply invokes recall');
 $sourceCount=count($fake->context);check(str_starts_with($fake->context[$sourceCount-2]['content'],LLM\MemoryRecallBlock::FOCUS),'queue focus immediately precedes response instruction');
 $rows=$db->query("SELECT id FROM chat_messages WHERE id>$max AND user_id=$bot")->fetch_all(MYSQLI_ASSOC);$sources=array_merge($sources,array_map('intval',array_column($rows,'id')));
 $failing=new class implements LLM\LLMProviderInterface{public function askChat(array $messagesContext,string $systemPrompt):?string{throw new RuntimeException('synthetic reply failure');}};
 (new ReflectionProperty($llm,'providers'))->setValue($llm,[$failing]);
 $usageModel='usage_'.$marker;$usage=LLM\UsageMeter::measurement('routerai',$usageModel,['usage'=>['prompt_tokens'=>3,'completion_tokens'=>0]],200,false,5,'memory_recall');LLM\UsageMeter::defer($usage);
 check((int)$db->query("SELECT COUNT(*) FROM llm_usage WHERE model='$usageModel'")->fetch_row()[0]===0,'query usage has no DB write before main reply');
 $debugContext=$llm->recallOrdinaryContext($ctx,'альфарий',[$owner]);$llm->generateReply($debugContext);
 check((int)$db->query("SELECT COUNT(*) FROM llm_usage WHERE model='$usageModel' AND task='memory_recall'")->fetch_row()[0]===1,'failed main reply still flushes numeric recall billing exactly once');
 $request=$db->query("SELECT request FROM llm_debug_log WHERE kind='chat' ORDER BY id DESC LIMIT 1")->fetch_row()[0]??'';
 check(str_contains($request,'memory_recall')&&!str_contains($request,'Омегона'),'failed reply logs metadata without duplicating recalled source text');
 (new ReflectionProperty($llm,'providers'))->setValue($llm,[$fake]);
 $beforeWithdraw=$calls;Domain\Privacy::setAccountConsent($owner,false,false);check($llm->recallOrdinaryContext($ctx,'альфарий',[$owner])===$ctx&&$calls===$beforeWithdraw,'withdrawn owner is rejected inside retrieval deadline');Domain\Privacy::setAccountConsent($owner,true,false);
 $beforeGreeting=$calls;$inline->processTrigger('greeting',['user_id'=>$other,'username'=>'Other']);check($calls===$beforeGreeting,'greeting never invokes recall');
 $before=$calls;$llm->buildReplyContext(4,null,null,false);$llm->generateUtility([['role'=>'user','content'=>'utility']],'utility');$llm->processTrigger('cron_spontaneous',[]);
 check($calls===$before,'generic context, utility and spontaneous scenarios never invoke recall');
}finally{
 if(isset($usageModel)){$stmt=$db->prepare('DELETE FROM llm_usage WHERE model=?');$stmt->bind_param('s',$usageModel);$stmt->execute();}
 $cookieHash=Domain\Privacy::tokenHash();if($cookieHash){$stmt=$db->prepare('DELETE FROM privacy_choices WHERE token_hash=?');$stmt->bind_param('s',$cookieHash);$stmt->execute();}
 foreach($episodes as$id)$db->query('DELETE FROM memory_episodes WHERE id='.(int)$id);
 foreach($sources as$id)$db->query('DELETE FROM chat_messages WHERE id='.(int)$id);
 foreach($users as$id){$db->query('DELETE FROM memory_episodes WHERE id IN (SELECT episode_id FROM memory_episode_sources WHERE user_id='.(int)$id.')');foreach(['privacy_consent_events','privacy_account_choices','privacy_browser_owners','privacy_sessions','privacy_mail_cleanup','privacy_files','account_privacy']as$table){if($db->query("SHOW COLUMNS FROM `$table` LIKE 'user_id'")->num_rows)$db->query('DELETE FROM `'.$table.'` WHERE user_id='.(int)$id);}$db->query('DELETE FROM users WHERE id='.(int)$id);}
 foreach($backup as$k=>$v){if($v===null){$stmt=$db->prepare('DELETE FROM site_options WHERE key_name=?');$stmt->bind_param('s',$k);$stmt->execute();}else$cfg->setOption($k,$v);}$cfg->flushCache();
}
it_done();
