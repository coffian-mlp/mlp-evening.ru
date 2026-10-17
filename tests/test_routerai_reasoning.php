<?php
namespace Infra {
    class ConfigManager {
        public static array $options = ['ai_primary_provider'=>'routerai', 'ai_routerai_key'=>'fixture',
            'ai_routerai_model'=>'fixture-main', 'ai_fast_model'=>'fixture-fast', 'ai_vision_model'=>'fixture-vision'];
        public static function getInstance(): self { return new self(); }
        public function getOption($key, $default = null) { return self::$options[$key] ?? $default; }
    }
}
namespace Domain {
    class ChatManager {}
    class StickerManager { public function getVisionMap(): array { return []; } }
    class LlmUsage { public static function record(array $row): void {} }
}
namespace LLM {
    class LlmDebugLog { public static function log(...$args): void {} }
    function curl_init($url) { return (object)['options'=>[]]; }
    function curl_setopt($ch, $key, $value) { $ch->options[$key]=$value; return true; }
    function curl_exec($ch) {
        $GLOBALS['reasoning_payload']=json_decode($ch->options[CURLOPT_POSTFIELDS],true);
        return '{"choices":[{"finish_reason":"stop","message":{"content":"Готово"}}]}';
    }
    function curl_getinfo($ch, $key) { return 200; }
    function curl_error($ch) { return ''; }
    function curl_close($ch) {}
}
namespace {
    require_once __DIR__.'/../autoload.php';
    $fail=0;
    function check($ok, $label) { global $fail; echo ($ok?'PASS ':'FAIL ').$label."\n"; if (!$ok) $fail++; }
    $ask = static function ($provider): array {
        $provider->askChat([['role'=>'user','content'=>'Fixture']], 'Fixture');
        return $GLOBALS['reasoning_payload'];
    };
    foreach (['auto','low','high','max','invalid'] as $effort) {
        Infra\ConfigManager::$options['ai_routerai_reasoning_effort']=$effort;
        $manager=new LLM\LLMManager();
        $main=(new ReflectionProperty($manager,'providers'))->getValue($manager);
        $fast=(new ReflectionProperty($manager,'fastProviders'))->getValue($manager);
        foreach (['main'=>$main[0], 'fast'=>$fast[0]] as $kind=>$provider) {
            $payload=$ask($provider);
            check(in_array($effort,['auto','invalid'],true) ? !isset($payload['reasoning']) : $payload['reasoning']===['effort'=>$effort], $kind.' actual request applies '.$effort);
            check(!isset($payload['plugins']) && $payload['max_tokens']===LLM\TokenBudget::DEFAULT, 'reasoning preserves unrelated request settings');
        }
    }
    unset(Infra\ConfigManager::$options['ai_routerai_reasoning_effort']);
    check(!isset($ask((new LLM\RouterAIProvider('fixture'))->withConfiguredReasoning())['reasoning']), 'missing setting preserves model default');
    Infra\ConfigManager::$options['ai_routerai_reasoning_effort']='high';
    $configured=(new LLM\RouterAIProvider('fixture'))->withConfiguredReasoning();
    check($ask($configured->withReasoningEffort('low'))['reasoning']===['effort'=>'low'], 'bounded clone can keep low reasoning');
    check($ask($configured)['reasoning']===['effort'=>'high'], 'bounded clone does not mutate configured provider');
    check(!isset($ask($configured->withReasoningEffort('auto'))['reasoning']), 'auto clears explicit reasoning');
    Infra\ConfigManager::$options['ai_routerai_reasoning_effort']='low';
    (new ReflectionMethod(LLM\VisionDescriber::class,'callModel'))->invoke(null,'data:image/png;base64,fixture');
    check($GLOBALS['reasoning_payload']['reasoning']===['effort'=>'low'] && $GLOBALS['reasoning_payload']['model']==='fixture-vision', 'vision helper applies same configured effort');
    $keys=(new ReflectionClassConstant(Api\SettingsController::class,'KEYS'))->getValue();
    check($keys['ai_routerai_reasoning_effort']===['enum',LLM\RouterAIProvider::REASONING_EFFORTS], 'settings accept only supported effort values');
    echo $fail?"FAILURES: $fail\n":"ALL PASS\n";
    exit($fail?1:0);
}
