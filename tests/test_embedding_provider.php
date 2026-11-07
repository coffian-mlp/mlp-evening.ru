<?php
require_once __DIR__.'/../autoload.php';
use LLM\RouterAIEmbeddingProvider;
use LLM\MemoryAssociations;
$fail=0;
function embeddingCheck(bool $ok,string $label):void{global $fail;echo($ok?'[OK] ':'[FAIL] ').$label."\n";if(!$ok)$fail++;}
$meter=[];$payloads=[];
$body=['data'=>[['index'=>1,'embedding'=>array_fill(0,512,2)],['index'=>0,'embedding'=>array_fill(0,512,1)]],'usage'=>['prompt_tokens'=>12,'total_tokens'=>12]];
$transport=function($payload,$timeout)use(&$body,&$payloads){$payloads[]=$payload;embeddingCheck($timeout>0&&$timeout<=1000,'absolute millisecond timeout');return[json_encode($body),200,false];};
$provider=new RouterAIEmbeddingProvider('fake',null,$transport,function(...$args)use(&$meter){$meter[]=$args;});
$vectors=$provider->embed(['one','two'],microtime(true)+1);
embeddingCheck(count($vectors)===2&&abs(array_sum(array_map(fn($x)=>$x*$x,$vectors[0]))-1)<1e-10,'ordered normalized vectors');
embeddingCheck($payloads[0]['dimensions']===512&&$payloads[0]['model']===RouterAIEmbeddingProvider::MODEL,'fixed embedding profile');
embeddingCheck(count($meter)===1&&json_decode($meter[0][0],true)['usage']['prompt_tokens']===12,'one usage record per HTTP attempt');
foreach(['duplicate','short','zero','error','malformed']as$case){
 $body=['data'=>[['index'=>0,'embedding'=>array_fill(0,512,1)],['index'=>1,'embedding'=>array_fill(0,512,1)]]];
 if($case==='duplicate')$body['data'][1]['index']=0;
 if($case==='short')array_pop($body['data'][1]['embedding']);
 if($case==='zero')$body['data'][1]['embedding']=array_fill(0,512,0);
 if($case==='error')$body['error']=['message'=>'do not expose'];
 if($case==='malformed')$body=['data'=>[]];
 try{$provider->embed(['one','two'],microtime(true)+1);embeddingCheck(false,$case.' rejected');}catch(RuntimeException $e){embeddingCheck(!str_contains($e->getMessage(),'do not expose'),$case.' rejected safely');}
}
$before=count($payloads);try{$provider->embed(['one'],microtime(true)-1);embeddingCheck(false,'expired deadline rejected');}catch(RuntimeException $e){embeddingCheck(count($payloads)===$before,'expired deadline makes no HTTP attempt');}
$associations=new MemoryAssociations(function($text,$prompt,$deadline){embeddingCheck(str_contains($prompt,'DL-6'),'association prompt contains cultural expansion task');return "Феникс Райт, Ace Attorney\nадвокат";},'fake-model');
embeddingCheck($associations->generate('DL-6',microtime(true)+1)==='Феникс Райт, Ace Attorney адвокат','association line normalized');
embeddingCheck($associations->profile()!==(new MemoryAssociations(fn()=>'', 'other-model'))->profile(),'association model contributes to profile');
$measurement=LLM\UsageMeter::measurement('routerai',RouterAIEmbeddingProvider::MODEL,['usage'=>['prompt_tokens'=>7,'total_tokens'=>7]],200,false,20,'memory_embedding_index');
embeddingCheck($measurement['input_tokens']===7&&$measurement['output_tokens']===null&&$measurement['total_tokens']===7,'embedding usage does not invent output tokens');
// The association entry point uses only the configured cheap profile and a dedicated prompt.
$configReflection = new ReflectionClass(Infra\ConfigManager::class);
$config = $configReflection->newInstanceWithoutConstructor();
$configReflection->getProperty('cache')->setValue($config, ['ai_enabled'=>'1', 'ai_fast_model'=>'cheap-model']);
$configReflection->getProperty('instance')->setValue(null, $config);
$events = new ArrayObject();
$cheap = new class('fake', 'cheap-model', null, null, $events) extends LLM\RouterAIProvider {
    public function __construct($key, $model, $proxy, $tokens, private ArrayObject $events) { parent::__construct($key, $model, $proxy, $tokens); }
    public function askChat(array $context, string $prompt): ?string {
        $this->events[] = ['context'=>$context, 'prompt'=>$prompt, 'task'=>LLM\UsageMeter::task()];
        return 'Ace Attorney, Феникс Райт';
    }
};
$primary = new class implements LLM\LLMProviderInterface {
    public function askChat(array $context, string $prompt): ?string { throw new RuntimeException('Primary must not be called'); }
};
$managerReflection = new ReflectionClass(LLM\LLMManager::class);
$manager = $managerReflection->newInstanceWithoutConstructor();
$managerReflection->getProperty('botUserId')->setValue($manager, 12);
$managerReflection->getProperty('providers')->setValue($manager, [$primary]);
$managerReflection->getProperty('fastProviders')->setValue($manager, [$cheap, $primary]);
$reply = $manager->generateMemoryAssociations('DL-6', LLM\MemoryAssociations::PROMPT, microtime(true)+5);
embeddingCheck($reply==='Ace Attorney, Феникс Райт' && count($events)===1, 'association generation calls only the cheap provider');
embeddingCheck(count($events[0]['context'])===1 && $events[0]['task']==='memory_associations', 'association utility has isolated context and usage task');
embeddingCheck(str_contains($manager->memoryAssociationProfile(), 'cheap-model'), 'association model identity participates in profile');
$configReflection->getProperty('cache')->setValue($config, ['ai_enabled'=>'1', 'ai_fast_model'=>'']);
embeddingCheck($manager->generateMemoryAssociations('DL-6', LLM\MemoryAssociations::PROMPT, microtime(true)+5)===null && count($events)===1, 'missing cheap profile does not fall back to the main model');
echo$fail?"FAILED: $fail\n":"ALL PASS\n";exit($fail?1:0);
