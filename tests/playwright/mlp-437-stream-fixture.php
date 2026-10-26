<?php
require dirname(__DIR__).'/integration_helpers.php';
if(PHP_SAPI!=='cli'||!is_file('/.dockerenv')||(it_config()['db']['host']??'')!=='db')throw new RuntimeException('Isolated Docker required');
$db=it_require_db();$config=Infra\ConfigManager::getInstance();$path=dirname(__DIR__,2).'/docs/private/mlp437-local.json';$secret='/etc/mlp-evening/stream-publish.json';
$mode=$argv[1]??'';
if($mode==='setup'){
    if(is_file($path))throw new RuntimeException('Fixture already exists');
    $state=['secret'=>is_file($secret)?file_get_contents($secret):null];$old=umask(0077);file_put_contents($path,json_encode($state));umask($old);
    if(!is_dir(dirname($secret)))mkdir(dirname($secret),0750,true);
    file_put_contents($secret,json_encode(['server'=>'rtmps://fixture.local:1936','key'=>'evening?user=broadcaster&pass='.str_repeat('a',64)]));chmod($secret,0640);
    Domain\SelfHostedStream::save(['stream_source'=>'self_hosted','stream_url'=>'https://player.twitch.tv/?channel=fixture','self_hosted_hls_url'=>'/live/evening/index.m3u8']);echo "Fixture ready\n";
}elseif($mode==='external'){$config->setOption('stream_source','external');echo "External selected\n";
}elseif($mode==='cleanup'){$state=json_decode(file_get_contents($path),true);if($state['secret']===null)unlink($secret);else file_put_contents($secret,$state['secret']);unlink($path);echo "Fixture removed\n";
}else throw new RuntimeException('setup|external|cleanup');
