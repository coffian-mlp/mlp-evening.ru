<?php
require dirname(__DIR__).'/autoload.php';
$fail=0;$check=static function($ok,$label)use(&$fail){echo ($ok?'[OK] ':'[FAIL] ').$label."\n";if(!$ok)$fail++;};
foreach(['/live/evening/index.m3u8','/live/stream_2-test/index.m3u8'] as $url)$check(Domain\SelfHostedStream::normalizeUrl($url)===$url,'local HLS URL accepted');
foreach(['https://evil.test/live/evening/index.m3u8','//evil.test/x','/live/../index.m3u8','/live/%2e%2e/index.m3u8','/live/stream/index.m3u8?token=secret','/api.php',"/live/a/index.m3u8\n",[],null] as $url){try{Domain\SelfHostedStream::normalizeUrl($url);$check(false,'unsafe URL rejected');}catch(Core\UserError $e){$check(true,'unsafe URL rejected');}}
$routes=require dirname(__DIR__).'/src/Api/routes.php';foreach(['save_stream_settings','self_hosted_stream_status','self_hosted_stream_credentials'] as $action)$check($routes[$action]['role']==='admin'&&is_callable($routes[$action]['handler']),'admin-only route '.$action);
$config=file_get_contents(dirname(__DIR__).'/deploy/media/mediamtx.sample.yml');
$check(str_contains($config,'rtmpEncryption: strict')&&str_contains($config,'apiAddress: 127.0.0.1:8897')&&str_contains($config,'metricsAddress: 127.0.0.1:8899'),'TLS ingest and local management defaults');
$check(!str_contains($config,'all_others')&&str_contains($config,'overridePublisher: false')&&str_contains($config,'record: false'),'single explicit stream, no publisher takeover or recording');
echo $fail?"FAIL: $fail\n":"ALL PASS\n";exit($fail?1:0);
