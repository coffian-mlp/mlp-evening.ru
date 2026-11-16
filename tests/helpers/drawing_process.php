<?php
function drawingStart(string $mode, ?int $id=null): array {
    $cmd=[PHP_BINARY,__DIR__.'/drawing_worker_probe.php',$mode]; if($id!==null)$cmd[]=(string)$id;
    if($mode==='daemon')$cmd=[PHP_BINARY,'-d','auto_prepend_file='.__DIR__.'/drawing_transport.php',dirname(__DIR__,2).'/bot_worker.php','daemon'];
    $process=proc_open($cmd,[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('Cannot start probe');
    return [$process,$pipes];
}
function drawingFinish(array $child): string {
    [$process,$pipes]=$child; $out=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
    if($code!==0)throw new RuntimeException('Probe exit '.$code.': '.$out);
    return $out;
}
function drawingWait(string $file): void {
    $deadline=microtime(true)+10;
    while(!is_file($file)&&microtime(true)<$deadline)usleep(20000);
    if(!is_file($file))throw new RuntimeException('Barrier did not arrive');
}
function drawingWaitPhase(int $id,string $phase): void {
    $deadline=microtime(true)+10; $queue=new LLM\JobQueue();
    do { $row=$queue->drawingRow($id); if(($row['data']['drawing_v1']['phase']??'')===$phase)return; usleep(20000); }while(microtime(true)<$deadline);
    throw new RuntimeException('Drawing phase did not arrive: '.$phase);
}
