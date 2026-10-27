<?php
require_once __DIR__.'/../autoload.php';
$fail=0;$check=static function($v,$m)use(&$fail){echo($v?'[OK] ':'[FAIL] ').$m."\n";if(!$v)$fail++;};
$responses=[];$urls=[];$probe=new Infra\StreamHealthProbe(static function($url)use(&$responses,&$urls){$urls[]=$url;return array_shift($responses)??throw new RuntimeException('Unexpected network call');});
$response=static fn($body,$status=200,$location='')=>compact('body','status','location');
$responses=[$response('{"ready":false}')];$check($probe->sample('evening')['state']==='down'&&count($urls)===1,'missing source avoids starting HLS muxer');
$master="#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=6000000\nvideo1_stream.m3u8\n";
$media="#EXTM3U\n#EXT-X-MEDIA-SEQUENCE:100\n#EXTINF:2,\nvideo100.mp4\n";
$responses=[$response('{"ready":true}'),$response($master),$response($media)];$sample=$probe->sample('evening');
$check($sample['state']==='live'&&$sample['progress']===hash('sha256',$media),'live state fingerprints media playlist rather than static master');
$session='12345678-1234-1234-1234-123456789abc';
$sessionMaster=str_replace('video1_stream.m3u8','video1_stream.m3u8?session='.$session,$master);
$sessionMedia=str_replace('video100.mp4','video100.mp4?session='.$session,$media);
$responses=[$response('{"ready":true}'),$response('',302,'/evening/index.m3u8?cookieCheck=1'),$response($sessionMaster),$response($sessionMedia)];
$sample=$probe->sample('evening');$check($sample['state']==='live'&&$sample['progress']===hash('sha256',$media),'cookie redirect and session-bearing media playlist are live');
$check(array_slice($urls,-2)===['http://127.0.0.1:8898/evening/index.m3u8?cookieCheck=1','http://127.0.0.1:8898/evening/video1_stream.m3u8?session='.$session],'handshake and media requests preserve allowed query parameters');
$nextSession='abcdefab-1234-1234-1234-123456789abc';
$responses=[$response('{"ready":true}'),$response(str_replace($session,$nextSession,$sessionMaster)),$response(str_replace($session,$nextSession,$sessionMedia))];
$same=$probe->sample('evening');$check($same['progress']===$sample['progress'],'session rotation cannot hide a frozen media playlist');
$responses=[$response('{"ready":true}'),$response($sessionMaster),$response(str_replace('100','101',$sessionMedia))];
$check($probe->sample('evening')['progress']!==$sample['progress'],'new media sequence still advances the fingerprint');
foreach(['/evening/index.m3u8?session='.$session,'http://127.0.0.1:8898/evening/index.m3u8?cookieCheck=1'] as $location){
    $responses=[$response('{"ready":true}'),$response('',307,$location),$response($sessionMaster),$response($sessionMedia)];
    $check($probe->sample('evening')['state']==='live','same-origin same-playlist redirect accepted');
}
foreach(['https://other.test/secret','//other.test/secret','http://127.0.0.1:8897/secret','/other/index.m3u8','/evening/../secret','/evening/index.m3u8?url=http://other.test','/evening/index.m3u8?cookieCheck=1&url=other','/evening/index.m3u8#fragment'] as $location){
    $start=count($urls);$responses=[$response('{"ready":true}'),$response('',302,$location)];
    $check($probe->sample('evening')['state']==='down'&&count($urls)===$start+2,'unsafe redirect never produces another request: '.$location);
}
$start=count($urls);$redirect=$response('',302,'/evening/index.m3u8?cookieCheck=1');
$responses=[$response('{"ready":true}'),$redirect,$redirect,$redirect];
$check($probe->sample('evening')['state']==='down'&&count($urls)===$start+4,'redirect loop stops after two hops');
$start=count($urls);$responses=[$response('',302,'http://127.0.0.1:8897/v3/paths/get/evening'),$response('',0)];
$check($probe->sample('evening')['state']==='down'&&count($urls)===$start+2,'control API redirects are not followed');
foreach(['video1_stream.m3u8?session=x&url=other','../video1_stream.m3u8?session=x','video1_stream.m3u8?other=x'] as $uri){
    $start=count($urls);$responses=[$response('{"ready":true}'),$response(str_replace('video1_stream.m3u8',$uri,$master))];
    $check($probe->sample('evening')['state']==='unknown'&&count($urls)===$start+2,'untrusted media query or path is not fetched');
}
$responses=[$response('{"ready":true}'),$response($master),$response($media."#EXT-X-ENDLIST\n")];$check($probe->sample('evening')['state']==='down','completed VOD playlist cannot appear live');
$responses=[$response('{"ready":true}'),$response("#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=2\nhttps://other.test/secret\n")];$check($probe->sample('evening')['state']==='unknown','untrusted absolute playlist URI is never fetched');
$responses=[$response('',0),$response('',0)];$check($probe->sample('evening')['state']==='down','loss of both local API and HLS is a media failure');
try{$probe->sample('../secret');$check(false,'invalid path denied');}catch(InvalidArgumentException $e){$check(true,'invalid path denied before any request');}
$check(!array_filter($urls,static fn($url)=>!str_starts_with($url,'http://127.0.0.1:8897/')&&!str_starts_with($url,'http://127.0.0.1:8898/')),'all health requests remain fixed to local media ports');
echo $fail?"FAIL: $fail\n":"ALL PASS\n";exit($fail?1:0);
