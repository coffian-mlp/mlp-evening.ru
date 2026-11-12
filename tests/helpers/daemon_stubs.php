<?php
/** Loaded only in the owned disposable laboratory, never by application code. */
if(!is_file('/.dockerenv')||!is_file('/var/www/html/.mlp461-owned'))throw new RuntimeException('Disposable daemon lab only');
require __DIR__.'/daemon_network_guard.php';
function daemonTelegramTransport(string $method,array $params): array {
    file_put_contents('/var/www/html/telegram-calls.log',$method."\n",FILE_APPEND);
    $result=match($method){
        'getMe'=>['id'=>123456,'is_bot'=>true,'username'=>'synthetic_bot'],
        'getUpdates'=>[],
        default=>throw new RuntimeException('Unexpected Telegram mutation in daemon lab'),
    };
    return ['status'=>200,'errno'=>0,'body'=>json_encode(['ok'=>true,'result'=>$result],JSON_THROW_ON_ERROR)];
}
