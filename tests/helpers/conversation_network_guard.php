<?php
/** Test-container-only guard. Never installed in the production PHP configuration. */
if(!is_file('/.dockerenv')||!is_file('/var/www/html/.mlp456-owned'))throw new RuntimeException('Disposable network guard only');
if(function_exists('curl_exec')&&(new ReflectionFunction('curl_exec'))->isInternal())throw new RuntimeException('Native curl_exec must be disabled');
$networkGuardFile='/var/www/html/conversation-network-violations.log';
$networkGuardOffset=is_file($networkGuardFile)?filesize($networkGuardFile):0;
register_shutdown_function(static function()use($networkGuardFile,$networkGuardOffset){clearstatcache(true,$networkGuardFile);if(is_file($networkGuardFile)&&filesize($networkGuardFile)>$networkGuardOffset){fwrite(STDERR,"Forbidden network attempt detected\n");exit(1);}});
// Local data:// fixtures remain available; network stream transports never reach a socket.
class ConversationBlockedNetworkStream {
    public $context;
    public function stream_open($path,$mode,$options,&$opened): bool {
        file_put_contents('/var/www/html/conversation-network-violations.log',"blocked_stream_attempt\n",FILE_APPEND);
        throw new RuntimeException('Network stream forbidden in synthetic tests');
    }
}
foreach(['http','https','ftp','ftps']as$scheme){if(in_array($scheme,stream_get_wrappers(),true))stream_wrapper_unregister($scheme);stream_wrapper_register($scheme,ConversationBlockedNetworkStream::class);}
function curl_exec($handle) {
    $url=curl_getinfo($handle,CURLINFO_EFFECTIVE_URL);$host=parse_url($url,PHP_URL_HOST);
    if(!in_array($host,['localhost','127.0.0.1','::1','php'],true)){
        file_put_contents('/var/www/html/conversation-network-violations.log',"blocked_http_attempt\n",FILE_APPEND);
        throw new RuntimeException('External network attempt forbidden in synthetic tests');
    }
    // Redirects are handled explicitly by callers; never follow outside the allowlist.
    curl_setopt($handle,CURLOPT_FOLLOWLOCATION,false);
    $multi=curl_multi_init();curl_multi_add_handle($multi,$handle);
    try{do{$status=curl_multi_exec($multi,$running);if($running)curl_multi_select($multi,0.05);}while($running&&$status===CURLM_OK);
        $info=curl_multi_info_read($multi);return $info&&$info['result']!==CURLE_OK?false:curl_multi_getcontent($handle);
    }finally{curl_multi_remove_handle($multi,$handle);curl_multi_close($multi);}
}
