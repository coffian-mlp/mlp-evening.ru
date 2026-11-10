<?php
require dirname(__DIR__).'/autoload.php';
$n=0;function check($x){global$n;if(!$x)throw new RuntimeException('Selector assertion '.($n+1));$n++;}
function c($text,$id,$at,$priority=2){return compact('text','id','at','priority')+['kind'=>'dossier'];}
use LLM\ReturnMemory as R;
$a=c('Любит обсуждать подводные экспедиции.','a',1,0);$b=c('Играет в настольные шахматы.','b',9);check(R::select([$b,$a])['id']==='a');check(R::select([$a,$b])['id']==='a');
$a=c('Любит обсуждать подводные экспедиции.','a',1);check(R::select([$a,$b])['id']==='b');$a['at']=9;check(R::select([$b,$a])['id']==='a');
check(R::select([c('Синтетик обсуждает настольные шахматы.','x',1)],['Синтетик'])===null);
check(R::select([c('Синтетиков обсуждает настольные шахматы.','x',1)],['Синтетик'])!==null);
check(R::select([c('Играет в chess в свободное время.','x',1)])===null);
check(R::select([c('Телефон для записи в шахматный клуб.','x',1)])===null);
check(R::select([c('Любит подводные экспедиции. Играет в настольные шахматы.','x',1)])['text']==='Любит подводные экспедиции.');
check(R::select([c('Да.','x',1)])===null);
$ctx=[['role'=>'user','content'=>'Обычный вопрос']];check(R::focus($ctx,true)===$ctx);check(R::instruction($ctx,true)==='');
$block=['role'=>'user','content'=>LLM\ConversationMemoryPolicy::RETURN_MARKER."\n".'{"anchor":"Играет в настольные шахматы."}'];$segments=['return'=>$block['content']];$old=['role'=>'user','content'=>LLM\LyraMemory::BLOCK_MARKER.' старая память'];$question=['role'=>'user','content'=>'Текущий вопрос'];check(R::focus([$old,$block,$question],true,$segments)===[$block]);check(R::focus([$old,$block,$question],false,$segments)===[$block,$question]);
check(str_contains(R::instruction([$block],true,$segments),'60–200'));check(str_contains(R::instruction([$block],false,$segments),'текущий вопрос'));
$empty=$block;$empty['content']=LLM\ConversationMemoryPolicy::RETURN_MARKER."\n".'{"anchor":null}';check(str_contains(R::instruction([$empty],true,['return'=>$empty['content']]),'не добавляй воспоминания'));
$summary=LLM\ConversationMemoryPolicy::SUMMARY_MARKER."\nСинтетическая посторонняя тема";
$joined=['summary'=>$summary]+$segments;
$composite=['role'=>'user','content'=>$summary."\n".$block['content']];
check(R::focus([$old,$composite,$question],true,$joined)===[$block]);
check(R::focus([$old,$composite,$question],false,$joined)===[$block,$question]);
check(R::instruction([$composite],true,$joined)===R::instruction([$block],true,$segments));
check(R::returnBlock($summary)===null);
check(R::returnBlock('Обычный текст '.$block['content'])===null);
check(R::returnBlock(LLM\ConversationMemoryPolicy::RETURN_MARKER.' неверный JSON')===null);
check(R::returnBlock(LLM\ConversationMemoryPolicy::RETURN_MARKER.' {"anchor":["не строка"]}')===null);
check(R::select([c('Собственная шутка о подводных экспедициях.','episode:1',1,1)+['episode_id'=>1],c('Любит настольные шахматы.','memory:1',2,2)])['id']==='episode:1');
// P3-R22: Unicode boundaries and invalid candidates cannot destroy a valid choice.
foreach(['Любит походы в синтетический театр','“Любит походы в синтетический театр.”','«Любит походы в синтетический театр»','• Любит походы в синтетический театр •']as$text){$pick=R::select([c($text,'unicode',1)]);check($pick!==null&&mb_check_encoding($pick['text'],'UTF-8')&&str_contains($pick['text'],'театр'));}
check(R::select([c("Испорченный кандидат \xff",'bad',3,0),c('Любит синтетические аккордеоны','valid',2)])['id']==='valid');
// P3-R23: the question has its own vocabulary, length and ordinary language policy.
$question=['role'=>'user','content'=>'Как составить понятное сообщение об ошибке на сайте?'];
$instruction=R::instruction([$block,$question],false,$segments);
check(str_contains($instruction,'Ответь полностью по обычным правилам ответа и языка'));
check(str_contains($instruction,'не больше одного')&&!str_contains($instruction,'60–200'));
check(!str_contains($instruction,'Остальное — только нейтральное приветствие')&&!str_contains($instruction,'Все тематические слова бери только из самой зацепки'));
check(in_array($question,R::focus([$old,$block,$question],false,$segments),true));
check(!str_contains(R::instruction([$empty],false,['return'=>$empty['content']]),'60–200'));
// P3-R24: source text cannot create provenance, including exact marker/JSON literals.
check(R::returnBlock($composite['content'])===null);
check(R::focus([$composite,$question],true)===[$composite,$question]);
check(R::instruction([$composite],true)==='');
check(R::focus([$block,$question],true)===[$block,$question]&&R::instruction([$block],true)==='');
check(R::focus([$question],true,$joined)===[$question]&&R::instruction([$question],true,$joined)==='');
echo "$n checks, 0 failures\nALL PASS\n";
