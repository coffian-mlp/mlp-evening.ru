<?php
/** Restart=always, RestartSec=5 and 5 starts/10s model; real workers are unmodified. */
if(!is_file('/.dockerenv')||!is_file('/var/www/html/.mlp461-owned'))throw new RuntimeException('Disposable daemon lab only');
$worker=$argv[1]??'';
if(!in_array($worker,['lyra_bot_worker.php','stream_monitor_worker.php'],true))throw new InvalidArgumentException('Unknown worker');
$prefix='/var/www/html/'.$worker;
$history=[];
while(!is_file('/var/www/html/stop-supervisors')){
    $history=array_values(array_filter($history,static fn($t)=>microtime(true)-$t<10));
    if(count($history)>=5){file_put_contents($prefix.'.events',json_encode(['event'=>'start_limit','time'=>microtime(true)])."\n",FILE_APPEND);exit(2);}
    $history[]=microtime(true);
    $process=proc_open([PHP_BINARY,'-d','disable_functions=curl_exec','-d','auto_prepend_file=/var/www/html/tests/helpers/daemon_stubs.php','/var/www/html/'.$worker,'--loop'],[1=>['file',$prefix.'.out','a'],2=>['file',$prefix.'.err','a']],$pipes);
    $status=proc_get_status($process);
    file_put_contents($prefix.'.events',json_encode(['event'=>'start','pid'=>$status['pid'],'time'=>microtime(true)])."\n",FILE_APPEND);
    while($status['running']){
        if(is_file('/var/www/html/stop-supervisors'))proc_terminate($process);
        usleep(100000);$status=proc_get_status($process);
    }
    proc_close($process);
    file_put_contents($prefix.'.events',json_encode(['event'=>'exit','code'=>$status['exitcode'],'time'=>microtime(true)])."\n",FILE_APPEND);
    if(!is_file('/var/www/html/stop-supervisors'))sleep(5);
}
