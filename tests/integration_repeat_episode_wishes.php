<?php
require_once __DIR__.'/integration_helpers.php';
if((it_config()['db']['host']??'')!=='db')it_skip('isolated database required');
$db=it_require_db();$m=new Domain\EpisodeManager();
if(($argv[1]??'')==='race'){echo json_encode($m->wish((int)$argv[2],(int)$argv[3],$argv[4],(int)$argv[5]));exit;}
require_once dirname(__DIR__).'/migrate.php';
$table='it_wish436_'.bin2hex(random_bytes(5));$sql=tempnam(sys_get_temp_dir(),'wish436');
try {
    $db->query("CREATE TABLE $table(user_id INT NOT NULL,episode_id INT NOT NULL,status VARCHAR(20) NOT NULL,accepted_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,fulfilled_snapshot_id INT NULL,fulfilled_completion_key VARCHAR(100) NULL,PRIMARY KEY(user_id,episode_id),INDEX(status,episode_id)) ENGINE=InnoDB");
    $db->query("INSERT INTO $table(user_id,episode_id,status,accepted_at,updated_at,fulfilled_snapshot_id,fulfilled_completion_key) VALUES(1,1,'active','2026-10-01','2026-10-01',NULL,NULL),(1,2,'fulfilled','2026-10-02','2026-10-03',4,'old-showing'),(2,1,'privacy_suspended','2026-10-01','2026-10-04',NULL,NULL)");
    $old=$db->query("SELECT * FROM $table ORDER BY user_id,episode_id")->fetch_all(MYSQLI_ASSOC);
    file_put_contents($sql,str_replace('episode_wishes',$table,file_get_contents(dirname(__DIR__).'/migrations/2026_10_24_repeat_episode_wishes.sql')));
    migrate_apply_file($db,$sql);migrate_apply_file($db,$sql);
    $new=$db->query("SELECT * FROM $table ORDER BY user_id,episode_id")->fetch_all(MYSQLI_ASSOC);
    foreach($new as &$r)unset($r['id']);unset($r);
    check($new===$old,'idempotent migration preserves every old wish, status, timestamp and completion');
    $db->query("INSERT INTO $table(user_id,episode_id,status,accepted_at,updated_at) VALUES(1,1,'active','2026-10-08','2026-10-08')");
    check((int)$db->query("SELECT COUNT(*) n FROM $table WHERE user_id=1 AND episode_id=1")->fetch_assoc()['n']===2,'migrated schema accepts separate votes for one owner and episode');
}finally{$db->query("DROP TABLE IF EXISTS $table");unlink($sql);}
$users=[];$ids=[];$sid=null;$runs=[];
$counts=static fn($id)=>(int)$db->query('SELECT COUNT(*) n FROM episode_wishes WHERE episode_id='.(int)$id.' AND status="active"')->fetch_assoc()['n'];
try{
    foreach([1,2] as $i){$users[]=(new Domain\UserManager())->createUser('it436_'.bin2hex(random_bytes(5)),'Test436!password','user');$db->query("INSERT INTO episode_list(TITLE,LENGTH) VALUES('Weekly vote fixture $i',1)");$ids[]=(int)$db->insert_id;}
    [$u,$other]=$users;[$a,$b]=$ids;$now=time()+60;$week=604800;
    $first=$m->wish($u,$a,'436:first:'.$u,$now-2*$week);
    check($first['status']==='accepted'&&$m->wish($u,$a,'436:early:'.$u,$now-$week-1)['code']==='cooldown','168 hours minus one second rejects');
    check($m->wish($u,$a,'436:second:'.$u,$now-$week)['status']==='accepted'&&$counts($a)===2,'exactly 168 hours adds another independent vote');
    $children=[];
    foreach([1,2] as $i){$pipes=[];$p=proc_open([PHP_BINARY,__FILE__,'race',(string)$u,(string)$a,'436:race:'.$u.':'.$i,(string)$now],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$children[]=[$p,$pipes];}
    $accepted=0;$cooldown=0;
    foreach($children as [$p,$pipes]){$out=json_decode(stream_get_contents($pipes[1]),true);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0||$err!=='')throw new RuntimeException('Race worker failed');$accepted+=($out['code']??'')==='accepted';$cooldown+=($out['code']??'')==='cooldown';}
    check($accepted===1&&$cooldown===1&&$counts($a)===3,'simultaneous weekly votes accept exactly one');
    check($m->wish($u,$a,'436:first:'.$u,$now)==$first&&$counts($a)===3,'old operation replay never adds a vote');
    $mine=$m->getUserWishes($u);check(count($mine)===1&&(int)$mine[0]['votes']===3,'personal list groups three votes into one episode');
    $catalog=array_column((new Domain\EpisodeRatingManager())->getCatalogueProjection($u)['rows'],null,'id');
    check($catalog[$a]['wishes']===3&&$catalog[$a]['own_votes']===3&&$catalog[$a]['own_next_allowed_at']===gmdate('Y-m-d\TH:i:s\Z',$now+$week),'catalogue exposes cumulative and own votes plus the next server deadline');
    check(count($catalog)===count($m->getAllEpisodes()),'multiple own votes do not duplicate catalogue rows');
    $guest=array_column((new Domain\EpisodeRatingManager())->getCatalogueProjection()['rows'],null,'id');
    check($guest[$a]['own_votes']===0&&$guest[$a]['own_next_allowed_at']===null,'guest sees no personal vote history');
    $all=array_column($m->getAllEpisodes(),null,'ID');check((int)$all[$a]['WANNA_WATCH']===3,'playlist receives all three votes as weight');
    $m->wish($other,$a,'436:other1:'.$u,$now-$week);$m->wish($other,$a,'436:other2:'.$u,$now);
    $m->cancelWish($u,$a,'436:cancel:'.$u,$now+1);
    check($counts($a)===2&&$m->getWishQuota($u,$now)['used']===1&&$m->wish($u,$a,'436:cancel-early:'.$u,$now+2)['code']==='cooldown','cancel removes every own vote, preserving others and both limits');
    check($m->wish($u,$a,'436:aftercancel:'.$u,$now+$week)['code']==='accepted'&&$counts($a)===3,'new vote after cancelled wishes is independent');
    $m->wish($u,$b,'436:before1:'.$u,$now-2*$week);$m->wish($u,$b,'436:before2:'.$u,$now-$week);$m->wish($u,$b,'436:afterstart:'.$u,$now+1);
    $story=Domain\EpisodeCatalog::normalize(array_values(array_filter($m->getAllEpisodes(),static fn($r)=>(int)$r['ID']===$b)))[0];
    $json=json_encode([$story],JSON_THROW_ON_ERROR);$q=$db->prepare('INSERT INTO playlist_snapshots(created_at,payload_json,origin) VALUES(UTC_TIMESTAMP(),?,"test")');$q->bind_param('s',$json);$q->execute();$sid=(int)$db->insert_id;
    $run='436:show:'.$u;$runs[]=$run;$m->bindOccurrence(['run_id'=>$run,'id'=>999436,'real_start_time'=>$now,'duration_minutes'=>60],$sid);
    $m->completeSnapshot($sid,$run,null,$now+3600);
    check($counts($b)===1&&(int)$db->query('SELECT COUNT(*) n FROM episode_wishes WHERE episode_id='.$b.' AND status="fulfilled"')->fetch_assoc()['n']===2,'view fulfills both older votes and retains the vote after the start');
    $m->correctCompletion($sid,[],$u,'436:minus:'.$u,$run);check($counts($b)===3,'correction restores individual votes without overwriting the newer vote');
    $m->correctCompletion($sid,[$story['story_id']],$u,'436:plus:'.$u,$run);check($counts($b)===1,'correction adding a showing again respects each vote date');
    $runB=$run.':B';$runs[]=$runB;$m->bindOccurrence(['run_id'=>$runB,'id'=>999436,'real_start_time'=>$now+$week,'duration_minutes'=>60],$sid);$m->completeSnapshot($sid,$runB,null,$now+$week+3600);
    $m->correctCompletion($sid,[],$u,'436:minus-again:'.$u,$run);check($counts($b)===0,'another surviving showing keeps all three votes fulfilled');
    $m->correctCompletion($sid,[],$u,'436:minus-B:'.$u,$runB);check($counts($b)===3,'removing the final showing restores all three votes');
    $m->suspendPrivacy($u,true);check($counts($b)===0,'privacy withdrawal excludes all accumulated votes');
    $m->suspendPrivacy($u,false);check($counts($b)===3,'privacy renewal restores all accumulated votes');
    $baseline=(int)$db->query('SELECT COUNT(*) n FROM episode_wishes WHERE user_id='.$u)->fetch_assoc()['n'];
    try{Infra\Transaction::run(Infra\Database::getInstance()->getConnection(),function()use($m,$u,$b,$now,$week){$m->wish($u,$b,'436:rollback:'.$u,$now+2*$week);throw new RuntimeException('injected');});}catch(RuntimeException $e){if($e->getMessage()!=='injected')throw $e;}
    check((int)$db->query('SELECT COUNT(*) n FROM episode_wishes WHERE user_id='.$u)->fetch_assoc()['n']===$baseline,'failed transaction leaves no extra vote');
    $m->eraseUserData($u);check((int)$db->query('SELECT COUNT(*) n FROM episode_wishes WHERE user_id='.$u)->fetch_assoc()['n']===0&&$counts($a)===2,'erasure removes all own votes without affecting another voter');
}finally{
    foreach($runs as $run){$q=$db->prepare('DELETE FROM playlist_occurrences WHERE run_id=?');$q->bind_param('s',$run);$q->execute();}
    if($sid)foreach(['playlist_corrections','playlist_completions'] as $table)$db->query('DELETE FROM '.$table.' WHERE snapshot_id='.$sid);
    if($sid)$db->query('DELETE FROM playlist_snapshots WHERE id='.$sid);
    foreach($users as $u){$m->eraseUserData($u);(new Domain\UserManager())->deleteUser($u);}
    foreach($ids as $id){$db->query('DELETE FROM watching_now WHERE EPNUM='.$id);$db->query('DELETE FROM episode_list WHERE ID='.$id);}
}
it_done();
