<?php
namespace LLM {
    function curl_init($url=null){return new \stdClass();}
    function curl_setopt($handle,$option,$value){return true;}
    function curl_exec($handle){
        $GLOBALS['usage_attempts']++;
        return $GLOBALS['usage_attempts']===1?'{"error":{"message":"temporary"}}':json_encode(['choices'=>[['message'=>['content'=>'Ответ'],'finish_reason'=>$GLOBALS['usage_truncated']?'length':'stop']],'usage'=>['prompt_tokens'=>10,'completion_tokens'=>5,'total_tokens'=>15]]);
    }
    function curl_getinfo($handle,$option){return $option===CURLINFO_HTTP_CODE?($GLOBALS['usage_attempts']===1?503:200):.1;}
    function curl_error($handle){return '';}
    function curl_close($handle){}
}
namespace {
require __DIR__.'/integration_helpers.php';it_require_db();
$db=Infra\Database::getInstance()->getConnection();$config=Infra\ConfigManager::getInstance();
$model='it_usage_'.bin2hex(random_bytes(6));$old=$config->getOption('ai_usage_rates',null);$debug=$config->getOption('ai_debug_log',null);
$fail=0;$check=static function($v,$text)use(&$fail){echo ($v?'[OK] ':'[FAIL] ').$text."\n";if(!$v)$fail++;};
try{
    (new Domain\LlmUsage())->saveRates([['provider'=>'routerai','model'=>$model,'input'=>1,'output'=>2,'currency'=>'USD']]);$config->setOption('ai_debug_log','0');
    $GLOBALS['usage_attempts']=0;$GLOBALS['usage_truncated']=false;
    $provider=(new LLM\RouterAIProvider('test-key',$model))->withVision(false);
    $result=LLM\UsageMeter::ask('memory',$provider,[['role'=>'user','content'=>'Technical fixture']],'Test');
    $s=$db->prepare('SELECT * FROM llm_usage WHERE model=? ORDER BY id');$s->bind_param('s',$model);$s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);
    $check($result==='Ответ' && count($rows)===2,'actual provider retry records two attempts without changing answer');
    $check($rows[0]['status']==='error' && $rows[0]['input_tokens']===null && $rows[1]['input_tokens']==10,'failed attempt usage remains unknown');
    $check($rows[1]['task']==='memory' && abs((float)$rows[1]['estimated_cost']-.00002)<.00000001,'task scope and configured estimate captured with debug disabled');
    $GLOBALS['usage_truncated']=true;
    try{LLM\UsageMeter::ask('chat',$provider,[],'Test');$check(false,'truncation still throws');}catch(LLM\TruncatedResponseException $e){$check(true,'truncation still throws');}
    $db->begin_transaction();LLM\UsageMeter::capture('routerai',$model,'{"usage":{"prompt_tokens":0,"completion_tokens":0}}',200,false,1,'utility');$db->rollback();
    $s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);
    $check(count($rows)===4 && $rows[2]['status']==='truncated' && $rows[3]['total_tokens']==0,'meter survives caller rollback and counts truncated usage');
    $check(!array_intersect(['user_id','request','response','prompt','api_key'],array_keys($rows[0])),'operational table excludes identity and text');
    $config->setOption('ai_usage_rates','[{"provider":"invalid","model":"invalid"}]');
    $GLOBALS['usage_truncated']=false;
    $check(LLM\UsageMeter::ask('chat',$provider,[],'Test')==='Ответ','accounting failure does not change a provider answer');
    $db->query('UPDATE llm_usage SET created_at=UTC_TIMESTAMP()-INTERVAL 91 DAY WHERE id='.(int)$rows[0]['id']);
    (new Domain\LlmUsage())->purgeExpired();
    $s->execute();$remaining=$s->get_result()->fetch_all(MYSQLI_ASSOC);
    $check(count($remaining)===3,'bounded retention removes old attempts independently of new requests');
}finally{
    $s=$db->prepare('DELETE FROM llm_usage WHERE model=?');$s->bind_param('s',$model);$s->execute();
    foreach(['ai_usage_rates'=>$old,'ai_debug_log'=>$debug] as $key=>$value){if($value!==null)$config->setOption($key,$value);else{$s=$db->prepare('DELETE FROM site_options WHERE key_name=?');$s->bind_param('s',$key);$s->execute();}}$config->flushCache();
}
echo $fail?"FAIL: $fail\n":"ALL PASS\n";exit($fail?1:0);
}
