<?php
require_once dirname(__DIR__).'/integration_helpers.php';
if(PHP_SAPI!=='cli'||!is_file('/.dockerenv')||(it_config()['db']['host']??'')!=='db')throw new RuntimeException('Isolated Docker required');
$db=it_require_db();$m=new Domain\EpisodeManager();$path=dirname(__DIR__,2).'/docs/private/mlp436-local.json';
$mode=$argv[1]??'';
if($mode==='setup'){
    if(is_file($path))throw new RuntimeException('Cleanup fixture first');
    $auth=json_decode(file_get_contents(dirname(__DIR__,2).'/docs/private/mlp425-local.json'),true,512,JSON_THROW_ON_ERROR);
    $s=['users'=>array_column($auth['users'],'id'),'actor'=>$auth['users']['user']['id'],'ids'=>[]];
    $save=static function()use(&$s,$path){$old=umask(0077);try{file_put_contents($path,json_encode($s,JSON_THROW_ON_ERROR));chmod($path,0600);}finally{umask($old);}};$save();
    foreach(['old','recent','first','second'] as $key){$title='Weekly '.$key.' fixture';$q=$db->prepare('INSERT INTO episode_list(TITLE,LENGTH) VALUES(?,1)');$q->bind_param('s',$title);$q->execute();$s['ids'][$key]=(int)$db->insert_id;$save();}
    $now=time();$latest=($argv[2]??'')==='boundary'?$now-604800+12:$now-8*86400;
    $m->wish($s['actor'],$s['ids']['old'],'436ui:old1:'.$s['actor'],$latest-604800);
    $m->wish($s['actor'],$s['ids']['old'],'436ui:old2:'.$s['actor'],$latest);
    $m->wish($s['actor'],$s['ids']['recent'],'436ui:recent:'.$s['actor'],$now);
    echo "Fixture ready\n";
}elseif($mode==='seed'){
    $s=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);$key=$argv[2]??'';
    if(!in_array($key,['first','second'],true))throw new RuntimeException('Owned target required');
    $m->wish($s['actor'],$s['ids'][$key],'436ui:seed:'.$s['actor'].':'.$key);echo "Seeded\n";
}elseif($mode==='state'){
    $s=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);$projection=(new Domain\EpisodeRatingManager())->getCatalogueProjection($s['actor']);
    echo json_encode(['rows'=>array_values(array_filter($projection['rows'],static fn($r)=>in_array($r['id'],$s['ids'],true))),'quota'=>$projection['viewer']['quota']],JSON_THROW_ON_ERROR);
}elseif($mode==='cleanup'){
    $s=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    foreach($s['users'] as $id)$m->eraseUserData((int)$id);
    foreach($s['ids'] as $id)$db->query('DELETE FROM episode_list WHERE ID='.(int)$id);
    unlink($path);echo "Fixture removed\n";
}else throw new RuntimeException('setup|seed|state|cleanup');
