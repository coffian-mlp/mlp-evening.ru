<?php
require dirname(__DIR__).'/integration_helpers.php';
if(PHP_SAPI!=='cli'||!is_file('/.dockerenv')||(it_config()['db']['host']??'')!=='db')exit(2);
it_require_db();ob_start();
$db=Infra\Database::getInstance()->getConnection();$config=Infra\ConfigManager::getInstance();$users=new Domain\UserManager();
$path=dirname(__DIR__,2).'/docs/private/mlp420-local.json';$mode=$argv[1]??'';
$save=static function(array $state)use($path){$mask=umask(0077);file_put_contents($path,json_encode($state,JSON_THROW_ON_ERROR));umask($mask);};
if($mode==='setup'){
    if(is_file($path))throw new RuntimeException('Fixture already exists');
    $s=['suffix'=>bin2hex(random_bytes(6)),'password'=>'Aa!'.bin2hex(random_bytes(16)),'ids'=>[],'options'=>[]];$save($s);
    foreach(['ai_enabled','stream_url','ai_usage_rates'] as $key){$stmt=$db->prepare('SELECT value FROM site_options WHERE key_name=?');$stmt->bind_param('s',$key);$stmt->execute();$s['options'][$key]=$stmt->get_result()->fetch_assoc()['value']??null;}$save($s);
    $config->setOption('ai_enabled','0');$config->setOption('stream_url','https://video.invalid/player');
    foreach(['admin','reader'] as $role){$s[$role.'Login']='it_bundle_'.$role.'_'.$s['suffix'];$s['ids'][$role]=$users->createUser($s[$role.'Login'],$s['password'],$role==='admin'?'admin':'user');$save($s);Domain\Privacy::setAccountConsent($s['ids'][$role],true);}
    $s['run']='it_ui_archive_'.$s['suffix'];$s['title']='Вечерок '.$s['suffix'];$s['model']='it_ui_usage_'.$s['suffix'];$save($s);
    $stories=[['story_id'=>'pair','ids'=>[1,2],'titles'=>['Первая часть','Вторая часть']],['story_id'=>'single','ids'=>[3],'titles'=>['<img src=x onerror="window.archiveXss=1">']]];
    $payload=json_encode($stories);$stmt=$db->prepare('INSERT INTO playlist_snapshots(created_at,payload_json,origin) VALUES(UTC_TIMESTAMP(),?,"test")');$stmt->bind_param('s',$payload);$stmt->execute();$s['snapshot']=$db->insert_id;$save($s);
    $metadata=json_encode(['title'=>$s['title']]);$stmt=$db->prepare('INSERT INTO playlist_occurrences(run_id,event_id,start_at,end_at,snapshot_id,state,metadata_json) VALUES(?,0,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()-INTERVAL 20 HOUR,?,"completed",?)');$stmt->bind_param('sis',$s['run'],$s['snapshot'],$metadata);$stmt->execute();
    $stmt=$db->prepare('INSERT INTO playlist_completions(completion_key,snapshot_id,run_id,start_at,completed_at,shown_json) VALUES(?,?,?,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()-INTERVAL 20 HOUR,JSON_ARRAY("pair"))');$stmt->bind_param('sis',$s['run'],$s['snapshot'],$s['run']);$stmt->execute();
    $s['reaction_start']=(new Domain\StreamReactions())->latestId();$save($s);
    LLM\UsageMeter::capture('routerai',$s['model'],'{"usage":{"prompt_tokens":1000000,"completion_tokens":500000,"total_tokens":1500000}}',200,false,100,'chat');
}elseif($mode==='cleanup'){
    if(!is_file($path))exit;$s=json_decode(file_get_contents($path),true);
    if(isset($s['run'])){foreach(['playlist_corrections'=>'completion_key','playlist_completions'=>'completion_key','playlist_occurrences'=>'run_id'] as $table=>$column){$stmt=$db->prepare("DELETE FROM $table WHERE $column=?");$stmt->bind_param('s',$s['run']);$stmt->execute();}}
    if(isset($s['snapshot']))$db->query('DELETE FROM playlist_snapshots WHERE id='.(int)$s['snapshot']);
    if(isset($s['model'])){$stmt=$db->prepare('DELETE FROM llm_usage WHERE model=?');$stmt->bind_param('s',$s['model']);$stmt->execute();}
    if(isset($s['reaction_start']))$db->query('DELETE FROM stream_reactions WHERE id>'.(int)$s['reaction_start']);
    foreach($s['ids'] as $id){(new Domain\StreamReactions())->eraseUserData($id);Domain\Privacy::stageErasure($id,[]);$users->deleteUser($id);}
    foreach($s['options'] as $key=>$value){if($value!==null)$config->setOption($key,$value);else{$stmt=$db->prepare('DELETE FROM site_options WHERE key_name=?');$stmt->bind_param('s',$key);$stmt->execute();}}
    unlink($path);
}else{
    $s=json_decode(file_get_contents($path),true);
    if($mode==='correct'){
        $stmt=$db->prepare('UPDATE playlist_completions SET shown_json=JSON_ARRAY("single") WHERE completion_key=?');$stmt->bind_param('s',$s['run']);$stmt->execute();
        $key='it_ui_correct_'.$s['suffix'];$stmt=$db->prepare('INSERT IGNORE INTO playlist_corrections(snapshot_id,actor_id,operation_key,created_at,before_json,after_json,completion_key) VALUES(?,?,?,UTC_TIMESTAMP(),JSON_ARRAY("pair"),JSON_ARRAY("single"),?)');$stmt->bind_param('iiss',$s['snapshot'],$s['ids']['admin'],$key,$s['run']);$stmt->execute();
    }elseif($mode==='meter'){
        LLM\UsageMeter::capture('routerai',$s['model'],'{"usage":{"prompt_tokens":1000000,"completion_tokens":500000,"total_tokens":1500000}}',200,false,100,'chat');
    }elseif($mode==='ban'){
        $db->query('UPDATE users SET is_banned=1 WHERE id='.(int)$s['ids']['reader']);
    }else throw new RuntimeException('Unknown fixture mode');
}
ob_end_clean();echo "OK\n";
