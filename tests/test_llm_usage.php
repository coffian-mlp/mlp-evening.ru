<?php
require __DIR__.'/../autoload.php';
use LLM\UsageMeter;
use Domain\LlmUsage;
$fail=0;$check=static function($v,$text)use(&$fail){echo ($v?'[OK] ':'[FAIL] ').$text."\n";if(!$v)$fail++;};
$normalize=static fn($body)=>UsageMeter::measurement('RouterAI','model',$body,200,false,10,'chat');
$unknown=$normalize(['choices'=>[]]);$zero=$normalize(['usage'=>['prompt_tokens'=>0,'completion_tokens'=>0,'total_tokens'=>0]]);
$check($unknown['input_tokens']===null && $unknown['total_tokens']===null && $zero['total_tokens']===0,'unknown usage differs from actual zero');
$row=$normalize(['usage'=>['prompt_tokens'=>1000000,'completion_tokens'=>500000,'completion_tokens_details'=>['reasoning_tokens'=>100000]]]);
$check($row['total_tokens']===1500000 && $row['output_tokens']===500000,'reasoning details are not counted twice');
$yandex=$normalize(['result'=>['usage'=>['inputTextTokens'=>'12','completionTokens'=>'5','totalTokens'=>'17']]]);
$check($yandex['input_tokens']===12 && $yandex['output_tokens']===5,'Yandex token fields');
$check($normalize(['usage'=>['prompt_tokens'=>-2,'completion_tokens'=>[]]])['input_tokens']===null,'malformed usage remains unknown');
$check($normalize(['choices'=>[['finish_reason'=>'length']]])['status']==='truncated','truncated requests accounted separately');
$rate=LlmUsage::rates([['provider'=>'RouterAI','model'=>'model','input'=>1,'output'=>2,'currency'=>'USD']]);
$check(LlmUsage::price($row,$rate)['estimated_cost']===2.0,'cost is an explicit per-million estimate');
$check(LlmUsage::price($unknown,$rate)['estimated_cost']===null,'unknown usage never becomes zero cost');
$free=LlmUsage::rates([['provider'=>'routerai','model'=>'model','input'=>0,'output'=>0,'currency'=>'RUB']]);
$check(LlmUsage::price($row,$free)===['estimated_cost'=>0.0,'currency'=>'RUB'],'zero price is valid and currency preserved');
try{LlmUsage::rates([['provider'=>'routerai','model'=>'m','input'=>-1]]);$check(false,'negative price rejected');}catch(Core\UserError $e){$check(true,'negative price rejected');}
try{UsageMeter::within('memory',static function(){throw new Exception('test');});}catch(Exception $e){}
$check(UsageMeter::task()==='other','task scope restored after failure');
class UsageReferenceProvider implements LLM\LLMProviderInterface {
    public bool $called=false;
    public function withTimeout($seconds):self{return clone $this;}
    public function askChat(array $messagesContext,string $systemPrompt):?string{$this->called=true;return 'ok';}
}
$provider=new UsageReferenceProvider();UsageMeter::ask('search',$provider,[],'prompt');
$check($provider->called && UsageMeter::task()==='other','successful provider clone propagated by reference');
echo $fail?"FAIL: $fail\n":"ALL PASS\n";exit($fail?1:0);
