<?php
namespace Infra {
    class ConfigManager {
        public static array $options = ['ai_send_images'=>1,'ai_main_is_vision'=>0,'ai_vision_provider'=>'routerai','ai_routerai_key'=>'fixture','ai_vision_model'=>'vision-fixture'];
        public static function getInstance(): self { return new self(); }
        public function getOption($key,$default=null) { return self::$options[$key] ?? $default; }
    }
}
namespace Domain { class StickerManager { public function getVisionMap(): array { return []; } } }
namespace LLM {
    class LlmDebugLog { public static function log(...$arguments): void {} }
    function curl_init($url) { return (object)['url'=>$url,'options'=>[]]; }
    function curl_setopt($ch,$key,$value) { $ch->options[$key]=$value;return true; }
    function curl_exec($ch) {
        $data=json_decode($ch->options[CURLOPT_POSTFIELDS]??'{}',true);
        $vision=($data['model']??'')==='vision-fixture';
        $auth=str_contains($ch->url,'/oauth');
        $GLOBALS['calls'][]=['vision'=>$vision,'auth'=>$auth,'timeout'=>$ch->options[CURLOPT_TIMEOUT_MS]];
        if ($vision) $GLOBALS['now'] += $GLOBALS['vision_duration'];
        if ($auth) { $GLOBALS['now'] += 1;return json_encode(['access_token'=>'fixture','expires_at'=>(time()+3600)*1000]); }
        return '{"choices":[{"message":{"content":"reply"},"finish_reason":"stop"}],"result":{"alternatives":[{"message":{"text":"reply"},"status":"ALTERNATIVE_STATUS_FINAL"}]}}';
    }
    function curl_getinfo($ch,$key) { return 200; }
    function curl_error($ch) { return ''; }
    function curl_close($ch) {}
}
namespace {
require_once __DIR__.'/../autoload.php';
$fail=0;
function checkProviderBudget($value,$label){global $fail;echo ($value?'[OK] ':'[FAIL] ').$label."\n";if(!$value)$fail++;}
$cache=new Core\FileCache('vision');$keys=[];
$clock=static fn():float => $GLOBALS['now'];
try {
 foreach ([LLM\OpenAIProvider::class,LLM\OpenRouterProvider::class,LLM\RouterAIProvider::class,LLM\YandexGPTProvider::class,LLM\GigaChatProvider::class] as $class) {
    foreach ([7,11] as $duration) {
        $GLOBALS['now']=100.0;$GLOBALS['calls']=[];$GLOBALS['vision_duration']=$duration;
        $url='https://example.com/budget-'.bin2hex(random_bytes(8)).'.png';$keys[]=md5($url);
        $provider= match ($class) {
            LLM\YandexGPTProvider::class => new $class('fixture','fixture-folder'),
            LLM\GigaChatProvider::class => new $class('fixture'),
            default => new $class('fixture','main-fixture')
        };
        $failed=false;
        try { LLM\RequestDeadline::within(110,fn()=>$provider->askChat([['role'=>'user','content'=>'![picture]('.$url.')']],'system'),$clock); }
        catch (RuntimeException $e) { $failed=true; }
        $calls=$GLOBALS['calls'];
        if ($duration===7) {
            $main=array_values(array_filter($calls,fn($c)=>!$c['vision']&&!$c['auth']));
            $expected=$class===LLM\GigaChatProvider::class?2000:3000;
            checkProviderBudget(!$failed && count($main)===1 && $main[0]['timeout']===$expected,$class.' main transport uses remainder after vision/OAuth');
            checkProviderBudget($calls[0]['vision'] && $calls[0]['timeout']===10000,$class.' vision inherits same absolute deadline');
        } else {
            checkProviderBudget($failed && count($calls)===1 && $calls[0]['vision'],$class.' exhausted vision prevents OAuth/main HTTP request');
            checkProviderBudget($cache->get(md5($url).'_fail',LLM\VisionDescriber::FAIL_TTL)===null,'deadline does not poison image failure cache');
        }
        checkProviderBudget(LLM\RequestDeadline::at()===null,'provider scope released');
    }
 }
} finally { foreach($keys as $key){$cache->delete($key);$cache->delete($key.'_fail');} }
echo $fail?"FAIL: $fail\n":"ALL PASS\n";exit($fail?1:0);
}
