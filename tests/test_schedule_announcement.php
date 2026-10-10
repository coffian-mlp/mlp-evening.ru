<?php
require_once __DIR__.'/../autoload.php';
use LLM\ScheduleAnnouncement;
$fail = 0;
function ok($condition, $label) { global $fail; echo ($condition?'[OK] ':'[FAIL] ').$label."\n"; if (!$condition) $fail++; }
class AnnouncementLlm383 extends LLM\LLMManager {
    public ?string $reply = null;
    public array $messages = [];
    public array $instructions = [];
    public bool $persist = true;
    public bool $throw = false;
    public int $calls = 0;
    public function __construct() {}
    public function liveText(string $instruction, ?string $mustContain = null): ?string { $this->instructions[]=$instruction; return $this->reply; }
    public function botSay(string $text, array $quotedIds = []) { $this->messages[]=$text; return $this->persist; }
    public function processTrigger($type, $data = []) { $this->calls++; if ($this->throw) throw new RuntimeException('fixture'); return $this->reply !== null && $this->persist; }
}
class AnnouncementQueue383 extends LLM\JobQueue {
    public array $done=[];
    public array $released=[];
    public function __construct() {}
    public function complete(array $ids): void { $this->done=array_merge($this->done,$ids); }
    public function release(array $ids, int $delaySeconds): void { $this->released[]=[$ids,$delaySeconds]; }
}
$llm=new AnnouncementLlm383();
$stories=[['titles'=>['Part One','Part Two']],['titles'=>['Single']]];
ok(!ScheduleAnnouncement::publish($llm,$stories,'2026-10-10 20:00:00','data'), 'provider outage sends no fallback');
$llm->reply='Спасибо всем! Новая подборка готова.';
ok(!ScheduleAnnouncement::publish($llm,$stories,'utc','data') && !$llm->messages,'missing accounting confirmation is retried');
$llm->reply='Спасибо! Просмотр засчитан, следующая подборка готова.';
ok(ScheduleAnnouncement::publish($llm,$stories,'utc','fresh schedule'), 'live confirmation published');
ok(str_ends_with($llm->messages[0],"1. Part One\n2. Part Two\n3. Single"), 'all episodes and paired order preserved');
ok(str_contains(end($llm->instructions),'fresh schedule') && str_contains(end($llm->instructions),'доставка задержалась'), 'delayed delivery uses fresh schedule and warning');
$canonical = [['titles'=>array_map(fn($i)=>"My Little Pony Friendship is Magic - Season 1 Episode $i - Episode $i", range(1,8))]];
ok(ScheduleAnnouncement::publish($llm,$canonical,'utc','data') && str_contains(end($llm->messages), '8. S01E08 — Episode 8'), 'all eight episodes formatted with season and episode');
$llm->persist=false;
ok(!ScheduleAnnouncement::publish($llm,$stories,'utc','data'),'chat persistence failure is not success');
ok(!ScheduleAnnouncement::publish($llm,[],'utc','data'),'empty snapshot is not announced');
$llm=new AnnouncementLlm383(); $queue=new AnnouncementQueue383(); $job=['id'=>383,'attempts'=>0,'data'=>['schedule_announcement'=>'playlist_finished']];
ScheduleAnnouncement::processJob($llm,$queue,$job,true);
ok(!$queue->done && $queue->released===[[[383],60]],'outage retains same durable job');
$llm->throw=true; $job['attempts']=1; ScheduleAnnouncement::processJob($llm,$queue,$job,true);
ok(end($queue->released)===[[383],120] && !$queue->done,'exception retains job with backoff');
$llm->throw=false; $llm->reply='ready'; ScheduleAnnouncement::processJob($llm,$queue,$job,true);
ok($queue->done===[383],'recovery completes job after confirmed delivery');
$calls=$llm->calls; ScheduleAnnouncement::processJob($llm,$queue,$job,false);
ok($llm->calls===$calls && end($queue->released)===[[383],120],'disabled bot retains announcement without calling LLM');
ok(ScheduleAnnouncement::retryDelay(100)===900,'retry interval capped at 15 minutes without dropping job');
echo $fail ? "FAIL: $fail\n" : "ALL PASS\n";
exit($fail?1:0);
