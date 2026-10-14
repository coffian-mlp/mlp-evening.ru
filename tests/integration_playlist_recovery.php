<?php
require_once __DIR__ . '/integration_helpers.php';
if ((it_config()['db']['host'] ?? '') !== 'db') it_skip('Requires isolated DB_HOST=db');
it_require_db(); $db=Infra\Database::getInstance()->getConnection();
$db->begin_transaction();
try {
    $m=new Domain\EpisodeManager(); $cfg=Infra\ConfigManager::getInstance();
    $now=strtotime('2026-10-20 10:00:00 UTC'); $start=$now+3600;
    $cfg->setOption('playlist_rollout_watermark', gmdate('Y-m-d H:i:s',$now-86400));
    $db->query('UPDATE events SET use_playlist=0,generate_new_playlist=0');
    $ids=[];
    foreach ([1,2] as $part) { $db->query("INSERT INTO episode_list(TITLE,LENGTH) VALUES('My Little Pony Friendship is Magic - Season 98 Episode 0$part - Recovery fixture',1)"); $ids[]=$db->insert_id; }
    $db->query('UPDATE episode_list SET TWOPART_ID='.$ids[1].' WHERE ID='.$ids[0]); $db->query('UPDATE episode_list SET TWOPART_ID='.$ids[0].' WHERE ID='.$ids[1]);
    $story=Domain\EpisodeCatalog::normalize(array_values(array_filter($m->getAllEpisodes(),fn($r)=>in_array((int)$r['ID'],$ids,true))))[0];
    $json=json_encode([$story]); $q=$db->prepare('INSERT INTO playlist_snapshots(created_at,payload_json,origin) VALUES(UTC_TIMESTAMP(),?,"test")');$q->bind_param('s',$json);$q->execute();$sid=$q->insert_id;
    $cfg->setOption('current_playlist_snapshot_id',(string)$sid);
    $date=gmdate('Y-m-d H:i:s',$start);$q=$db->prepare('INSERT INTO events(title,description,start_time,duration_minutes,is_recurring,use_playlist,generate_new_playlist,color) VALUES("Recovery","",?,10,0,1,1,"#000000")');$q->bind_param('s',$date);$q->execute();$event=$q->insert_id;$run=$event.'_'.$start;
    $life=new Domain\PlaylistLifecycle(); $life->tick($now);
    $state=fn()=> $db->query("SELECT * FROM playlist_occurrences WHERE run_id='$run'")->fetch_assoc();
    check($state()['state']==='pending','future event bound');
    $db->query("UPDATE events SET use_playlist=0,generate_new_playlist=0 WHERE id=$event");$life->tick($now+1);
    check($state()['state']==='cancelled','disabled future event cancelled');
    $db->query("UPDATE events SET use_playlist=1,generate_new_playlist=1 WHERE id=$event");$life->tick($now+2);
    check($state()['state']==='pending','re-enabled future event restored');
    check(!empty(json_decode($state()['metadata_json'],true)['state_revisions']),'restoration recorded in binding history');
    $db->query("UPDATE events SET start_time=DATE_ADD(start_time,INTERVAL 1 DAY) WHERE id=$event");$life->tick($now+3);
    check($state()['state']==='cancelled','moved future occurrence cancelled');
    $db->query("UPDATE events SET start_time='$date' WHERE id=$event");$life->tick($now+4);
    check($state()['state']==='pending','moving event back restores original run');
    $m->setOccurrenceState($run,'cancelled');$life->tick($now+5);
    check($state()['state']==='cancelled','explicit cancellation is not restored');
    // Legacy calendar cancellations lacked source metadata.
    $db->query("UPDATE playlist_occurrences SET metadata_json=JSON_REMOVE(metadata_json,'$.cancellation_source') WHERE run_id='$run'");$life->tick($now+6);
    check($state()['state']==='pending','legacy future calendar cancellation is recoverable');
    $u=(new Domain\UserManager())->createUser('it_arch395_'.bin2hex(random_bytes(6)),'Test-395!','user');
    $m->wish($u,$ids[0],'arch395:wish:'.$u,$now);
    $db->query("INSERT INTO episode_wish_events(operation_key,user_id,episode_id,kind,status,created_at,outcome_json) VALUES('arch395:legacy:$u',NULL,".$ids[0].",'legacy','active','".gmdate('Y-m-d H:i:s',$now)."','{\"count\":1}')");
    $life->tick($start+601);
    check(!empty($m->getOccurrenceOutcome($run)['view_accounted']), 'restored occurrence automatically accounts its viewing');
    $runB='arch395:B:'.$u;$m->bindOccurrence(['run_id'=>$runB,'id'=>$event,'real_start_time'=>$start+86400,'duration_minutes'=>10],$sid);
    $m->completeSnapshot($sid,$runB,null,$start+87000);
    $m->correctCompletion($sid,[],$u,'arch395:correct:A:'.$u,$run);
    $wish=$db->query('SELECT status,fulfilled_completion_key FROM episode_wishes WHERE user_id='.$u)->fetch_assoc();
    check($wish['status']==='fulfilled' && $wish['fulfilled_completion_key']===$runB,'correcting A keeps wish fulfilled by B');
    $legacy=$db->query("SELECT status,fulfilled_completion_key FROM episode_wish_events WHERE operation_key='arch395:legacy:$u'")->fetch_assoc();
    check($legacy['status']==='fulfilled' && $legacy['fulfilled_completion_key']===$runB,'legacy wish also stays fulfilled by B');
    $m->correctCompletion($sid,[],$u,'arch395:correct:B:'.$u,$runB);
    check(count($m->getUserWishes($u))===1,'wish restored only after last matching showing is removed');
    check($db->query("SELECT status FROM episode_wish_events WHERE operation_key='arch395:legacy:$u'")->fetch_assoc()['status']==='active','legacy wish restored after last showing');
} finally {$db->rollback();Infra\ConfigManager::getInstance()->flushCache();}
it_done();
