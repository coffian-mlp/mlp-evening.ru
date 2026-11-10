<?php
require_once dirname(__DIR__,2).'/autoload.php';
if(!is_file('/.dockerenv')||Infra\Env::get('DB_NAME')!=='mlp_phase3')throw new RuntimeException('Synthetic replay probe only');
$db=Infra\Database::getInstance()->getConnection();$state=json_decode(file_get_contents(dirname(__DIR__,2).'/reviewfix-replay-state.json'),true,512,JSON_THROW_ON_ERROR);
$before=(int)$db->query('SELECT COUNT(*) FROM llm_usage')->fetch_row()[0];$GLOBALS['replayRO']=false;
$GLOBALS['phase3ReplayFake']=new class extends LLM\LLMManager{public function generateConversationSummary(string $p,float $d):?string{
 LLM\UsageMeter::capture('routerai','z-ai/glm-5.3-flashx',json_encode(['usage'=>['prompt_tokens'=>1000,'completion_tokens'=>200]]),200,false,1,'evening_summary');
 try{Infra\Database::getInstance()->getConnection()->query("INSERT INTO site_options(key_name,value) VALUES('it_replay_should_not_write','synthetic') ON DUPLICATE KEY UPDATE value='synthetic'");}catch(mysqli_sql_exception $e){$GLOBALS['replayRO']=$e->getCode()===1792;}
 if(getenv('MLP_REPLAY_FAKE_FAIL')==='1')throw new RuntimeException('SYNTHETIC_PRIVATE_CANARY');
 return 'Синтетический результат без записи.';
}};
$code=file_get_contents(dirname(__DIR__).'/replay_conversation_summary.php');
$code=preg_replace('/\$ranges=\[.*?;/',"\$ranges=['2026-11-07'=>[{$state['first']},{$state['last']}]];",$code,1);
$code=str_replace('$llm=new LLM\\LLMManager();',"\$llm=\$GLOBALS['phase3ReplayFake'];",$code,$replaced);if($replaced!==1)throw new RuntimeException('Replay factory seam not found');
$code=preg_replace("~require_once dirname\(__DIR__\)\.'/autoload.php';~","require_once '/var/www/html/autoload.php';",$code,1);
$file=dirname(__DIR__,2).'/reviewfix-replay-copy.php';file_put_contents($file,$code);putenv('MLP_ALLOW_SUMMARY_REPLAY=1');
try{require$file;}finally{unlink($file);}
$after=(int)$db->query('SELECT COUNT(*) FROM llm_usage')->fetch_row()[0];
if($after!==$before||!$GLOBALS['replayRO']){echo"REPLAY_READ_ONLY_FAIL\n";exit(1);}echo"REPLAY_READ_ONLY_PASS\n";
