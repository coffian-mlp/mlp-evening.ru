<?php
/** MLP-460: stale greetings and scene input use distinct, bounded history. */
require_once __DIR__.'/../autoload.php';
$fail=0;$total=0;
$ok=function(bool $v,string $name)use(&$fail,&$total){$total++;$fail+=(int)!$v;echo($v?'[OK] ':'[FAIL] ').$name."\n";};
$now=1800000000;$bot=12;
$row=fn($uid,$age,$deleted=0)=>['user_id'=>$uid,'created_at'=>gmdate('Y-m-d H:i:s',$now-$age),'is_deleted'=>$deleted];
if(!class_exists(LLM\GreetingContext::class)||!method_exists(LLM\LyraArtist::class,'directorTask')){echo "[FAIL] greeting/scene context policy is missing\nFAIL: 1\n";exit(1);}
$ok(LLM\GreetingContext::idle([], $bot,$now),'empty chat is idle');
$ok(LLM\GreetingContext::idle([$row(2,5400)],$bot,$now),'exact idle boundary ends conversation');
$ok(!LLM\GreetingContext::idle([$row(2,5399)],$bot,$now),'one second before idle boundary stays live');
$ok(LLM\GreetingContext::idle([$row(2,40000),$row($bot,60)],$bot,$now),'recent bot output does not revive an ended conversation');
$ok(LLM\GreetingContext::idle([$row(2,40000),$row(3,60,1)],$bot,$now),'deleted content does not revive a conversation');
$ok(!LLM\GreetingContext::idle([$row(2,40000),$row(3,60)],$bot,$now),'another human keeps a conversation live');
$ok(LLM\GreetingContext::idle([$row(2,-60)],$bot,$now),'future timestamps do not revive conversation');
$originalTz=date_default_timezone_get();date_default_timezone_set('America/New_York');
$ok(LLM\GreetingContext::idle([$row(2,5400)],$bot,$now),'database dates are interpreted as UTC');date_default_timezone_set($originalTz);
$ctx=[['role'=>'user','content'=>'[23:59] Synthetic: Обсуждаем гонку воздушных шаров.'],['role'=>'assistant','content'=>"[00:01] Лира: OLD_SCENE в ночной комнате.\n![рисунок](/upload/lyra/old.jpg)"],['role'=>'assistant','content'=>'[00:02] Лира: Воздушный шар над облаками!']];
$recent=array_map(fn($i)=>['message'=>'PREVIOUS_'.$i.' ![рисунок](/upload/lyra/'.$i.'.jpg)'],range(1,7));
$task=LLM\LyraArtist::directorTask($ctx,'Внешность: Synthetic — green mane.',$recent,'{"wish":"вид сверху"}');
$content=$task[0]['content'];
$ok(count($task)===1&&$task[0]['role']==='user','transcript remains data in one user message');
$ok(!str_contains($content,'OLD_SCENE'),'old image caption is excluded from the transcript');
$ok(str_contains($content,'гонку воздушных шаров')&&str_contains($content,'Воздушный шар над облаками'),'human topic and normal bot response survive');
$ok(!str_contains($content,'23:59')&&!str_contains($content,'00:02'),'message timestamps do not dictate the scene lighting');
$ok(str_contains($content,'green mane')&&str_contains($content,'вид сверху'),'appearance and collected wishes survive');
$ok(str_contains($content,'PREVIOUS_1')&&str_contains($content,'PREVIOUS_5')&&!str_contains($content,'PREVIOUS_6'),'five most recent scenes are bounded');
$ok(!str_contains($content,'/upload/lyra/'),'image paths are not needed by the director');
$empty=LLM\LyraArtist::directorTask([], '', [], '');
$ok($empty===[],'empty transcript does not invent a scene from previous drawings');
$seen=null;$identity=null;
$subject='@Synthetic в мастерской днём, вид сверху';
$result=LLM\LyraArtist::drawingSubjectForMentions($subject,[['id'=>1,'login'=>'synthetic','nickname'=>'Synthetic']],[1=>'green mane'],'BIOGRAPHY_NIGHT_WORKSHOP_SENTINEL',
    function($context,$system)use(&$seen){$seen=[$context,$system];return 'Synthetic with a green mane arranges tools in the workshop, seen from above.';},$identity,$recent);
$explicit=json_decode($seen[0][0]['content'],true);
$ok(!str_contains($seen[0][0]['content'],'BIOGRAPHY_NIGHT_WORKSHOP_SENTINEL')&&str_contains($seen[0][0]['content'],'green mane'),'biography does not prescribe the explicit scene; appearance remains');
$ok(isset($explicit['artist'])&&str_contains($explicit['artist']['appearance'],'mint-green')&&str_contains($explicit['artist']['appearance'],'golden eyes'),'artist identity survives removal of biography for self portraits');
$ok($explicit['request']===$subject&&count($explicit['previous_scenes'])===5,'explicit director preserves request and gets the same five prior scenes');
$ok(str_contains($result,$subject)&&str_contains($seen[1],'original request remains authoritative'),'explicit setting and composition retain precedence over variety');
echo $fail?"FAIL: $fail/$total\n":"ALL PASS ($total)\n";exit($fail?1:0);
