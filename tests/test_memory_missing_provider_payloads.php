<?php
/** MLP-459 R1: exercise native provider serializers and manager retry paths without I/O. */
namespace Infra {
    final class ConfigManager {
        public static function getInstance():self{return new self();}
        public function getOption($key,$default=null){return ['ai_send_images'=>0,'ai_debug_log'=>0,'ai_reactions'=>0,'ai_max_tokens'=>64,'ai_evening_summary_enabled'=>0,'ai_return_memory_enabled'=>0][$key]??$default;}
    }
}
namespace Domain {
    final class LlmUsage {public static function record(array $row):void{}}
    final class UserManager {
        public function getUserById($id):array{return ['login'=>'SyntheticVoice','nickname'=>'SyntheticVoice'];}
        public function getAllUsers():array{return [];}
    }
}
namespace LLM {
    function curl_init($url){return (object)['url'=>$url,'options'=>[]];}
    function curl_setopt($handle,$key,$value){$handle->options[$key]=$value;return true;}
    function curl_exec($handle){
        if(str_contains($handle->url,'oauth'))return '{"access_token":"synthetic","expires_at":9999999999999}';
        $GLOBALS['recall_payloads'][]=['transport'=>'http','raw'=>$handle->options[CURLOPT_POSTFIELDS]];
        $answer=array_shift($GLOBALS['recall_answers']);if($answer instanceof \Throwable)throw $answer;
        return json_encode(['choices'=>[['message'=>['content'=>$answer],'finish_reason'=>'stop']], 'result'=>['alternatives'=>[['message'=>['text'=>$answer]]]],'usage'=>['prompt_tokens'=>1,'completion_tokens'=>1]]);
    }
    function curl_getinfo($handle,$key){return $key===CURLINFO_HTTP_CODE?200:0;}
    function curl_error($handle){return '';}
    function curl_close($handle){}
    function shell_exec($command){
        $GLOBALS['recall_payloads'][]=['transport'=>'cli','raw'=>$command];
        $answer=array_shift($GLOBALS['recall_answers']);if($answer instanceof \Throwable)throw $answer;return $answer;
    }
}
namespace {
require dirname(__DIR__).'/autoload.php';
$fail=0;$checks=0;
$ok=function($condition,$label)use(&$fail,&$checks){$checks++;if(!$condition)$fail++;echo($condition?'[OK] ':'[FAIL] ').$label."\n";};
$factories=[
 'RouterAI'=>fn()=>new LLM\RouterAIProvider('synthetic','synthetic'),
 'OpenAI'=>fn()=>new LLM\OpenAIProvider('synthetic','synthetic'),
 'OpenRouter'=>fn()=>new LLM\OpenRouterProvider('synthetic','synthetic'),
 'YandexFoundation'=>fn()=>new LLM\YandexGPTProvider('synthetic','synthetic'),
 'YandexOpenAI'=>fn()=>new LLM\YandexGPTProvider('synthetic','synthetic/deepseek-v32/latest'),
 'GigaChat'=>fn()=>new LLM\GigaChatProvider('synthetic'),
 'CLI'=>fn()=>new LLM\CliUtilityProvider('synthetic %s'),
];
$prompt='Синтетический системный промпт.';
$history=[['role'=>'user','content'=>'Синтетический вопрос «тест»?'],['role'=>'assistant','content'=>'Предыдущая реплика.']];
$final=['role'=>'user','content'=>'Ответь на текущий синтетический вопрос.'];$plain=[...$history,$final];
$reset=function($answers=['Синтетический ответ.']){$GLOBALS['recall_payloads']=[];$GLOBALS['recall_answers']=$answers;};
$legacyFile=__DIR__.'/fixtures/memory_missing_legacy_payloads.json';
if(in_array('--record-legacy',$argv,true)){
 $legacy=[];foreach($factories as$name=>$factory){$reset();$factory()->askChat($plain,$prompt);$legacy[$name]=$GLOBALS['recall_payloads'][0];}
 file_put_contents($legacyFile,json_encode($legacy,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");echo "Recorded seven native legacy payloads\n";exit;
}
$legacy=json_decode(file_get_contents($legacyFile),true,512,JSON_THROW_ON_ERROR);
$roles=function($payload){
 if($payload['transport']==='http')return array_column(json_decode($payload['raw'],true,512,JSON_THROW_ON_ERROR)['messages'],'role');
 preg_match_all('/^\[(user|assistant|system)\] /m',$payload['raw'],$m);return ['system',...$m[1]];
};
$strictProviders=['YandexFoundation','YandexOpenAI','GigaChat'];
$expectedContext=function($context){
 foreach($context as&$row)if(str_contains($row['content'],'Поиск исторической памяти'))$row['role']='system';
 unset($row);return $context;
};
$valid=function($payload,$context,$strict)use($roles,$expectedContext){
 $context=$expectedContext($context);if($strict)$context=array_filter($context,fn($row)=>$row['role']!=='system');
 return $roles($payload)===['system',...array_column($context,'role')];
};
$body=function($payload){return $payload['transport']==='http'?json_decode($payload['raw'],true,512,JSON_THROW_ON_ERROR)['messages']:null;};
$systemText=fn($message)=>$message['content']??$message['text'];
$beforeFinal=[...LLM\MemoryRecallBlock::missing($history,'deadline'),$final];
$afterFinal=LLM\MemoryRecallBlock::missing($plain,'deadline');
$multiple=[['role'=>'system','content'=>'Первое дополнительное правило.'],$history[0],['role'=>'system','content'=>'Второе дополнительное правило.'],$history[1],['role'=>'system','content'=>'Третье дополнительное правило.'],$final];
$contexts=['off'=>$plain,'before_final'=>$beforeFinal,'after_final'=>$afterFinal,'multiple_system'=>$multiple];
foreach($factories as$name=>$factory){
 $strict=in_array($name,$strictProviders,true);
 $reset();$factory()->askChat(LLM\MemoryRecallBlock::missing($plain,'ok'),$prompt);
 $ok($GLOBALS['recall_payloads'][0]===$legacy[$name],$name.' no-guidance payload matches captured legacy bytes');
 foreach($contexts as$order=>$context){
  $reset();$original=serialize($context);$factory()->askChat($context,$prompt);$payload=$GLOBALS['recall_payloads'][0];
  $ok($valid($payload,$context,$strict),$name.' '.$order.' respects the provider system-role contract');
  $ok(serialize($context)===$original,$name.' '.$order.' leaves input context intact');
  if(in_array($order,['before_final','after_final'],true)){$text=$payload['transport']==='http'?json_encode(json_decode($payload['raw'],true),JSON_UNESCAPED_UNICODE):$payload['raw'];$ok(substr_count($text,'Поиск исторической памяти')===1,$name.' '.$order.' retains guidance exactly once');}
  if($payload['transport']==='http'){
   $messages=$body($payload);$expected=$expectedContext($context);$systems=array_values(array_filter($expected,fn($row)=>$row['role']==='system'));
   if($strict){
    $merged=$prompt;foreach($systems as$row)$merged.="\n\n".$row['content'];
    $ok($systemText($messages[0])===$merged,$name.' '.$order.' appends all system contents to the initial prompt in order');
   }else{
    $actual=array_values(array_filter($messages,fn($row)=>$row['role']==='system'));
    $ok(array_map($systemText,$actual)===[$prompt,...array_column($systems,'content')],$name.' '.$order.' keeps the original system messages unchanged');
   }
   $actual=array_values(array_filter($messages,fn($row)=>$row['role']!=='system'));$expected=array_values(array_filter($expected,fn($row)=>$row['role']!=='system'));
   $ok(array_map($systemText,$actual)===array_column($expected,'content'),$name.' '.$order.' preserves all non-system contents and their order');
  }
 }
 // The real manager selects providers and performs its language retry; only transport is fake.
 foreach(['off'=>$plain,'on'=>$beforeFinal]as$state=>$context)foreach(['primary','fallback','language_retry']as$path){
  $reset($path==='fallback'?[new RuntimeException('Synthetic permanent failure'),'Синтетический ответ.']:($path==='language_retry'?['Случайные 悔 слова.','Синтетический ответ.']:['Синтетический ответ.']));
  $manager=(new ReflectionClass(LLM\LLMManager::class))->newInstanceWithoutConstructor();
  $chain=$path==='fallback'?[new LLM\RouterAIProvider('synthetic','synthetic'),$factory()]:[$factory()];
  foreach(['botUserId'=>1,'personaPromptCache'=>$prompt,'providers'=>$chain,'fastProviders'=>$chain]as$key=>$value)(new ReflectionProperty($manager,$key))->setValue($manager,$value);
  $answer=$manager->generateReply($context);$captured=$GLOBALS['recall_payloads'];
  $ok($answer==='Синтетический ответ.'&&count($captured)===($path==='primary'?1:2),$name.' '.$state.' '.$path.' executes native path and call bound');
  $validAttempts=true;foreach($captured as$index=>$payload){$attemptStrict=$path==='fallback'&&$index===0?false:$strict;$validAttempts=$validAttempts&&$valid($payload,$context,$attemptStrict);}
  $ok($validAttempts,$name.' '.$state.' '.$path.' preserves the provider role contract on every attempt');
  if($strict&&$state==='on'){$last=end($captured);$messages=$body($last);$note=$expectedContext([$beforeFinal[count($history)]])[0]['content'];$ok(str_ends_with($systemText($messages[0]),"\n\n".$note),$name.' '.$state.' '.$path.' retains guidance at the end of the initial system');}
 }
}
$guidance=end($afterFinal);$ok($guidance['role']==='system'&&str_starts_with($guidance['content'],'Поиск исторической памяти'),'guidance restores the original system role and wording');
$summary=LLM\ConversationMemoryPolicy::SUMMARY_MARKER.' Синтетическая беседа';$returned=LLM\ConversationMemoryPolicy::RETURN_MARKER."\n".'{"anchor":"Синтетический эпизод"}';
$composed=LLM\ConversationMemoryPolicy::apply($beforeFinal,[$summary,$returned],8000);$focused=LLM\ReturnMemory::focus($composed,false,['summary'=>$summary,'return'=>$returned]);
$ok(in_array($guidance,$composed,true)&&in_array($guidance,$focused,true),'summary composition and return focus preserve the system guidance');
echo "$checks checks, $fail failures\n";echo$fail?"FAIL\n":"ALL PASS\n";exit((int)($fail>0));
}
