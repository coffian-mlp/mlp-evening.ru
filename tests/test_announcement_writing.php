<?php
require_once __DIR__.'/../autoload.php';
use LLM\AnnouncementContent;
use LLM\AnnouncementSynopsis;
$fail=0;
function check386($c,$s){global $fail;echo ($c?'[OK] ':'[FAIL] ').$s."\n";if(!$c)$fail++;}
class Writer386 extends LLM\LLMManager {
 public array $calls=[];
 public array $drafts=[];
 public function __construct(){}
 public function liveTextBounded(string $instruction,?string $mustContain,int $deadlineSec,int $timeoutSec=10,?string $trustedTask=null,?array $actionContext=null):?string {
  $this->calls[]=[$instruction,$deadlineSec,$trustedTask];return array_shift($this->drafts);
 }
}
class Content386 extends AnnouncementContent {
 public bool $debut=true;
 public int $summaryCalls=0;
 protected function communityMemory():string{return 'COMMUNITY_CANARY';}
 protected function telegramDebut():bool{return $this->debut;}
 protected function episodeSummaries(array $stories):?array{$this->summaryCalls++;return [['episode_id'=>1,'title'=>'Stare Master','summary'=>'Флаттершай присматривает за Меткоискателями.','source'=>'https://example.org/stare']];}
}
$start=strtotime('2026-10-17 16:00:00 UTC');
$body="Я освоила Telegram и зову вас смотреть вместе!\n\nПосмотрим, как Флаттершай присматривает за Меткоискателями.\n\nЖду вас 17.10.2026 в 19:00 МСК на https://mlp-evening.ru/.";
check386(AnnouncementContent::caption($body,'main',179,$start)!==null,'natural date connector is accepted');
$w=new Writer386();$w->drafts=['Я объясню рандом. 17.10.2026 19:00 МСК https://mlp-evening.ru/',$body];$c=new Content386($w);
$f=['occurrence'=>['real_start_time'=>$start],'snapshot'=>['stories'=>[]],'chat'=>[['name'=>'User','text'=>'PUBLIC_CHAT_CANARY']]];
$r=$c->generateText($f,'main',179);
check386($r==="#179\n\n".$body && count($w->calls)===2,'invalid mechanism explanation is reformulated, not posted');
check386($c->summaryCalls===1,'reformulation reuses synopsis result');
check386($w->calls[0][1]===$w->calls[1][1],'writer retries retain absolute deadline');
check386(str_contains($w->calls[1][0],'validation_errors') && str_contains($w->calls[1][0],'Удали объяснения'),'writer receives concrete validation feedback');
check386(str_contains($w->calls[0][0],'COMMUNITY_CANARY') && str_contains($w->calls[0][0],'PUBLIC_CHAT_CANARY'),'persona writer retains memory and actual chat evidence');
check386(str_contains($w->calls[0][0],'перед сюжетами явно поясни') && str_contains($w->calls[0][0],'первого лица'),'writer instructed to connect invitation and viewing programme');
$w->drafts=[$body];$c->debut=false;$c->generateText($f,'reminder',179);
check386(str_contains(end($w->calls)[0],'не объявляй снова') && str_contains(end($w->calls)[0],'до просмотра три часа'),'later reminder does not repeat Telegram debut');
$w->calls=[];$w->drafts=array_fill(0,4,'Общий анонс без даты.');check386($c->generateText($f,'main',179)===null && count($w->calls)===3,'writer stops after two repairs without canned public text');
$catalogue=[['episode_id'=>1,'title'=>'Stare Master'],['episode_id'=>2,'title'=>'Part Two']];
$rows=[['episode_id'=>1,'title'=>'Stare Master','summary'=>'Summary one','source'=>'https://example.org/a'],['episode_id'=>2,'title'=>'Part Two','summary'=>'Summary two','source'=>'https://example.org/b']];
check386(AnnouncementSynopsis::decode(json_encode($rows),$catalogue,array_column($rows,'source'))!==null,'complete ordered summaries accepted');
check386(AnnouncementSynopsis::decode(json_encode(array_reverse($rows)),$catalogue,array_column($rows,'source'))===null,'summary order or identity mismatch rejected');
check386(AnnouncementSynopsis::decode(json_encode([$rows[0]]),$catalogue,array_column($rows,'source'))===null,'missing episode rejected');
check386(AnnouncementSynopsis::decode(json_encode($rows),$catalogue,['https://example.org/a'])===null,'invented citation absent from evidence rejected');
check386(count(AnnouncementSynopsis::catalogue([['ids'=>[1,2],'titles'=>['Part One','Part Two']]]))===2,'paired story has a synopsis for each part');
check386(AnnouncementSynopsis::catalogue([['ids'=>[1,2],'titles'=>['Part One']]])===[],'malformed snapshot rejected');
echo $fail?"FAIL: $fail\n":"ALL PASS\n";exit($fail?1:0);
