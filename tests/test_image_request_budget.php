<?php
namespace Infra {
    final class ConfigManager {
        public static function getInstance():self{return new self();}
        public function getOption($key,$default=null){return ['ai_image_provider'=>'openai','ai_openai_key'=>'synthetic','ai_openai_base_url'=>'https://example.invalid/v1/chat/completions','ai_debug_log'=>0][$key]??$default;}
    }
}
namespace LLM {
    // Intercept the transport boundary; no network or generated-file side effects.
    function curl_init($url){return (object)['url'=>$url,'options'=>[]];}
    function curl_setopt_array($handle,$options){$handle->options=$options;$GLOBALS['image_budget_options']=$options;return true;}
    function curl_exec($handle){$GLOBALS['image_budget_called']=true;return '{"error":{"message":"synthetic failure"}}';}
    function curl_getinfo($handle,$option){return 400;}
    function curl_error($handle){return '';}
    function curl_close($handle){}
}
namespace {
require dirname(__DIR__).'/autoload.php';
$path=tempnam(sys_get_temp_dir(),'image_budget_');$previous=ini_set('error_log',$path);$usage=[];
try{
 $result=Domain\LlmUsage::withoutDatabase(fn()=>LLM\RequestDeadline::within(microtime(true)-1,fn()=>LLM\ImageGenerator::generate('Synthetic test')),function($row)use(&$usage){$usage[]=$row;});
 $checks=[
  'native image transport uses a 150 second timeout'=>($GLOBALS['image_budget_options'][CURLOPT_TIMEOUT]??0)===150,
  'an expired text-request scope does not cut off the separate image request'=>!empty($GLOBALS['image_budget_called']),
  'fake failed request is accounted once without persistence'=>$result===null&&count($usage)===1,
 ];
 $fail=0;foreach($checks as$label=>$pass){echo($pass?'[OK] ':'[FAIL] ').$label."\n";if(!$pass)$fail++;}
}finally{ini_set('error_log',$previous);unlink($path);}
echo$fail?"FAIL\n":"ALL PASS\n";exit((int)($fail>0));
}
