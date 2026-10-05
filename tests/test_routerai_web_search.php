<?php
namespace LLM {
    // External provider transport fixture; application endpoints and domain methods remain real.
    function curl_init($url) { return new \stdClass(); }
    function curl_setopt($handle, $option, $value) { $GLOBALS['router_fixture_options'][$option] = $value; return true; }
    function curl_exec($handle) { return json_encode(['choices'=>[['finish_reason'=>'stop','message'=>['content'=>'{"candidates":[]}','annotations'=>[['type'=>'url_citation','url_citation'=>['url'=>'https://example.org/episode','title'=>'Episode']],['url_citation'=>['url'=>'javascript:alert(1)']]]]]]]); }
    function curl_getinfo($handle,$option) { return 200; }
    function curl_error($handle) { return ''; }
    function curl_close($handle) {}
}
namespace {
    require_once __DIR__.'/../autoload.php';
    $fail=0;function expect($ok,$label){global $fail;echo($ok?'PASS ':'FAIL ').$label."\n";if(!$ok)$fail++;}
    $main=new \LLM\RouterAIProvider('fake-fixture-key','z-ai/glm-5.3',null,200);
    $search=$main->withWebSearch(3)->withReasoningEffort('low')->withTimeout(12);
    expect($search->askChat([['role'=>'user','content'=>'fixture query']],'fixture prompt')==='{"candidates":[]}','actual provider response parsed');
    $data=json_decode($GLOBALS['router_fixture_options'][CURLOPT_POSTFIELDS],true);
    expect($data['plugins']===[['id'=>'web','engine'=>'exa','max_results'=>3]],'actual HTTP payload web/exa');
    expect($data['reasoning']===['effort'=>'low'],'actual HTTP payload unified low reasoning');
    expect($GLOBALS['router_fixture_options'][CURLOPT_TIMEOUT]===12,'bounded clone transport timeout');
    expect($search->getSearchEvidence()===[['url'=>'https://example.org/episode','title'=>'Episode']],'actual annotations extracted, unsafe URL removed');
    expect($main->getSearchEvidence()===[],'main evidence unaffected');
    $GLOBALS['router_fixture_options']=[];$main->askChat([['role'=>'user','content'=>'ordinary chat']],'ordinary prompt');
    $data=json_decode($GLOBALS['router_fixture_options'][CURLOPT_POSTFIELDS],true);
    expect(!isset($data['plugins']),'ordinary HTTP payload no search');
    expect(!isset($data['reasoning']),'ordinary HTTP payload reasoning default unchanged');
    expect($GLOBALS['router_fixture_options'][CURLOPT_TIMEOUT]===60,'ordinary timeout unchanged');
    $invalid=false;try{$main->withReasoningEffort('disabled');}catch(\InvalidArgumentException $e){$invalid=true;}
    expect($invalid,'invalid effort fails closed');
    echo $fail?"FAILURES: $fail\n":"ALL PASS\n";exit($fail?1:0);
}
