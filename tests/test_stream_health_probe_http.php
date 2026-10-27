<?php
require_once __DIR__.'/../autoload.php';
$fail=0;$check=static function($v,$m)use(&$fail){echo($v?'[OK] ':'[FAIL] ').$m."\n";if(!$v)$fail++;};
$pipes=[];$process=proc_open([PHP_BINARY,__DIR__.'/fixtures/stream-health-http.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
if(!is_resource($process)){echo "FAIL: fixture did not start\n";exit(1);}
try{
    fclose($pipes[0]);stream_set_blocking($pipes[1],false);$ready='';$deadline=microtime(true)+3;
    do{$ready.=stream_get_contents($pipes[1]);if(str_contains($ready,"READY\n"))break;usleep(10000);}while(microtime(true)<$deadline);
    if(!str_contains($ready,"READY\n"))throw new RuntimeException('HTTP fixture unavailable');
    $probe=new Infra\StreamHealthProbe();$first=$probe->sample('evening');$second=$probe->sample('evening');$third=$probe->sample('evening');
    $check($first['state']==='live'&&$second['state']==='live'&&$third['state']==='live','real cURL follows bounded cookie redirect and session-bearing media URI');
    $check(isset($first['progress'],$second['progress'])&&$first['progress']===$second['progress'],'separate cookie/session handshakes preserve frozen-playlist identity');
    $check(isset($second['progress'],$third['progress'])&&$second['progress']!==$third['progress'],'advancing sequence changes identity through real HTTP');
    $run=['last_checked'=>null,'healthy_since'=>null,'progress_key'=>null,'progress_at'=>null,'recovered_since'=>null,'lost_at'=>null,'health'=>'unknown','armed'=>0,'ended'=>0];
    $run=Domain\StreamHealthPolicy::observe($run,$first,1800000000);
    foreach([10,20,30] as $t)$run=Domain\StreamHealthPolicy::observe($run,$second,1800000000+$t);
    $check($run['health']==='down','rotating HTTP sessions cannot mask the 30-second stale-playlist alarm');
}catch(Throwable $e){$check(false,$e->getMessage());}
finally{proc_terminate($process);fclose($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[2]);proc_close($process);if($error!=='')$check(false,trim($error));}
echo $fail?"FAIL: $fail\n":"ALL PASS\n";exit($fail?1:0);
