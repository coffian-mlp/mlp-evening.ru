<?php
namespace Infra {
    class ConfigManager {
        public static array $options=['ai_send_images'=>1,'ai_main_is_vision'=>0];
        public static function getInstance(): self { return new self(); }
        public function getOption($key,$default=null) { return self::$options[$key]??$default; }
    }
}
namespace Domain { class ChatManager {} class StickerManager { public function getVisionMap(): array { return []; } } }
namespace LLM {
    function curl_init($url) {return (object)['options'=>[]];}
    function curl_setopt($ch,$option,$value) {$ch->options[$option]=$value;return true;}
    function curl_exec($ch) {$GLOBALS['vision_payload']=json_decode($ch->options[CURLOPT_POSTFIELDS],true);return '{"choices":[{"message":{"content":"Ответ"},"finish_reason":"stop"}]}';}
    function curl_getinfo($ch,$option) {return 200;}
    function curl_error($ch) {return '';}
    function curl_close($ch) {}
}
namespace {
require_once __DIR__.'/../autoload.php';
$failed=0;function check($ok,$label){global $failed;echo ($ok?'[OK] ':'[FAIL] ').$label."\n";if(!$ok)$failed++;}
Infra\ConfigManager::$options += ['ai_primary_provider'=>'routerai','ai_routerai_key'=>'fixture','ai_routerai_model'=>'text-main', 'ai_openai_key'=>'fixture','ai_fast_model'=>'vision-fast','ai_fast_is_vision'=>1];
$manager=new LLM\LLMManager();
$main=(new ReflectionProperty($manager,'providers'))->getValue($manager);
$fast=(new ReflectionProperty($manager,'fastProviders'))->getValue($manager);
$vision=static fn($provider)=>(new ReflectionProperty($provider,'vision'))->getValue($provider);
check($vision($main[0])===false && $vision($fast[0])===true,'actual manager routes main and fast with independent capabilities');
check($vision($fast[1])===false && $vision($main[1])===false,'fallback preserves own conservative capability');
Infra\ConfigManager::$options['ai_fast_model']='';
$manager=new LLM\LLMManager();
check((new ReflectionProperty($manager,'providers'))->getValue($manager)===(new ReflectionProperty($manager,'fastProviders'))->getValue($manager),'missing fast model uses main chain and capability');
$url='https://example.com/vision-routing-'.bin2hex(random_bytes(8)).'.png';
$cache=new Core\FileCache('vision');$cache->set(md5($url),['d'=>'Cached image description','url'=>$url]);
$context=[['role'=>'user','content'=>'Look ![image]('.$url.')','chat_message_id'=>123]];
try {
 foreach([LLM\RouterAIProvider::class,LLM\OpenAIProvider::class,LLM\OpenRouterProvider::class] as $class){
    $provider=new $class('fixture key','fixture model');
    $provider->withVision(true)->withTimeout(10)->askChat($context,'System');$direct=$GLOBALS['vision_payload']['messages'][1];
    check(is_array($direct['content']) && end($direct['content'])['type']==='image_url',$class.' vision copy receives actual image despite global flag');
    check(!isset($direct['chat_message_id']),$class.' internal target ID not leaked as API field');
    $provider->withVision(false)->askChat($context,'System');$text=$GLOBALS['vision_payload']['messages'][1]['content'];
    check(is_string($text) && str_contains($text,'[Картинка: Cached image description]') && !str_contains($text,'!['),$class.' nonvision copy replaces image from cache');
    check(str_contains($context[0]['content'],'!['),'original context preserved for next provider');
 }
}finally{$cache->delete(md5($url));}
echo $failed?"FAIL: $failed\n":"ALL PASS\n";exit($failed?1:0);
}
