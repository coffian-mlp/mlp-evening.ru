<?php
if (($argv[1] ?? '')==='legacy-claim') {
    $root=dirname(__DIR__,2);require $root.'/baseline-red/autoload.php';
    if(!is_file($root.'/.mlp468-owned') || Infra\Env::get('DB_HOST')!=='db')throw new RuntimeException('Synthetic legacy queue probe only');
    $db=Infra\Database::getInstance()->getConnection();$db->query("SELECT GET_LOCK('bot_worker',0)");
    try {echo json_encode(array_map(static fn($job)=>(int)$job['id'],(new LLM\JobQueue())->claimDue(50))),"\n";}
    finally {$db->query("SELECT RELEASE_LOCK('bot_worker')");}exit;
}
if (in_array($argv[1] ?? '',['inline-virtual','tick-virtual'],true)) require __DIR__.'/drawing_inline_clock.php';
require __DIR__.'/drawing_transport.php';
require __DIR__.'/drawing_fixture.php';
$action=$argv[1] ?? 'tick'; $id=isset($argv[2])?(int)$argv[2]:null;
$db=Infra\Database::getInstance()->getConnection();
if ($action==='tick') (new LLM\BotWorker())->tick();
elseif ($action==='tick-virtual') { $GLOBALS['drawing_clock']=\time()+61;(new LLM\BotWorker())->tick(); }
elseif ($action==='context-probe') {
    $llm=new class extends LLM\LLMManager {
        public function buildReplyContext(int $limit=24,$maxAgeHours=null,?int $beforeId=null,bool $includePinned=true,?bool $includeMemory=null,?string $memoryQuery=null): array {
            $context=parent::buildReplyContext($limit,$maxAgeHours,$beforeId,$includePinned,$includeMemory,$memoryQuery);
            $dir=dirname(__DIR__,2).'/cache/mlp468';file_put_contents($dir.'/context_entered','1');$deadline=microtime(true)+20;
            while(!is_file($dir.'/context_release')&&microtime(true)<$deadline)usleep(20000);
            if(!is_file($dir.'/context_release'))throw new RuntimeException('Context barrier not released');
            return $context;
        }
    };
    $worker=new LLM\BotWorker();(new ReflectionProperty($worker,'llm'))->setValue($worker,$llm);$worker->tick();
}
elseif ($action==='prepare') LLM\DrawingJob::prepareOne(new LLM\LLMManager(),$id);
elseif ($action==='prepare-inline-only') {
    $llm=new LLM\LLMManager();$queue=new LLM\JobQueue();$db->query("SELECT GET_LOCK('bot_worker',0)");
    try { LLM\DrawingJob::route($llm,$queue->drawingRow($id),true); }
    finally { $db->query("SELECT RELEASE_LOCK('bot_worker')"); }
    LLM\DrawingJob::prepareOne($llm,$id);
}
elseif ($action==='recover') LLM\DrawingJob::recover();
elseif ($action==='deliver') {
    if (!(int)$db->query("SELECT GET_LOCK('bot_worker',0) n")->fetch_assoc()['n']) exit(3);
    try { LLM\DrawingJob::deliver(new LLM\LLMManager(),$id); } finally { $db->query("SELECT RELEASE_LOCK('bot_worker')"); }
} elseif ($action==='inline' || $action==='inline-virtual') {
    $job=(new LLM\JobQueue())->drawingRow($id);
    LLM\BotDispatch::runInlineDrawing($id,$job['data'],0);
} elseif ($action==='counter') LLM\ImageGenerator::bumpToday(null,gmdate('Y-m-d'),'synthetic_counter_'.$id);
elseif ($action==='inline-deliver') LLM\DrawingJob::inlineDeliver(new LLM\LLMManager(),$id);
elseif ($action==='inline-timeout') {
    $now=1000.0;
    LLM\DrawingJob::inlineDeliver(new LLM\LLMManager(),$id,static function() use (&$now): float { return $now; },static function() use (&$now): void { $now+=60; });
}
else throw new RuntimeException('Unknown synthetic probe');
echo "Probe PASS\n";
