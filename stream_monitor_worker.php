<?php
/** Persistent supervision loop; never exposes diagnostics through HTTP. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/autoload.php';
$loop=in_array('--loop',$argv,true);
do{
    $started=microtime(true);
    try{(new \Domain\StreamMonitor())->tick();}
    catch(\Throwable $e){error_log('Stream monitor tick failed: '.get_class($e));}
    if($loop)usleep((int)(max(0.1,10-(microtime(true)-$started))*1000000));
}while($loop);
