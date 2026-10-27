<?php
require_once __DIR__.'/../autoload.php';
$fail=0;$check=static function($v,$m)use(&$fail){echo($v?'[OK] ':'[FAIL] ').$m."\n";if(!$v)$fail++;};
$responses=[];$urls=[];$probe=new Infra\StreamHealthProbe(static function($url)use(&$responses,&$urls){$urls[]=$url;return array_shift($responses)??throw new RuntimeException('Unexpected network call');});
$response=static fn($body,$status=200)=>compact('body','status');
$responses=[$response('{"ready":false}')];$check($probe->sample('evening')['state']==='down'&&count($urls)===1,'missing source avoids starting HLS muxer');
$master="#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=6000000\nvideo1_stream.m3u8\n";
$media="#EXTM3U\n#EXT-X-MEDIA-SEQUENCE:100\n#EXTINF:2,\nvideo100.mp4\n";
$responses=[$response('{"ready":true}'),$response($master),$response($media)];$sample=$probe->sample('evening');
$check($sample['state']==='live'&&$sample['progress']===hash('sha256',$media),'live state fingerprints media playlist rather than static master');
$responses=[$response('{"ready":true}'),$response($master),$response($media."#EXT-X-ENDLIST\n")];$check($probe->sample('evening')['state']==='down','completed VOD playlist cannot appear live');
$responses=[$response('{"ready":true}'),$response("#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=2\nhttps://other.test/secret\n")];$check($probe->sample('evening')['state']==='unknown','untrusted absolute playlist URI is never fetched');
$responses=[$response('',0),$response('',0)];$check($probe->sample('evening')['state']==='down','loss of both local API and HLS is a media failure');
try{$probe->sample('../secret');$check(false,'invalid path denied');}catch(InvalidArgumentException $e){$check(true,'invalid path denied before any request');}
$check(!array_filter($urls,static fn($url)=>!str_starts_with($url,'http://127.0.0.1:8897/')&&!str_starts_with($url,'http://127.0.0.1:8898/')),'all health requests remain fixed to local media ports');
echo $fail?"FAIL: $fail\n":"ALL PASS\n";exit($fail?1:0);
