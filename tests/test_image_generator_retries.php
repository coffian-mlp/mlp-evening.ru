<?php
namespace Infra {
    final class ConfigManager {
        public static function getInstance(): self { return new self(); }
        public function getOption($key, $default = null) {
            return ($GLOBALS['image_retry_config'] ?? [])[$key] ?? ['ai_image_provider'=>'openai','ai_openai_key'=>'synthetic','ai_routerai_key'=>'synthetic', 'ai_debug_log'=>0][$key] ?? $default;
        }
    }
}
namespace LLM {
    // Also exercise the archived one-shot implementation without any network access in RED.
    function curl_init($url) {return (object)['url'=>$url,'options'=>[],'response'=>[]];}
    function curl_setopt_array($ch,$options){$ch->options=$options;return true;}
    function curl_setopt($ch,$key,$value){$ch->options[$key]=$value;return true;}
    function curl_exec($ch){$ch->response=array_shift($GLOBALS['baseline_images']);$GLOBALS['baseline_calls'][]=$ch->options;return $ch->response['body']??false;}
    function curl_getinfo($ch,$key){return $ch->response['code']??0;}
    function curl_error($ch){return $ch->response['error']??'';}
    function curl_errno($ch){return $ch->response['errno']??0;}
    function curl_close($ch){}
}
namespace {
require dirname(__DIR__).'/autoload.php';
$fail = 0;
function checkRetry(bool $ok, string $label): void {
    global $fail;
    echo ($ok ? '[OK] ' : '[FAIL] ').$label."\n";
    if (!$ok) $fail++;
}
function imageScenario(array $responses, ?float $budget = null, ?string $reference = null): array {
    $calls=[]; $logs=[]; $usage=[]; $saves=0; $now=1000.0;
    $options=[
        'clock'=>static function() use (&$now) { return $now; },
        'pause'=>static function(float $seconds) use (&$now) { $now += $seconds; },
        'transport'=>static function($endpoint,$payload,$headers,$proxy,$multipart,$timeout) use (&$responses,&$calls,&$now) {
            $calls[]=compact('endpoint','payload','multipart','timeout');
            $row=array_shift($responses) ?? ['code'=>503];
            $now += $row['seconds'] ?? 0;
            return $row + ['body'=>'','code'=>0,'errno'=>0,'error'=>'','duration_ms'=>1];
        },
        'save'=>static function(string $bytes) use (&$saves) { $saves++; return $bytes==='fixture' ? '/upload/lyra/synthetic.jpg' : null; },
        'debug'=>static function(array $request,string $body,string $status,int $ms) use (&$logs) { $logs[]=compact('request','status'); },
    ];
    if ($budget !== null) $options['deadline']=$now+$budget;
    $url=Domain\LlmUsage::withoutDatabase(fn()=>LLM\ImageGenerator::generate('Synthetic image',null,$reference,$options),
        static function($row) use (&$usage) { $usage[]=$row; });
    return compact('url','calls','logs','usage','saves','now') + ['error'=>LLM\ImageGenerator::lastError()];
}
$success=['code'=>200,'body'=>json_encode(['data'=>[['b64_json'=>base64_encode('fixture')]]])];
if (!defined(LLM\ImageGenerator::class.'::MAX_ATTEMPTS')) {
    $GLOBALS['baseline_images']=[['errno'=>28,'error'=>'synthetic timeout'],$success];$GLOBALS['baseline_calls']=[];
    $result=Domain\LlmUsage::withoutDatabase(fn()=>LLM\ImageGenerator::generate('Synthetic timeout retry'),static function($row):void{});
    checkRetry($result!==null && count($GLOBALS['baseline_calls'])===2,'timeout is followed by a second transport attempt');
    checkRetry(($GLOBALS['baseline_calls'][0][CURLOPT_TIMEOUT]??0)===110,'native transport attempt timeout is 110 seconds');
} else {
    foreach ([5,6,7,18,28,52,55,56] as $errno) {
        $r=imageScenario([['code'=>200,'errno'=>$errno,'error'=>'synthetic transport'],$success]);
        checkRetry($r['url']!==null && count($r['calls'])===2 && count($r['usage'])===2 && $r['saves']===1, 'transient errno '.$errno.' succeeds on second attempt');
    }
    foreach ([429,500,502,503,599] as $code) {
        $r=imageScenario([['code'=>$code],['errno'=>28,'error'=>'timeout'],$success]);
        checkRetry($r['url']!==null && count($r['calls'])===3 && count($r['logs'])===3, 'HTTP '.$code.' succeeds on third attempt');
        checkRetry(array_column(array_column($r['logs'],'request'),'attempt')===[1,2,3], 'debug attempt numbers '.$code);
    }
    foreach ([400,401,403,404,408,422,425] as $code) {
        $r=imageScenario([['code'=>$code],$success]);
        checkRetry($r['url']===null && count($r['calls'])===1,'HTTP '.$code.' is not retried');
    }
    foreach ([3,23,26,42,60] as $errno) {
        $r=imageScenario([['errno'=>$errno,'error'=>'permanent'],$success]);
        checkRetry($r['url']===null && count($r['calls'])===1,'permanent errno '.$errno.' is not retried');
    }
    foreach ([200,400,503] as $code) {
        $r=imageScenario([['code'=>$code,'body'=>'   {"error":{"message":"content safety blocked"}}'],$success]);
        checkRetry(count($r['calls'])===1 && LLM\ImageGenerator::lastErrorIsSafety(),'safety '.$code.' stops transport retry');
    }
    foreach (['not json','{}','{"data":[{"b64_json":"%%%"}]}',json_encode(['data'=>[['b64_json'=>base64_encode('bad image')]]])] as $body) {
        $r=imageScenario([['code'=>200,'body'=>$body],$success]);
        checkRetry($r['url']===null && count($r['calls'])===1 && $r['logs'][0]['status']==='error','finished malformed image is not retried');
    }
    $r=imageScenario(array_fill(0,4,['errno'=>28,'error'=>'timeout','seconds'=>110]));
    checkRetry(count($r['calls'])===3 && $r['now']<=1331 && $r['error']!==null,'three timeouts exhaust a bounded budget');
    checkRetry(array_column($r['calls'],'timeout')===[110000,110000,110000],'each attempt has 110 second timeout');
    $r=imageScenario([$success],-1);
    checkRetry(!$r['calls'] && !$r['usage'],'expired image deadline starts no HTTP attempt');
    $r=LLM\RequestDeadline::within(microtime(true)-1,fn()=>imageScenario([$success]));
    checkRetry($r['url']!==null && count($r['calls'])===1,'expired text scope does not cancel independent image');
    checkRetry($r['error']===null,'success clears last error');
    $im=imagecreatetruecolor(128,128);
    for($x=0;$x<128;$x++)for($y=0;$y<128;$y++)imagesetpixel($im,$x,$y,imagecolorallocate($im,($x*7)%255,($y*9)%255,($x+$y)%255));
    ob_start();imagepng($im);$bytes=ob_get_clean();imagedestroy($im);
    $reference=LLM\ImageGenerator::saveJpeg($bytes,'it_retry_reference');
    try {
        foreach ([['openai','synthetic','/images/edits',true],['routerai','google/synthetic','/chat/completions',false],['routerai','synthetic','/api/v1/images',false]] as [$provider,$model,$endpoint,$multipart]) {
            $GLOBALS['image_retry_config']=['ai_image_provider'=>$provider,'ai_image_model'=>$model];
            $r=imageScenario([['errno'=>28,'error'=>'timeout'],$success],null,$reference);
            checkRetry($r['url']!==null && count($r['calls'])===2 && str_ends_with($r['calls'][0]['endpoint'],$endpoint) && $r['calls'][0]['multipart']===$multipart,'reference retry keeps '.$provider.' '.$endpoint.' transport');
            checkRetry($r['calls'][0]['payload']===$r['calls'][1]['payload'],'reference request remains immutable across retry '.$endpoint);
            checkRetry(!str_contains(json_encode($r['logs']),'data:image/'),'debug request omits inline reference bytes '.$endpoint);
        }
    } finally {unset($GLOBALS['image_retry_config']);if($reference)unlink(dirname(__DIR__).$reference);}
}
echo $fail ? "FAIL: $fail\n" : "ALL PASS\n";
exit((int)($fail>0));
}
