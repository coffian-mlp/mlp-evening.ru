<?php
$fail=0;
$check=static function($ok,$label)use(&$fail){echo($ok?'[OK] ':'[FAIL] ').$label."\n";if(!$ok)$fail++;};
$root=dirname(__DIR__);
foreach(['lyra_bot_worker.php','stream_monitor_worker.php'] as $worker){
    foreach([['mysqli_sql_exception',2006,true],['mysqli_sql_exception',2002,true],['mysqli_sql_exception',1064,false],['RuntimeException',2006,false]] as [$class,$code,$fatal]){
        $script='require '.var_export(__DIR__.'/fixtures/daemon-database-loss.php',true).'; $argv=["worker","--loop"]; require '.var_export($root.'/'.$worker,true).';';
        $env=getenv();$env['MLP_DAEMON_ERROR_CLASS']=$class;$env['MLP_DAEMON_ERROR_CODE']=(string)$code;
        $process=proc_open([PHP_BINARY,'-r',$script],[1=>['pipe','w'],2=>['pipe','w']],$pipes,$root,$env);
        stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
        $out='';$deadline=microtime(true)+12;$timedOut=false;
        do{
            $out.=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);$status=proc_get_status($process);
            if(!$status['running'])break;
            usleep(20000);
        }while(microtime(true)<$deadline);
        if($status['running']){proc_terminate($process,9);$timedOut=true;}
        $out.=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);proc_close($process);
        $label="$worker $class/$code";
        $check(!$timedOut,$label.' terminates within the test deadline');
        $check($fatal?$status['exitcode']===1&&!str_contains($out,'NEXT_TICK'):$status['exitcode']===0&&str_contains($out,'NEXT_TICK'),$label.($fatal?' exits 1 before another tick':' continues to another tick'));
        if($fatal){
            $check(substr_count($out,'mysqli_sql_exception')===1&&str_contains($out,(string)$code),$label.' logs class and code once');
            $check(!str_contains($out,'ORDINARY_ERROR_RECORDED'),$label.' bypasses ordinary error reporting');
        }
        $check(!str_contains($out,'synthetic query and private data'),$label.' omits error message and private data');
    }
}
echo $fail?"FAIL: $fail\n":"ALL PASS\n";exit($fail?1:0);
