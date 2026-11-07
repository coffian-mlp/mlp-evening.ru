<?php
ob_start();require_once __DIR__.'/integration_helpers.php';$db=it_require_db();require_once dirname(__DIR__).'/migrate.php';migrate_database($db,dirname(__DIR__).'/migrations');
$cfg=Infra\ConfigManager::getInstance();$marker='recall_review_'.bin2hex(random_bytes(5));$users=[];$ids=[];$episodeIds=[];$backup=[];$logIds=[];
$settings=['ai_memory_recall_enabled'=>1,'ai_memory_recall_expand'=>0,'ai_memory_enabled'=>1,'ai_memory_block_limit'=>8000,'ai_enabled'=>1,'ai_debug_log'=>1,'ai_reactions'=>0,'ai_online_in_context'=>0,'ai_system_prompt'=>'Синтетическая проверка.','ai_additional_instructions'=>''];
foreach(array_merge(array_keys($settings),['ai_bot_user_id','ai_owner_user_id'])as$key)$backup[$key]=$cfg->getOptionDetails($key)['value']??null;
class RecallReviewProvider implements LLM\LLMProviderInterface {
 public int $calls=0;public function __construct(private array $answers,private string $model){}
 public function getModel():string{return $this->model;}
 public function askChat(array $messagesContext,string $systemPrompt):?string{$answer=$this->answers[$this->calls++]??null;if($answer instanceof Throwable)throw $answer;return $answer;}
}
try {
 foreach(['owner','bot']as$role){$users[]=(new Domain\UserManager())->createUser($marker.'_'.$role,'Aa!'.bin2hex(random_bytes(10)),'user','Лира');}
 [$owner,$bot]=$users;$settings['ai_owner_user_id']=$owner;$settings['ai_bot_user_id']=$bot;foreach($settings as$k=>$v)$cfg->setOption($k,$v);
 $texts=['УНИКАЛЬНЫЙ_ИСТОЧНИК_'.bin2hex(random_bytes(6)),'Бот произнёс вторую фразу.'];
 foreach($users as$i=>$id){$stmt=$db->prepare("INSERT INTO chat_messages(user_id,username,message,created_at) VALUES(?,'Лира',?,?)");$date='2026-11-07 00:0'.$i.':00';$stmt->bind_param('iss',$id,$texts[$i],$date);$stmt->execute();$ids[]=(int)$db->insert_id;}
 $store=new Domain\MemoryEpisodeManager();$reader=new LLM\MemorySourceReader();$profile=hash('sha256',$marker);$episode=(new LLM\MemoryEpisodeBuilder($bot))->chat((new Domain\ChatManager())->memorySourceRows($ids),$profile)[0];$record=$store->register($episode,$reader->current(...));$episodeIds[]=(int)$record['id'];$vector=array_fill(0,512,0.0);$vector[0]=1;$store->publish($episode,$record,'Феникс Райт DL-6',$vector,$reader->current(...));
 $llm=new LLM\LLMManager();(new ReflectionProperty($llm,'memoryRetriever'))->setValue($llm,new LLM\MemoryRetriever($store,$reader,$profile,$bot,fn()=>['vector'=>$vector,'tags'=>'']));
 $context=$llm->recallOrdinaryContext([['role'=>'user','content'=>'а Феникс?']],'а Феникс?',[$owner]);
 $background=current(array_filter($context,fn($m)=>str_starts_with($m['content'],LLM\MemoryRecallBlock::MARKER)));$lines=explode("\n",$background['content']);$block=json_decode($lines[1],true);$sources=$block['sources'];
 check($sources[0]['author']===$sources[1]['author']&&$sources[0]['role']==='user'&&$sources[1]['role']==='bot','F2-R2 equal nicknames preserve distinct roles in final background');
 check($sources[0]['user_id']===$owner&&$sources[1]['user_id']===$bot,'F2-R2 final background preserves stable user IDs');
 $focus=end($context)['content'];$focusData=json_decode(substr($focus,strpos($focus,"\n")+1),true);$speakers=$focusData[0]['sources'];check($speakers[0]['user_id']===$owner&&$speakers[1]['user_id']===$bot&&$speakers[0]['role']==='user'&&$speakers[1]['role']==='bot','F2-R2 focus preserves complete structured IDs and roles for equal-named actors');
 $cases=[
  'success'=>['Готово.'],
  'truncation'=>[new LLM\TruncatedResponseException('fake',$texts[0])],
  'failure'=>[new RuntimeException($texts[0])],
  'latin_success'=>['Unicornproof','Согласна.'],
  'latin_truncated'=>['Unicornproof',new LLM\TruncatedResponseException('fake',$texts[0])],
  'latin_failure'=>['Unicornproof',new RuntimeException($texts[0])],
  'transient_retry'=>[new RuntimeException('RouterAI HTTP Error 503: synthetic'),'Готово.'],
 ];
 foreach($cases as$name=>$answers){
  $provider=new RecallReviewProvider($answers,$marker.'_'.$name);foreach(['providers','fastProviders']as$p)(new ReflectionProperty($llm,$p))->setValue($llm,[$provider]);
  $before=(int)$db->query('SELECT COALESCE(MAX(id),0) FROM llm_debug_log')->fetch_row()[0];$llm->generateReply($context);
  $logs=$db->query('SELECT id,request,response FROM llm_debug_log WHERE id>'.$before.' ORDER BY id')->fetch_all(MYSQLI_ASSOC);$logIds=array_merge($logIds,array_column($logs,'id'));
  check(count($logs)>=1,'F2-R1 actual '.$name.' path logged');
  if(str_starts_with($name,'latin_')||$name==='transient_retry')check($provider->calls===2,'F2-R1 actual '.$name.' makes second provider call');
  if($name==='latin_success')check(count($logs)===2,'F2-R1 both language attempts logged');
  foreach($logs as$log){check(!str_contains($log['request']??'',$texts[0])&&!str_contains($log['response']??'',$texts[0]),'F2-R1 '.$name.' log contains no recalled source text');check(str_contains($log['request'],'memory_recall'),'F2-R1 '.$name.' metadata retained');}
 }
 $failing=new RecallReviewProvider([new RuntimeException('synthetic fallback')],$marker.'_first');$second=new RecallReviewProvider(['Готово.'],$marker.'_second');(new ReflectionProperty($llm,'providers'))->setValue($llm,[$failing,$second]);
 $before=(int)$db->query('SELECT COALESCE(MAX(id),0) FROM llm_debug_log')->fetch_row()[0];$llm->generateReply($context);$logs=$db->query('SELECT id,request FROM llm_debug_log WHERE id>'.$before)->fetch_all(MYSQLI_ASSOC);$logIds=array_merge($logIds,array_column($logs,'id'));check(count($logs)===2&&$failing->calls===1&&$second->calls===1,'F2-R1 provider fallback covered');foreach($logs as$log)check(!str_contains($log['request'],$texts[0])&&str_contains($log['request'],'memory_recall'),'F2-R1 provider fallback redacted with metadata');
 LLM\LlmDebugLog::log('utility','review_boundary',$marker,['messages'=>[['role'=>'user','content'=>LLM\MemoryRecallBlock::MARKER.$texts[0]]]],$texts[0],'ok',1);$last=$db->query("SELECT id,request,response FROM llm_debug_log WHERE model='$marker' ORDER BY id DESC LIMIT 1")->fetch_assoc();$logIds[]=$last['id'];check(!str_contains($last['request'],$texts[0])&&!str_contains($last['response'],$texts[0]),'F2-R1 central boundary protects indirect log callers too');
 $calls=0;$empty=new LLM\MemoryRetriever($store,$reader,$profile,$bot,function()use(&$calls,$vector){$calls++;return ['vector'=>$vector,'tags'=>''];});$input=[['role'=>'user','content'=>'greeting']];$result=$empty->recall($input,'Привет, Лира!',8000);check($result['context']===$input&&$calls===0&&$result['metadata']['reason']==='no_query_topic','greeting-only query performs no embedding or recall');
 $weak=$vector;$weak[0]=0.25;$weak[1]=sqrt(1-0.25**2);$negative=new LLM\MemoryRetriever($store,$reader,$profile,$bot,fn()=>['vector'=>$weak,'tags'=>'']);check($negative->recall($input,'несуществующаятема',8000)['context']===$input,'weak vector match below relevance gate produces empty block');
 check($negative->recall($input,'Феникс незнакомаятема',8000)['context']===$input,'partial lexical overlap does not bypass relevance gate');
 check($negative->recall($input,'Феникс Райт',8000)['context']!==$input,'complete lexical evidence admits a weak but anchored vector');
 check($negative->recall($input,'DL-6?',8000)['context']!==$input,'structured identifier evidence survives FT minimum token size');
 // The logger string interface must protect every JSON serialization, without changing ordinary bytes.
 $payload=['messages'=>[['content'=>LLM\MemoryRecallBlock::MARKER.$texts[0]]],'url'=>'https://example.test/a/b'];
 $serializations=['array'=>$payload,'nested_array'=>['nested'=>[$payload]]];
 foreach([0,JSON_UNESCAPED_UNICODE,JSON_UNESCAPED_SLASHES,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES]as$flags){
  $json=json_encode($payload,$flags);$serializations['json_'.$flags]=$json;
  $serializations['nested_json_'.$flags]=['nested'=>json_encode(['inside'=>$json],$flags)];
  $serializations['json_string_'.$flags]=json_encode($json,$flags);
 }
 $plain=['message'=>'Обычная реплика / путь'];$plainCases=['plain_array'=>$plain,'plain_spaced_json'=>"{\n \"text\": \"\\u041b\\u0438\\u0440\\u0430\", \"url\": \"https:\\/\\/example.test\"\n}"];
 foreach([0,JSON_UNESCAPED_UNICODE,JSON_UNESCAPED_SLASHES,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES]as$flags)$plainCases['plain_json_'.$flags]=json_encode(['nested'=>json_encode($plain,$flags)],$flags);
 foreach(array_merge($serializations,$plainCases)as$name=>$request){
  $ordinary=str_starts_with($name,'plain_');$response=$ordinary?'Обычный ответ / unchanged':$texts[0];$model=$marker.'_edge_'.$name;
  LLM\LlmDebugLog::log('utility','review_serialization',$model,$request,$response,'ok',1);
  $s=$db->prepare('SELECT id,request,response FROM llm_debug_log WHERE model=? ORDER BY id DESC LIMIT 1');$s->bind_param('s',$model);$s->execute();$row=$s->get_result()->fetch_assoc();$logIds[]=(int)$row['id'];
  check($ordinary?($row['request']===LLM\LlmDebugLog::squash($request)&&$row['response']===$response):(!str_contains($row['request'],$texts[0])&&!str_contains($row['response'],$texts[0])),'F2-R3 actual logger '.$name.' privacy/byte parity');
 }
 // Exercise normalization -> terms -> actual MySQL BOOLEAN evidence, including attached operators.
 // A second source is not needed: this isolated ready row only exercises association selection.
 $key=hash('sha256',$marker.'_lexical');$s=$db->prepare("INSERT INTO memory_episodes(episode_key,source_kind,first_source_id,last_source_id,source_hash,profile_hash,authors_json,started_at,ended_at,status,associations) VALUES(?,'memory',0,0,?,?,'[]','2026-01-01','2026-01-01','ready','Феникс другой')");$s->bind_param('sss',$key,$key,$profile);$s->execute();$episodeIds[]=(int)$db->insert_id;
 $expected=[$episodeIds[0]];
 foreach(['Феникс Райт','Феникс + - * " ~ < > ( ) @ Райт','+Феникс -Райт*','Феникс "Райт','>Феникс <Райт',str_repeat('Феникс Райт ',1000)]as$query){
  $clean=LLM\MemoryQueryText::clean($query);$strong=$store->recallStrongAssociationIds(LLM\MemoryRetriever::terms($clean),$profile);sort($strong);
  check($strong===$expected,'F2-R4 BOOLEAN preserves both words: '.mb_substr($query,0,60));
 }
 foreach(['','+-*"~<>()@']as$query)check($store->recallStrongAssociationIds(LLM\MemoryRetriever::terms(LLM\MemoryQueryText::clean($query)),$profile)===[],'F2-R4 empty/operator-only BOOLEAN has no evidence');
} finally {
 foreach($logIds as$id)$db->query('DELETE FROM llm_debug_log WHERE id='.(int)$id);
 foreach($episodeIds as$id)$db->query('DELETE FROM memory_episodes WHERE id='.(int)$id);
 foreach($ids as$id)$db->query('DELETE FROM chat_messages WHERE id='.(int)$id);
 foreach($users as$id)$db->query('DELETE FROM users WHERE id='.(int)$id);
 foreach($backup as$key=>$value){if($value===null){$s=$db->prepare('DELETE FROM site_options WHERE key_name=?');$s->bind_param('s',$key);$s->execute();}else$cfg->setOption($key,$value);}$cfg->flushCache();
}
it_done();
