<?php
namespace LLM {
    function time(): int { return $GLOBALS['audit_clock']; }
    function sleep(int $seconds): int { $GLOBALS['audit_clock'] += $seconds; return 0; }
}
namespace {
require __DIR__.'/integration_helpers.php';
if(PHP_SAPI!=='cli'||!is_file('/.dockerenv')||(it_config()['db']['host']??'')!=='db')it_skip('Requires isolated Docker DB_HOST=db');
$lockDb=it_require_db();$db=Infra\Database::getInstance()->getConnection();$db->begin_transaction();$GLOBALS['audit_clock']=time();
try {
    $config=Infra\ConfigManager::getInstance();$config->setOption('ai_use_queue','0');$config->setOption('ai_worker_mode','inline');
    if(!(int)$lockDb->query("SELECT GET_LOCK('bot_drawing',0) n")->fetch_assoc()['n'])throw new RuntimeException('Fixture lock busy');
    $before=(int)$db->query('SELECT COALESCE(MAX(id),0) n FROM llm_jobs')->fetch_assoc()['n'];$ran=0;
    $payload=['command'=>['handler_type'=>'image'],'message'=>'/draw separate request','username'=>'Drawing Requester'];
    $llm=new class extends LLM\LLMManager {
        public array $notices=[];
        public function __construct() {}
        public function botSayLive(string $instruction,string $fallback,array $quotedIds=[],?string $mustContain=null) { $this->notices[]=compact('instruction','fallback','mustContain');return true; }
    };
    (new ReflectionMethod(LLM\BotDispatch::class,'inlineDrawing'))->invoke(null,$payload,0,static function()use(&$ran){return LLM\DrawingSchedule::run(false,static function()use(&$ran){$ran++;return true;});},$llm);
    $row=$db->query('SELECT status FROM llm_jobs WHERE id>'.$before.' ORDER BY id LIMIT 1')->fetch_assoc();
    echo 'Elapsed virtual waiting: '.($GLOBALS['audit_clock']-time())." seconds\n";
    echo 'Queue mode: '.(LLM\BotDispatch::shouldQueue()?'queued':'inline').'; executor calls: '.$ran.'; final job status: '.$row['status']."\n";
    check($row['status']==='failed','exhausted inline request is terminal instead of waiting for a nonexistent worker');
    check($ran===0,'busy request never calls image generation');
    check(count($llm->notices)===1 && $llm->notices[0]['mustContain']==='@Drawing Requester','inline timeout notifies its requester once through live speech');
    check(str_contains($llm->notices[0]['fallback']??'', 'Повтори'),'failure fallback gives an actionable retry');
    $lockDb->query("SELECT RELEASE_LOCK('bot_drawing')");
    $llm->notices=[];$before=(int)$db->query('SELECT MAX(id) n FROM llm_jobs')->fetch_assoc()['n'];
    (new ReflectionMethod(LLM\BotDispatch::class,'inlineDrawing'))->invoke(null,$payload,0,static function()use(&$ran){$ran++;return true;},$llm);
    $row=$db->query('SELECT status FROM llm_jobs WHERE id>'.$before.' ORDER BY id LIMIT 1')->fetch_assoc();
    check($row['status']==='done' && $ran===1 && !$llm->notices,'uncontended inline request completes without a timeout notice');
}finally{
    $lockDb->query("SELECT RELEASE_LOCK('bot_drawing')");$lockDb->close();$db->rollback();Infra\ConfigManager::getInstance()->flushCache();
}
it_done();
}
