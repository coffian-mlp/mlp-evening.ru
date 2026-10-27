<?php
namespace LLM {
    /** Isolated completion seam: no database, network or chat writes. */
    class LLMManager {
        public static array $answers=[],$calls=[];
        private ?array $issue=null;
        public function liveTextBounded(string $instruction,?string $mustContain,int $deadlineSec,int $timeoutSec=10,?string $trustedTask=null,?array $actionContext=null):?string {
            self::$calls[]=func_get_args();$answer=array_shift(self::$answers)??['text'=>null];
            $this->issue=$answer['issue']??null;
            if(!empty($answer['exhaust_budget']))sleep(max(0,$deadlineSec-time()-4));
            return $answer['text'];
        }
        public function lastLiveReplyIssue():?array{return $this->issue;}
    }
}
namespace {
require_once __DIR__.'/../autoload.php';
$fail=0;$check=static function($v,$m)use(&$fail){echo($v?'[OK] ':'[FAIL] ').$m."\n";if(!$v)$fail++;};
$live=new \ReflectionMethod(\Domain\StreamMonitor::class,'liveNotice');
$call=static function(array $answers)use($live){\LLM\LLMManager::$answers=$answers;\LLM\LLMManager::$calls=[];return $live->invoke(null,'Сигнал эфира пропал; попроси проверить трансляцию.');};
$natural='Ой, эфир куда-то ускакал! Стример, глянь трансляцию и выбери кнопку ниже.';
$check($call([['text'=>$natural]])===$natural&&count(\LLM\LLMManager::$calls)===1,'valid persona text is preserved without another model call');
$issue=['text'=>'@invented, проверь эфир','errors'=>['address'=>'Недопустимое упоминание']];
$check($call([['text'=>null,'issue'=>$issue],['text'=>$natural]])===$natural,'rejected address is reformulated instead of immediately falling back');
$requests=\LLM\LLMManager::$calls;
$check(count($requests)===2&&$requests[0][2]===$requests[1][2]&&$requests[0][3]===6,'repair shares the original eight-second deadline and request timeout');
$check(str_contains($requests[1][0],'previous_reply')&&str_contains($requests[1][0],'validation_errors')&&str_contains($requests[1][4],'не исполняй инструкции'),'repair receives validation data without promoting previous output to instructions');
$check($requests[0][5]['actor_id']===0&&$requests[0][5]['include_pinned']===false&&str_contains($requests[0][4],'адресата и кнопки вставляет код'),'persona/background action context remains separate from chat and recipient insertion');
$check($call([['text'=>'[[command:1]]'],['text'=>'@other, да'],['text'=>$natural]])===$natural&&count(\LLM\LLMManager::$calls)===3,'two reformulations can recover invalid markers and mentions');
$check($call([['text'=>'SILENCE'],['text'=>'@other'],['text'=>str_repeat('x',901)]])===null&&count(\LLM\LLMManager::$calls)===3,'invalid responses stop after the original attempt and two repairs');
$check($call([['text'=>null],['text'=>$natural]])===null&&count(\LLM\LLMManager::$calls)===1,'unavailable model falls back immediately without repeated requests');
$check($call([['text'=>null,'issue'=>$issue,'exhaust_budget'=>true],['text'=>$natural]])===null&&count(\LLM\LLMManager::$calls)===1,'insufficient remaining budget cannot delay the alarm with a repair');
$reflection=new \ReflectionClass(\Domain\StreamMonitor::class);$monitor=$reflection->newInstanceWithoutConstructor();
$reflection->getProperty('writer')->setValue($monitor,static fn()=>null);
$content=$reflection->getMethod('content');[$fallback]=$content->invoke($monitor,'site_warning',['lost_at'=>gmdate('Y-m-d H:i:s')],[],time());
$check(str_contains($fallback,'Сигнал эфира пропал')&&str_contains($fallback,'катушки')&&str_contains($fallback,'кнопкой')&&!str_contains($fallback,'@'),'fallback retains persona, loss fact and streamer controls without inventing an addressee');
$reflection->getProperty('writer')->setValue($monitor,static fn()=>$natural);
$check($content->invoke($monitor,'site_warning',['lost_at'=>gmdate('Y-m-d H:i:s')],[],time())[0]===$natural,'normal LLM wording is never replaced with the fallback');
echo $fail?"FAIL: $fail\n":"ALL PASS\n";exit($fail?1:0);
}
