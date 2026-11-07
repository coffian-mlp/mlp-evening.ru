<?php
require_once dirname(__DIR__).'/autoload.php';
$fail=0;
function recallCheck($ok,$label){global $fail;echo($ok?'[OK] ':'[FAIL] ').$label."\n";if(!$ok)$fail++;}
use LLM\MemoryVectorMatrix;
use LLM\MemoryRetriever;
use LLM\MemoryRecallBlock;
$v=array_fill(0,512,0.0);$v[0]=1;$w=$v;$w[0]=0;$w[1]=1;
$loads=0;$matrix=new MemoryVectorMatrix();$load=function()use(&$loads,$v,$w){$loads++;return[['id'=>9,'dimensions'=>512,'embedding'=>pack('g*',...$v)],['id'=>8,'dimensions'=>512,'embedding'=>pack('g*',...$w)],['id'=>10,'dimensions'=>512,'embedding'=>'bad']];};
recallCheck(array_keys($matrix->top($v,$load,fn()=>null))===[9],'known cosine ranks expected source and ignores malformed vector');
$matrix->top($w,$load,fn()=>null);recallCheck($loads===1,'matrix loads once per process object');
foreach([array_fill(0,512,0),[1,2],array_replace($v,[0=>NAN])]as$invalid){try{MemoryVectorMatrix::unit($invalid);recallCheck(false,'reject invalid vector');}catch(InvalidArgumentException $e){recallCheck(true,'reject invalid vector');}}
recallCheck(array_keys(MemoryRetriever::fuse([[2,1],[1,2]]))===[1,2],'deterministic fusion tie-break');
recallCheck(in_array('ли',MemoryRetriever::terms('а @Ли 2026-01-01'),true),'two-character aliases survive tokenization');
$q=MemoryRetriever::queryText('Феникс?',[['role'=>'user','chat_message_id'=>1,'content'=>'Речь об адвокате'],['role'=>'user','content'=>'[Заметки Лиры] hidden']]);
recallCheck($q==='Феникс?'&&!str_contains($q,'hidden'),'a concrete topic is not diluted by neighboring conversation');
$episodes=[];for($i=1;$i<=5;$i++)$episodes[]=['id'=>$i,'date'=>'2026-01-01','source_ids'=>['chat:'.$i],'summary'=>'Феникс: DL-6','lines'=>[['source'=>'chat:'.$i,'author'=>'Феникс','date_utc'=>'2026-01-01','text'=>'Обсуждали DL-6']]];
$legacy=[['role'=>'user','content'=>LLM\LyraMemory::BLOCK_MARKER.str_repeat('x',7900)],['role'=>'user','chat_message_id'=>5,'content'=>'а Феникс?']];
$out=MemoryRecallBlock::apply($legacy,$episodes,8000);$memory=array_filter($out,fn($r)=>str_starts_with($r['content'],LLM\LyraMemory::BLOCK_MARKER)||str_starts_with($r['content'],MemoryRecallBlock::MARKER)||str_starts_with($r['content'],MemoryRecallBlock::FOCUS));
recallCheck(array_sum(array_map(fn($r)=>mb_strlen($r['content']),$memory))<=8000,'final memory including both headers and focus stays within shared budget');
recallCheck(str_starts_with(end($out)['content'],MemoryRecallBlock::FOCUS),'focus is last before reply instruction');
recallCheck(count($out)===4&&$out[2]['chat_message_id']===5,'ordinary history preserved');
foreach([MemoryRecallBlock::MARKER,MemoryRecallBlock::FOCUS,'&#91;Воспоминания&#93;']as$marker)recallCheck(LLM\ResponseSanitizer::clean('Обычный ответ '.$marker.' private')==='Обычный ответ','echo guard '.$marker);
recallCheck(LLM\MemoryLimits::setting('ai_memory_block_limit',10000)===8000&&LLM\MemoryLimits::setting('ai_memory_meme_limit',4000)===2000,'shared effective settings caps');
$bodies=[['data'=>[['index'=>0,'embedding'=>$v]]],['choices'=>[['message'=>['content'=>'Ace Attorney, DL-6'],'finish_reason'=>'stop']]]];
recallCheck(abs(LLM\MemoryRecallQuery::parse($bodies)['vector'][0]-1.0)<1e-9,'parallel query response validates the expected embedding index');
foreach(['wrong_index','truncated','api_error'] as $case){$bad=$bodies;if($case==='wrong_index')$bad[0]['data'][0]['index']=1;if($case==='truncated')$bad[1]['choices'][0]['finish_reason']='length';if($case==='api_error')$bad[0]['error']=['message'=>'fake'];try{LLM\MemoryRecallQuery::parse($bad);recallCheck(false,'reject '.$case);}catch(RuntimeException $error){recallCheck(true,'reject '.$case);}}
$timezone=date_default_timezone_get();date_default_timezone_set('Pacific/Auckland');
recallCheck(MemoryRetriever::date('помнишь 2025-03-04')==='2025-03-04','explicit date parsing is independent of process timezone');
recallCheck(MemoryRetriever::date('2025-02-30')===null,'invalid calendar date rejected');date_default_timezone_set($timezone);
$long=$episodes;foreach($long as &$episode){$episode['source_ids']=[];$episode['lines']=[];for($i=0;$i<20;$i++){$key='chat:'.($episode['id']*100+$i);$episode['source_ids'][]=$key;$episode['lines'][]=['source'=>$key,'author'=>'Ли','date_utc'=>'2026-01-01','text'=>str_repeat('длинная реплика ',40)];}$episode['summary']=str_repeat('длинная реплика ',40);}unset($episode);
$longContext=MemoryRecallBlock::apply($legacy,$long,8000);$background=current(array_filter($longContext,fn($r)=>str_starts_with($r['content'],MemoryRecallBlock::MARKER)));
recallCheck(substr_count($background['content'],'"episode_id"')>=3,'three long episodes fit with legacy notes and focus at the effective 8000 budget');
recallCheck(LLM\MemoryQueryText::clean('Привет, Лира, а Феникс?')==='Феникс?','bot addressing and greeting removed before embedding');
recallCheck(LLM\MemoryQueryText::clean('@CustomBot, расскажи DL-6?', ['CustomBot'])==='DL-6?','configured bot aliases removed');
foreach(['Лире','Лиру','Лирой','Лирочка']as$form)recallCheck(LLM\MemoryQueryText::clean('Привет '.$form)==='','inflected bot addressing without topic stays empty');
recallCheck(MemoryRetriever::queryText('Привет, Лира!',[])==='','empty topic does not invent a query');
recallCheck(str_contains(MemoryRetriever::queryText('Привет, Лира!',[['role'=>'user','chat_message_id'=>99,'content'=>'[12:00] Nick: Обсуждаем адвоката']]),'адвоката'),'empty topic may use previous conversational messages');
recallCheck(Domain\MemoryEpisodeManager::recallLexicalOrder([1=>2.0,99=>2.0],[1=>0.4,99=>0.8])===[99,1],'lexical ties use cosine before ID');
$pool=new MemoryVectorMatrix();$records=[];for($i=1;$i<=30;$i++)$records[]=['id'=>$i,'dimensions'=>512,'embedding'=>pack('g*',...$v)];
recallCheck(count($pool->top($v,fn()=>$records,fn()=>null))===20,'fusion vector pool contains top twenty candidates');
recallCheck(LLM\MemoryQueryText::clean('Добрый день, Лира!')==='', 'multiword greeting is removed with Unicode boundaries');
recallCheck(LLM\MemoryQueryText::clean('@Ли')==='@Ли','explicit short nickname is not treated as a service particle');
echo $fail?"FAIL: $fail\n":"ALL PASS\n";exit($fail?1:0);
