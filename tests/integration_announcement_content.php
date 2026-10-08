<?php
require_once __DIR__.'/integration_helpers.php';
if ((it_config()['db']['host'] ?? '') !== 'db') it_skip('requires isolated db');
it_require_db();
$db=Infra\Database::getInstance()->getConnection();
$db->begin_transaction();
try {
    $now=strtotime('2026-10-07 16:00:00 UTC');
    $start=strtotime('2026-10-10 16:00:00 UTC');
    $oldStart=$start-604800;$oldEnd=$oldStart+14400;
    $db->query("INSERT INTO events(title,description,start_time,duration_minutes,is_recurring,recurrence_rule,use_playlist,generate_new_playlist,color) VALUES('Announcement fixture','Fixture details','2026-10-03 16:00:00',240,1,'weekly',1,1,'#000000')");
    $eid=$db->insert_id;
    $db->query("INSERT INTO events(title,description,start_time,duration_minutes,is_recurring,recurrence_rule,use_playlist,generate_new_playlist,color) VALUES('Night fixture','Night details','2026-10-03 20:00:00',240,1,'weekly',0,0,'#000000')");
    $nid=$db->insert_id;
    $stories=[['ids'=>[1,2],'titles'=>['Part 1','Part 2'],'length'=>2]];
    $payload=json_encode($stories,JSON_THROW_ON_ERROR);
    $q=$db->prepare('INSERT INTO playlist_snapshots(created_at,payload_json,origin) VALUES(UTC_TIMESTAMP(),?,"test")');$q->bind_param('s',$payload);$q->execute();$sid=$db->insert_id;
    $events=Domain\EventManager::expandOccurrences((new Domain\EventManager())->getAllRaw(),21,$now);
    $target=null;
    foreach ($events as $e) if ((int)$e['id']===$eid && (int)$e['real_start_time']===$start) $target=$e;
    check($target!==null,'weekly expansion includes target and old base');
    foreach ([[$start,'pending'],[$oldStart,'completed']] as [$ts,$state]) {
        $run=$eid.'_'.$ts;$from=gmdate('Y-m-d H:i:s',$ts);$to=gmdate('Y-m-d H:i:s',$ts+14400);$out=$state==='completed' ? '{"view_accounted":true}' : null;
        $q=$db->prepare('INSERT INTO playlist_occurrences(run_id,event_id,start_at,end_at,snapshot_id,state,metadata_json,outcome_json) VALUES(?,?,?,?,?,?,"{}",?)');$q->bind_param('sississ',$run,$eid,$from,$to,$sid,$state,$out);$q->execute();
    }
    $q=$db->prepare('INSERT INTO chat_messages(user_id,username,message,created_at,is_deleted) VALUES(NULL,"Fixture",?,?,?)');
    $ids=[];
    foreach ([[$oldStart-1,'before',0],[$oldStart+1,'viewing evidence',0],[$oldEnd+1,'night evidence',0],[$oldEnd+2,'deleted evidence',1],[$oldEnd+14400,'after',0],[$now,'current excluded',0]] as [$ts,$text,$deleted]) {
        $at=gmdate('Y-m-d H:i:s',$ts);$q->bind_param('ssi',$text,$at,$deleted);$q->execute();$ids[$text]=$db->insert_id;
    }
    $content=new LLM\AnnouncementContent();$f=$content->facts($target,$now);
    check($f!==null && $f['snapshot']['id']===$sid,'real schema bound snapshot read');
    check((int)$f['night']['id']===$nid,'following target night included');
    check($f['previous']['run_id']===$eid.'_'.$oldStart,'last completed earlier viewing selected');
    $chatIds=array_column($f['chat'],'id');
    check(in_array($ids['viewing evidence'],$chatIds,true) && in_array($ids['night evidence'],$chatIds,true),'prior viewing and following night context included');
    check(!in_array($ids['before'],$chatIds,true) && !in_array($ids['after'],$chatIds,true) && !in_array($ids['deleted evidence'],$chatIds,true) && !in_array($ids['current excluded'],$chatIds,true),'outside window, deleted and current chat excluded');
    $nightMeta=json_encode(['use_playlist'=>0,'title'=>'Historic night','description'=>'Original'],JSON_THROW_ON_ERROR);
    $nightRun=$nid.'_'.$oldEnd;$nightFrom=gmdate('Y-m-d H:i:s',$oldEnd);$nightTo=gmdate('Y-m-d H:i:s',$oldEnd+14400);
    $q=$db->prepare('INSERT INTO playlist_occurrences(run_id,event_id,start_at,end_at,snapshot_id,state,metadata_json) VALUES(?,?,?,?,0,"completed",?)');$q->bind_param('sisss',$nightRun,$nid,$nightFrom,$nightTo,$nightMeta);$q->execute();
    $db->query('UPDATE events SET start_time="2026-10-03 21:00:00",duration_minutes=60 WHERE id='.$nid);
    $historic=$content->facts($target,$now);
    check($historic['previous']['night']['run_id']===$nightRun && in_array($ids['night evidence'],array_column($historic['chat'],'id'),true),'historical night binding survives live schedule changes');
    $db->query('UPDATE events SET duration_minutes=180 WHERE id='.$eid);
    check($content->facts($target,$now)===null,'live changed duration invalidates old persisted binding');
    $db->query('UPDATE events SET duration_minutes=240,is_recurring=0 WHERE id='.$eid);
    check($content->facts($target,$now)===null,'removed weekly target cannot fall back to stale passed occurrence');
} finally { $db->rollback(); }
it_done();
