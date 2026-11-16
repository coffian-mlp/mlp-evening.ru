<?php
require_once __DIR__.'/integration_helpers.php';
it_require_db();$db=Infra\Database::getInstance()->getConnection();$m=new Domain\EpisodeManager();
if (($argv[1]??'')==='edit') {
    $params=json_decode($argv[2],true,512,JSON_THROW_ON_ERROR);
    try {$m->editUpcomingPlaylist($params['run_id'],$params['snapshot_id'],$params['version'],['operation'=>'add','episode_id'=>$params['episode_id']],$params['actor_id']);echo 'accepted';}
    catch(Core\UserError $e){echo str_contains($e->getMessage(),'другом окне')?'conflict':'unexpected:'.$e->getMessage();}
    exit;
}
$actor=0;$sid=0;$eid=0;$run='';$ids=[];
$options=$db->query("SELECT key_name,value,updated_at FROM site_options WHERE key_name IN ('current_playlist_snapshot_id','current_playlist')")->fetch_all(MYSQLI_ASSOC);
$events=$db->query('SELECT id,use_playlist FROM events')->fetch_all(MYSQLI_ASSOC);
try {
    $db->query('UPDATE events SET use_playlist=0');
    $actor=(new Domain\UserManager())->createUser('it_editor_race_'.bin2hex(random_bytes(5)),'Synthetic472!','admin');
    foreach(['Original','First','Second'] as $title){$db->query('INSERT INTO episode_list(TITLE,LENGTH) VALUES("it472 race '.$title.'",1)');$ids[]=$db->insert_id;}
    $stories=array_values(array_filter(Domain\EpisodeCatalog::normalize($m->getAllEpisodes()),fn($s)=>$s['ids']===[(int)$ids[0]]));$payload=json_encode($stories);
    $q=$db->prepare('INSERT INTO playlist_snapshots(created_at,payload_json,origin) VALUES(UTC_TIMESTAMP(),?,"test")');$q->bind_param('s',$payload);$q->execute();$sid=$db->insert_id;
    $start=time()+3600;$stamp=gmdate('Y-m-d H:i:s',$start);$q=$db->prepare('INSERT INTO events(title,start_time,duration_minutes,use_playlist) VALUES("it472 race",?,60,1)');$q->bind_param('s',$stamp);$q->execute();$eid=$db->insert_id;$run=$eid.'_'.$start;
    $m->bindOccurrence(['id'=>$eid,'run_id'=>$run,'real_start_time'=>$start,'duration_minutes'=>60,'use_playlist'=>1],$sid);
    Infra\ConfigManager::getInstance()->setOption('current_playlist_snapshot_id',(string)$sid);
    $state=$m->getPlaylistEditor();$children=[];
    // Queue two independent PHP/MySQL clients behind the real publication row.
    $db->begin_transaction();$db->query('SELECT value FROM site_options WHERE key_name="current_playlist_snapshot_id" FOR UPDATE');
    foreach([$ids[1],$ids[2]] as $id){
        $params=['run_id'=>$run,'snapshot_id'=>$sid,'version'=>$state['version'],'episode_id'=>$id,'actor_id'=>$actor];
        $pipes=[];$proc=proc_open([PHP_BINARY,__FILE__,'edit',json_encode($params)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($proc))throw new RuntimeException('Cannot start race client');fclose($pipes[0]);$children[]=[$proc,$pipes];
    }
    usleep(250000);$db->commit();$out=[];
    foreach($children as [$proc,$pipes]){$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($proc)===0,'race client exits successfully');$out[]=$stdout;if($stderr)echo $stderr;}
    sort($out);check($out===['accepted','conflict'],'two real competing edits: one accepted and one version conflict');
    check(count($m->getSnapshot($sid)['stories'])===2,'losing edit cannot overwrite accepted payload');
    check((int)$db->query('SELECT COUNT(*) n FROM audit_logs WHERE action="playlist_edit" AND user_id='.$actor)->fetch_assoc()['n']===1,'only accepted racing operation audited');
    $before=$m->getPlaylistEditor();
    // Fail the audit write after the payload update: the complete operation must roll back.
    $db->query('ALTER TABLE audit_logs ADD CONSTRAINT mlp472_fail_audit CHECK (action<>"playlist_edit" OR user_id<>'.$actor.' OR JSON_UNQUOTE(JSON_EXTRACT(details,"$.operation.operation"))<>"remove")');
    try {
        try{$m->editUpcomingPlaylist($run,$sid,$before['version'],['operation'=>'remove','index'=>0],$actor);check(false,'injected audit failure surfaced');}catch(mysqli_sql_exception $e){check(true,'injected audit failure surfaced');}
    } finally {$db->query('ALTER TABLE audit_logs DROP CHECK mlp472_fail_audit');}
    $after=$m->getPlaylistEditor();check($after['stories']===$before['stories'] && $after['version']===$before['version'],'audit failure rolls back payload and version atomically');
} finally {
    $db->rollback();
    if($run!==''){$q=$db->prepare('DELETE FROM playlist_occurrences WHERE run_id=?');$q->bind_param('s',$run);$q->execute();}
    if($sid)$db->query('DELETE FROM playlist_snapshots WHERE id='.$sid);
    if($eid)(new Domain\EventManager())->delete($eid);
    if($ids)$db->query('DELETE FROM episode_list WHERE ID IN ('.implode(',',$ids).')');
    if($actor){Domain\Privacy::stageErasure($actor,[]);(new Domain\UserManager())->deleteUser($actor);}
    $q=$db->prepare('UPDATE events SET use_playlist=? WHERE id=?');foreach($events as $r){$q->bind_param('ii',$r['use_playlist'],$r['id']);$q->execute();}
    $db->query("DELETE FROM site_options WHERE key_name IN ('current_playlist_snapshot_id','current_playlist')");$q=$db->prepare('INSERT INTO site_options(key_name,value,updated_at) VALUES(?,?,?)');foreach($options as $r){$q->bind_param('sss',$r['key_name'],$r['value'],$r['updated_at']);$q->execute();}
    Infra\ConfigManager::getInstance()->flushCache();
}
it_done();
