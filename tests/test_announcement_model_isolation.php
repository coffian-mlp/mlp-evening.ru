<?php
namespace Infra {
    class ConfigManager {
        public static function getInstance(): self { return new self(); }
        public function getOption($key,$default=null) { return $default; }
    }
}
namespace Domain {
    class AnnouncementStore { public function nextNumber(): int { return 179; } }
}
namespace LLM {
    class LyraMemory {
        public function __construct(private LLMManager $llm) {}
        public function buildPromptBlock(array $audience): string {
            $this->llm->log->calls[]=['memory',$this->llm->profile];return 'Fixture memory';
        }
    }
    class AnnouncementSynopsis {
        public static function generate(LLMManager $llm,array $stories): array {
            $llm->log->calls[]=['synopsis',$llm->profile];
            return [['episode_id'=>1,'title'=>'Fixture episode','summary'=>'Сюжет серии.','source'=>'https://example.org/episode']];
        }
    }
}
namespace {
require_once __DIR__.'/../autoload.php';
$fail=0;
function checkIsolation($ok,$label){global $fail;echo ($ok?'[OK] ':'[FAIL] ').$label."\n";if(!$ok)$fail++;}
class IsolationModel extends LLM\LLMManager {
    public string $profile='main';
    public object $log;
    public function __construct() { $this->log=(object)['calls'=>[],'clones'=>0]; }
    public function forAnnouncements(): LLM\LLMManager {
        $this->log->clones++;$copy=clone $this;$copy->profile='announcements';return $copy;
    }
    public function liveTextBounded(string $instruction,?string $mustContain,int $deadlineSec,int $timeoutSec=10,?string $trustedTask=null,?array $actionContext=null):?string {
        $editor=str_contains($instruction,'результат работы редактора');
        $this->log->calls[]=[$editor?'editor':'post',$this->profile];
        return $editor?'Статус обновлён.':'Я жду вас 17.10.2026 19:00 МСК на https://mlp-evening.ru/';
    }
}
class IsolationContent extends LLM\AnnouncementContent {
    protected function firstPublication(): bool { return false; }
    public function memory(): string { return $this->communityMemory(); }
}
$facts=['occurrence'=>['real_start_time'=>strtotime('2026-10-17 16:00:00 UTC')],'snapshot'=>['stories'=>[]],'chat'=>[]];
foreach (['editor-first','memory-first','writer-first'] as $order) {
    $model=new IsolationModel();$content=new IsolationContent($model);
    if($order==='editor-first')checkIsolation($content->editorText('Статус обновлён.')==='Статус обновлён.','editor reply remains available before writing');
    if($order==='memory-first')$content->memory();
    checkIsolation($content->generateText($facts,'main',179)!==null,$order.': main announcement accepted');
    $content->editorText('Статус обновлён.');$content->memory();
    checkIsolation($content->generateText($facts,'reminder',179)!==null,$order.': reminder accepted after editor/memory work');
    foreach($model->log->calls as [$task,$profile]) {
        $expected=in_array($task,['post','synopsis'],true)?'announcements':'main';
        checkIsolation($profile===$expected,$order.': '.$task.' uses '.$expected.' profile');
    }
    checkIsolation($model->profile==='main' && $model->log->clones===1,$order.': base unchanged and writer reused');
}
echo $fail?"FAIL: $fail\n":"ALL PASS\n";exit($fail?1:0);
}
