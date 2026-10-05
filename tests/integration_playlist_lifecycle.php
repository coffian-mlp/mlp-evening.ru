<?php
require_once __DIR__.'/integration_helpers.php';
if((it_config()['db']['host']??'')!=='db')it_skip('requires isolated db');
$db=it_require_db();$m=new Domain\EpisodeManager();if(($argv[1]??'')==='import-race'){echo json_encode(['id'=>$m->importLegacySnapshot()['id']]);exit(0);}$core=Infra\Database::getInstance()->getConnection();$u=(new Domain\UserManager())->createUser('it_mlp361_life_'.bin2hex(random_bytes(5)),'Test361!Password','user');$ids=[];$snapshots=[];$runs=[];
try{
 foreach([1,2] as $i){$db->query("INSERT INTO episode_list(TITLE,LENGTH,TWOPART_ID) VALUES('My Little Pony Friendship is Magic - Season 99 Episode 0$i - Fixture$i',2,NULL)");$ids[]=$db->insert_id;}
 $db->query('UPDATE episode_list SET TWOPART_ID='.$ids[1].' WHERE ID='.$ids[0]);$db->query('UPDATE episode_list SET TWOPART_ID='.$ids[0].' WHERE ID='.$ids[1]);
 $story=Domain\EpisodeCatalog::normalize(array_values(array_filter($m->getAllEpisodes(),fn($r)=>in_array((int)$r['ID'],$ids,true))))[0];
 $stmt=$db->prepare('INSERT INTO playlist_snapshots(created_at,payload_json,origin) VALUES(UTC_TIMESTAMP(),?,"test")');$json=json_encode([$story]);$stmt->bind_param('s',$json);$stmt->execute();$snapshot=$db->insert_id;$snapshots[]=$snapshot;
 $options=$db->query("SELECT key_name,value,updated_at FROM site_options WHERE key_name IN ('current_playlist','current_playlist_snapshot_id')")->fetch_all(MYSQLI_ASSOC);$raceIds=[];
 try{
     $cfg=Infra\ConfigManager::getInstance();$cfg->setOption('current_playlist_snapshot_id','0');$legacy=$story;$legacy['ids']=array_reverse($story['ids']);$legacy['titles']=array_reverse($story['titles']);$cfg->setOption('current_playlist',json_encode([$legacy]));$db->query("UPDATE site_options SET updated_at='2026-07-20 17:24:33' WHERE key_name='current_playlist'");
     $children=[];for($i=0;$i<2;$i++){$pipes=[];$proc=proc_open([PHP_BINARY,__FILE__,'import-race'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$children[]=[$proc,$pipes];}
     foreach($children as [$proc,$pipes]){$text=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($proc)===0,'concurrent legacy importer succeeds');$result=json_decode($text,true);if(isset($result['id']))$raceIds[]=(int)$result['id'];}
     check(count($raceIds)===2 && $raceIds[0]===$raceIds[1],'concurrent legacy import creates one current snapshot');
 }finally{
     $db->query("DELETE FROM site_options WHERE key_name IN ('current_playlist','current_playlist_snapshot_id')");$restore=$db->prepare('INSERT INTO site_options(key_name,value,updated_at) VALUES(?,?,?)');foreach($options as $opt){$restore->bind_param('sss',$opt['key_name'],$opt['value'],$opt['updated_at']);$restore->execute();}foreach(array_unique($raceIds) as $rid)$db->query('DELETE FROM playlist_snapshots WHERE id='.$rid);Infra\ConfigManager::getInstance()->flushCache();
 }
 $now=strtotime('2026-10-20 10:00:00 UTC');$m->voteForEpisode($ids[0]);check((int)array_values(array_filter($m->getAllEpisodes(),fn($r)=>(int)$r['ID']===$ids[0]))[0]['WANNA_WATCH']===1,'anonymous adapter preserves separate contribution');$m->wish($u,$ids[0],'life:pre:'.$u,$now-10);$m->wish($u,$ids[1],'life:post:'.$u,$now+10);
 $run='test361:'.$u;$runs[]=$run;$m->bindOccurrence(['run_id'=>$run,'id'=>99999,'real_start_time'=>$now,'duration_minutes'=>60],$snapshot);
 try{Infra\Transaction::run($core,function()use($m,$snapshot,$run,$now){$m->completeSnapshot($snapshot,$run,null,$now+3600);throw new RuntimeException('injected rollback');});}catch(RuntimeException $e){}
 check((int)$db->query('SELECT TIMES_WATCHED FROM episode_list WHERE ID='.$ids[0])->fetch_assoc()['TIMES_WATCHED']===0,'transaction rollback');
 $db->query("ALTER TABLE watching_now ADD CONSTRAINT mlp361_fail_history CHECK (EPNUM<>".$ids[0].")");
 try{$m->completeSnapshot($snapshot,$run,null,$now+3600);check(false,'injected failure thrown');}catch(mysqli_sql_exception $e){check(true,'injected failure thrown');}finally{$db->query('ALTER TABLE watching_now DROP CHECK mlp361_fail_history');}
 check((int)$db->query('SELECT TIMES_WATCHED FROM episode_list WHERE ID='.$ids[0])->fetch_assoc()['TIMES_WATCHED']===0,'midwrite rollback');
 $m->completeSnapshot($snapshot,$run,null,$now+3600);$m->completeSnapshot($snapshot,$run,null,$now+3601);
 check((int)$db->query('SELECT TIMES_WATCHED FROM episode_list WHERE ID='.$ids[0])->fetch_assoc()['TIMES_WATCHED']===1,'completion retry once');
 check(count($m->getUserWishes($u))===1,'prestart fulfilled poststart retained');check((int)$db->query('SELECT legacy_wanna_watch FROM episode_list WHERE ID='.$ids[0])->fetch_assoc()['legacy_wanna_watch']===0,'legacy wish fulfilled');
 $m->correctCompletion($snapshot,[],$u,'correction:minus:'.$u);check(count($m->getUserWishes($u))===2,'correction restores wish');check((int)$db->query('SELECT legacy_wanna_watch FROM episode_list WHERE ID='.$ids[0])->fetch_assoc()['legacy_wanna_watch']===1,'correction restores legacy');
 $m->correctCompletion($snapshot,[$story['story_id']],$u,'correction:plus:'.$u);check(count($m->getUserWishes($u))===1,'correction fulfills again');
 $run2=$run.':second';$runs[]=$run2;$m->bindOccurrence(['run_id'=>$run2,'id'=>99999,'real_start_time'=>$now+604800,'duration_minutes'=>60],$snapshot);$m->completeSnapshot($snapshot,$run2,null,$now+608400);
 check((int)$db->query('SELECT TIMES_WATCHED FROM episode_list WHERE ID='.$ids[0])->fetch_assoc()['TIMES_WATCHED']===2,'same snapshot second real occurrence');
 check(count($m->getUserWishes($u))===0,'second occurrence fulfills later wish');
 $m->correctCompletion($snapshot,[],$u,'correction:second:'.$u,$run2);
 $active=$m->getUserWishes($u);check(count($active)===1 && (int)$active[0]['episode_id']===$ids[1],'second correction does not restore first occurrence wishes');check((int)$db->query('SELECT legacy_wanna_watch FROM episode_list WHERE ID='.$ids[0])->fetch_assoc()['legacy_wanna_watch']===0,'second correction does not restore first legacy wish');

 try{Infra\Transaction::run($core,function()use($m,$core,$story,$now,$runs){
     $cfg=Infra\ConfigManager::getInstance();$cfg->setOption('ai_enabled','0');$cfg->setOption('playlist_rollout_watermark',gmdate('Y-m-d H:i:s',$now-3600));
     $json=json_encode([$story]);$stmt=$core->prepare('INSERT INTO playlist_snapshots(created_at,payload_json,origin) VALUES(UTC_TIMESTAMP(),?,"test")');$stmt->bind_param('s',$json);$stmt->execute();$sid=$core->insert_id;
     $legacyStory=$story;$legacyStory['ids']=array_reverse($story['ids']);$legacyStory['titles']=array_reverse($story['titles']);
     $cfg->setOption('current_playlist_snapshot_id','0');$cfg->setOption('current_playlist',json_encode([$legacyStory]));$core->query("UPDATE site_options SET updated_at='2026-07-20 17:24:33' WHERE key_name='current_playlist'");
     $cfg->setOption('current_playlist',json_encode([$legacyStory,$legacyStory]));$beforeImport=(int)$core->query('SELECT COUNT(*) AS n FROM playlist_snapshots')->fetch_assoc()['n'];
     try{$m->importLegacySnapshot();check(false,'duplicate legacy rejected');}catch(RuntimeException $e){check(true,'duplicate legacy rejected');}
     check((int)$core->query('SELECT COUNT(*) AS n FROM playlist_snapshots')->fetch_assoc()['n']===$beforeImport,'invalid legacy not published');
     $cfg->setOption('current_playlist',json_encode([$legacyStory]));$core->query("UPDATE site_options SET updated_at='2026-07-20 17:24:33' WHERE key_name='current_playlist'");
     $imported=$m->importLegacySnapshot();check($imported['stories'][0]['ids']===$legacyStory['ids'] && $imported['stories'][0]['titles']===$legacyStory['titles'],'legacy import preserves raw order and titles');check($imported['created_at']==='2026-07-20 17:24:33','legacy import keeps original date');check($m->getSavedPlaylist()['_meta']['snapshot_id']===$imported['id'] && $m->getSavedPlaylist()['_meta']['is_old'],'legacy snapshot actionable and remains old');check($m->importLegacySnapshot()['id']===$imported['id'],'legacy import once');
     $cfg->setOption('current_playlist_snapshot_id',(string)$sid);
     $start=gmdate('Y-m-d H:i:s',$now-1800);$stmt=$core->prepare('INSERT INTO events(title,description,start_time,duration_minutes,is_recurring,recurrence_rule,use_playlist,generate_new_playlist,color) VALUES("Lifecycle fixture","",?,10,0,"none",1,1,"#000000")');$stmt->bind_param('s',$start);$stmt->execute();$eid=$core->insert_id;$run=$eid.'_'.($now-1800);
     $m->bindOccurrence(['run_id'=>$run,'id'=>$eid,'real_start_time'=>$now-1800,'duration_minutes'=>10,'generate_new_playlist'=>1,'use_playlist'=>1],$sid);
     (new Domain\PlaylistLifecycle())->tick($now);
     check($core->query("SELECT state FROM playlist_occurrences WHERE run_id='$run'")->fetch_assoc()['state']==='completed','AI0 delayed auto finish');
     $published=$m->getCurrentSnapshot()['id'];check($published!==$sid,'auto generates next snapshot');
     (new Domain\PlaylistLifecycle())->tick($now+1);check($m->getCurrentSnapshot()['id']===$published,'auto retry does not regenerate');
     $core->query("UPDATE events SET start_time='".gmdate('Y-m-d H:i:s',$now-3000)."' WHERE id=$eid");
     (new Domain\PlaylistLifecycle())->tick($now+1);$missed=$eid.'_'.($now-3000);check($core->query("SELECT state FROM playlist_occurrences WHERE run_id='$missed'")->fetch_assoc()['state']==='needs_attention','unbound missed start persisted');
     $stmt=$core->prepare('INSERT INTO events(title,description,start_time,duration_minutes,is_recurring,recurrence_rule,use_playlist,generate_new_playlist,color) VALUES("Gen only fixture","",?,10,0,"none",0,1,"#000000")');$genStart=gmdate('Y-m-d H:i:s',$now-900);$stmt->bind_param('s',$genStart);$stmt->execute();$genId=$core->insert_id;$genRun=$genId.'_'.($now-900);
     $viewsBefore=(int)$core->query('SELECT TIMES_WATCHED FROM episode_list WHERE ID='.$story['ids'][0])->fetch_assoc()['TIMES_WATCHED'];
     (new Domain\PlaylistLifecycle())->tick($now+2);$genOutcome=$m->getOccurrenceOutcome($genRun);
     check($genOutcome && !$genOutcome['view_accounted'] && $genOutcome['generated_snapshot_id']>0,'gen only late AI0 produces durable successor');
     check((int)$core->query('SELECT TIMES_WATCHED FROM episode_list WHERE ID='.$story['ids'][0])->fetch_assoc()['TIMES_WATCHED']===$viewsBefore,'gen only does not add views');
     (new Domain\PlaylistLifecycle())->tick($now+3);check($m->getOccurrenceOutcome($genRun)['generated_snapshot_id']===$genOutcome['generated_snapshot_id'],'gen only retry stable generated snapshot');
     $historicStart=gmdate('Y-m-d H:i:s',$now-7200);$stmt=$core->prepare('INSERT INTO events(title,description,start_time,duration_minutes,is_recurring,recurrence_rule,use_playlist,generate_new_playlist,color) VALUES("Historical gen fixture","",?,10,0,"none",0,1,"#000000")');$stmt->bind_param('s',$historicStart);$stmt->execute();$historicId=$core->insert_id;$historicalCurrent=$m->getCurrentSnapshot()['id'];
     (new Domain\PlaylistLifecycle())->tick($now+4);check($m->getOccurrenceOutcome($historicId.'_'.($now-7200))===null && $m->getCurrentSnapshot()['id']===$historicalCurrent,'watermark skips historical gen only');
     $core->query('DELETE FROM events WHERE id='.$historicId);
     $core->query('DELETE FROM events WHERE id='.$genId);
     $futureStart=$now+600;$core->query("UPDATE events SET start_time='".gmdate('Y-m-d H:i:s',$futureStart)."' WHERE id=$eid");
     (new Domain\PlaylistLifecycle())->tick($now+2);$futureRun=$eid.'_'.$futureStart;
     check($core->query("SELECT state FROM playlist_occurrences WHERE run_id='$futureRun'")->fetch_assoc()['state']==='pending','upcoming snapshot prebound');
     $core->query('UPDATE events SET duration_minutes=30 WHERE id='.$eid);(new Domain\PlaylistLifecycle())->tick($now+2);
     $revised=$core->query("SELECT end_at,metadata_json FROM playlist_occurrences WHERE run_id='$futureRun'")->fetch_assoc();$schedule=json_decode($revised['metadata_json'],true);
     check($revised['end_at']===gmdate('Y-m-d H:i:s',$futureStart+1800) && count($schedule['schedule_revisions']??[])===1,'upcoming duration revision retains audit');
     $m->regeneratePlaylist();$revision=json_decode($core->query("SELECT metadata_json FROM playlist_occurrences WHERE run_id='$futureRun'")->fetch_assoc()['metadata_json'],true);
     check(count($revision['snapshot_revisions']??[])===1,'explicit revision retains audit');check((int)$core->query("SELECT snapshot_id FROM playlist_occurrences WHERE run_id='$futureRun'")->fetch_assoc()['snapshot_id']===$m->getCurrentSnapshot()['id'],'upcoming binding follows explicit publication');
     $core->query("DELETE FROM events WHERE id=$eid");(new Domain\PlaylistLifecycle())->tick($now+3);
     check($core->query("SELECT state FROM playlist_occurrences WHERE run_id='$futureRun'")->fetch_assoc()['state']==='cancelled','deleted upcoming cancels accounting');
     foreach ([[0,1],[1,0]] as [$beforeUse,$afterUse]) {
         $toggleStart = $now + 300;
         $toggleDate = gmdate('Y-m-d H:i:s', $toggleStart);
         $stmt = $core->prepare('INSERT INTO events(title,description,start_time,duration_minutes,is_recurring,recurrence_rule,use_playlist,generate_new_playlist,color) VALUES("Toggle fixture","",?,10,0,"none",?,1,"#000000")');
         $stmt->bind_param('si',$toggleDate,$beforeUse);$stmt->execute();$toggleId=$core->insert_id;$toggleRun=$toggleId.'_'.$toggleStart;
         (new Domain\PlaylistLifecycle())->tick($now+4);
         $core->query('UPDATE events SET use_playlist='.$afterUse.' WHERE id='.$toggleId);
         (new Domain\PlaylistLifecycle())->tick($now+5);
         $toggleRecord = $core->query("SELECT snapshot_id,metadata_json FROM playlist_occurrences WHERE run_id='$toggleRun'")->fetch_assoc();
         if ($afterUse) {
             check((int)$toggleRecord['snapshot_id']>0,'gen only changed to viewing binds snapshot');
             $toggleMetadata=json_decode($toggleRecord['metadata_json'],true);
             check(count($toggleMetadata['snapshot_revisions']??[])>=1,'gen only to viewing binding retains audit');
         }
         $viewCount=(int)$core->query('SELECT COUNT(*) AS n FROM playlist_completions')->fetch_assoc()['n'];
         (new Domain\PlaylistLifecycle())->tick($toggleStart+601);
         $toggleOutcome=$m->getOccurrenceOutcome($toggleRun);
         check($toggleOutcome && $toggleOutcome['view_accounted']===(bool)$afterUse && $toggleOutcome['generated_snapshot_id']>0,'changed calendar flags complete correctly '.$beforeUse.' to '.$afterUse);
         check((int)$core->query('SELECT COUNT(*) AS n FROM playlist_completions')->fetch_assoc()['n']===$viewCount+$afterUse,'changed calendar flags count optional view '.$beforeUse.' to '.$afterUse);
         $core->query('DELETE FROM events WHERE id='.$toggleId);
     }
     $chainNow=$now+86400*22;$chainStart=$chainNow-86400*21-3600;foreach($runs as $manualRun){$escaped=$core->real_escape_string($manualRun);$core->query("UPDATE playlist_occurrences SET state='cancelled' WHERE run_id='$escaped'");}$cfg->setOption('playlist_rollout_watermark',gmdate('Y-m-d H:i:s',$chainStart));
     $stmt=$core->prepare('INSERT INTO events(title,description,start_time,duration_minutes,is_recurring,recurrence_rule,use_playlist,generate_new_playlist,color) VALUES("Catchup fixture","",?,10,1,"weekly",1,1,"#000000")');$chainDate=gmdate('Y-m-d H:i:s',$chainStart);$stmt->bind_param('s',$chainDate);$stmt->execute();$chainId=$core->insert_id;
     $m->bindOccurrence(['run_id'=>$chainId.'_'.$chainStart,'id'=>$chainId,'real_start_time'=>$chainStart,'duration_minutes'=>10,'generate_new_playlist'=>1,'use_playlist'=>1],$sid);
     (new Domain\PlaylistLifecycle())->tick($chainNow);
     $previousId=null;for($week=0;$week<4;$week++){$run=$chainId.'_'.($chainStart+604800*$week);$outcome=$m->getOccurrenceOutcome($run);check($outcome && $outcome['view_accounted'] && $outcome['generated_snapshot_id']>0,'multiweek known catchup '.$week);if($previousId!==null)check($outcome['snapshot_id']===$previousId,'catchup binds predecessor successor '.$week);$previousId=$outcome['generated_snapshot_id'];}
     $lastCurrent=$m->getCurrentSnapshot()['id'];(new Domain\PlaylistLifecycle())->tick($chainNow+1);check($m->getCurrentSnapshot()['id']===$lastCurrent,'multiweek catchup retry no new generation');
     throw new RuntimeException('fixture rollback');
 });}catch(RuntimeException $e){if($e->getMessage()!=='fixture rollback')throw $e;}finally{Infra\ConfigManager::getInstance()->flushCache();}
}finally{
 foreach($runs as $r){$escaped=$db->real_escape_string($r);$db->query("DELETE FROM playlist_completions WHERE run_id='$escaped'");$db->query("DELETE FROM playlist_occurrences WHERE run_id='$escaped'");}
 foreach($snapshots as $s){$db->query('DELETE FROM playlist_corrections WHERE snapshot_id='.(int)$s);$db->query('DELETE FROM playlist_snapshots WHERE id='.(int)$s);}
 $db->query('DELETE FROM episode_wish_events WHERE user_id='.(int)$u);$db->query('DELETE FROM episode_wishes WHERE user_id='.(int)$u);$db->query('DELETE FROM episode_wish_locks WHERE user_id='.(int)$u);foreach($ids as $id){$db->query('DELETE FROM episode_wish_events WHERE episode_id='.(int)$id);$db->query('DELETE FROM watching_now WHERE EPNUM='.(int)$id);$db->query('DELETE FROM episode_list WHERE ID='.(int)$id);}$db->query('DELETE FROM users WHERE id='.(int)$u);
}
it_done();
