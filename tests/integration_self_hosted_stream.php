<?php
require __DIR__.'/integration_helpers.php';
if((it_config()['db']['host']??'')!=='db')it_skip('isolated database required');
$db=it_require_db();$config=Infra\ConfigManager::getInstance();$keys=['stream_source','stream_url','self_hosted_hls_url'];$before=[];
foreach($keys as $key)$before[$key]=$config->getOptionDetails($key);
$current=static fn()=>array_map(static fn($key)=>$config->getOptionDetails($key),$keys);
try{
    Domain\SelfHostedStream::save(['stream_source'=>'external','stream_url'=>'https://player.twitch.tv/?channel=fixture','self_hosted_hls_url'=>'/live/evening/index.m3u8']);
    check(Domain\SelfHostedStream::settings()===['source'=>'external','url'=>'/live/evening/index.m3u8'],'external mode retained');
    $baseline=$current();
    foreach([['stream_source'=>'other'],['self_hosted_hls_url'=>'https://evil.test/live/evening/index.m3u8'],['stream_url'=>'javascript:alert(1)'],['stream_url'=>'https://user:secret@example.com/']] as $patch){
        try{Domain\SelfHostedStream::save($patch+['stream_source'=>'self_hosted','stream_url'=>'https://player.twitch.tv/?channel=fixture','self_hosted_hls_url'=>'/live/evening/index.m3u8']);check(false,'invalid settings reject');}catch(Core\UserError $e){check($current()===$baseline,'invalid settings reject before any writes');}
    }
    Domain\SelfHostedStream::save(['stream_source'=>'self_hosted','stream_url'=>'https://player.twitch.tv/?channel=fixture','self_hosted_hls_url'=>'/live/evening/index.m3u8']);
    check(Domain\SelfHostedStream::settings()['source']==='self_hosted'&&$config->getOption('stream_url')==='https://player.twitch.tv/?channel=fixture','self-hosted source retains external URL');
    check(!Domain\SelfHostedStream::status()['available'],'missing optional media service returns unavailable safely');
}finally{foreach($before as $key=>$row){$q=$db->prepare('DELETE FROM site_options WHERE key_name=?');$q->bind_param('s',$key);$q->execute();if($row){$q=$db->prepare('INSERT INTO site_options(key_name,value,updated_at) VALUES(?,?,?)');$q->bind_param('sss',$key,$row['value'],$row['updated_at']);$q->execute();}}$config->flushCache();}
it_done();
