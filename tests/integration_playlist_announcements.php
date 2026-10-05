<?php
require_once __DIR__.'/integration_helpers.php';
if((it_config()['db']['host']??'')!=='db')it_skip('requires isolated db');
$probe=it_require_db();$probe->close();
$core=Infra\Database::getInstance()->getConnection();$manager=new Domain\EpisodeManager();
try {
 Infra\Transaction::run($core,function()use($core,$manager){
  $now=time();$cfg=Infra\ConfigManager::getInstance();
  $core->query("INSERT INTO episode_list(TITLE,LENGTH) VALUES('MLP361 announcement fixture',1)");$eid=$core->insert_id;
  $story=Domain\EpisodeCatalog::normalize(array_values(array_filter($manager->getAllEpisodes(),fn($row)=>(int)$row['ID']===$eid)))[0];
  $payload=json_encode([$story]);$stmt=$core->prepare('INSERT INTO playlist_snapshots(created_at,payload_json,origin) VALUES(UTC_TIMESTAMP(),?,"test")');$stmt->bind_param('s',$payload);$stmt->execute();$snapshot=$core->insert_id;
  $oldRun='announcement_old_'.bin2hex(random_bytes(4));
  $manager->bindOccurrence(['run_id'=>$oldRun,'id'=>999999,'real_start_time'=>$now-86400,'duration_minutes'=>1,'use_playlist'=>1,'generate_new_playlist'=>0],$snapshot);
  $manager->completeSnapshot($snapshot,$oldRun,null,$now-86340);
  check(!empty($manager->getSnapshot($snapshot)['completed_at']),'fixture snapshot completed in earlier occurrence');
  $start=gmdate('Y-m-d H:i:s',$now-180);
  $stmt=$core->prepare('INSERT INTO events(title,description,start_time,duration_minutes,is_recurring,recurrence_rule,use_playlist,generate_new_playlist,color) VALUES("MLP361 announcement","",?,1,0,"none",1,1,"#000000")');$stmt->bind_param('s',$start);$stmt->execute();$event=$core->insert_id;$run=$event.'_'.($now-180);
  $manager->bindOccurrence(['run_id'=>$run,'id'=>$event,'real_start_time'=>$now-180,'duration_minutes'=>1,'use_playlist'=>1,'generate_new_playlist'=>1],$snapshot);
  $manager->regeneratePlaylist();check($manager->getCurrentSnapshot()['id']!==$snapshot,'admin publishes unrelated newer snapshot');
  $worker=new LLM\BotWorker();$announcements=new ReflectionMethod($worker,'runAnnouncements');
  $instruction=function(string $run)use($cfg,$announcements,$worker,$core){$cfg->setOption('announced_events','{}');$announcements->invoke($worker);$s=$core->prepare('SELECT payload FROM llm_jobs WHERE JSON_UNQUOTE(JSON_EXTRACT(payload,"$.event_run_id"))=? ORDER BY id DESC LIMIT 1');$s->bind_param('s',$run);$s->execute();$row=$s->get_result()->fetch_assoc();return $row?json_decode($row['payload'],true)['message']:'';};
  $text=$instruction($run);check($text!==''&&!str_contains($text,'А вот и расписание на следующий раз'),'failed current run does not announce past completion as prepared');
  check(str_contains($text,'не подтверждена'),'failed run explicitly forbids false preparation claim to live model');
  $manager->finishOccurrence($run,$now);$outcome=$manager->getOccurrenceOutcome($run);
  check($outcome && (int)$outcome['generated_snapshot_id']>0,'current run has durable generation proof');
  $text=$instruction($run);check(str_contains($text,'А вот и расписание на следующий раз'),'successful current run announces prepared playlist');
  $stmt=$core->prepare('INSERT INTO events(title,description,start_time,duration_minutes,is_recurring,recurrence_rule,use_playlist,generate_new_playlist,color) VALUES("MLP361 gen-only","",?,1,0,"none",0,1,"#000000")');$stmt->bind_param('s',$start);$stmt->execute();$genEvent=$core->insert_id;$genRun=$genEvent.'_'.($now-180);
  $manager->bindOccurrence(['run_id'=>$genRun,'id'=>$genEvent,'real_start_time'=>$now-180,'duration_minutes'=>1,'use_playlist'=>0,'generate_new_playlist'=>1],0);
  $manager->finishOccurrence($genRun,$now);$genOutcome=$manager->getOccurrenceOutcome($genRun);
  check($genOutcome && !$genOutcome['view_accounted'] && (int)$genOutcome['generated_snapshot_id']>0,'gen-only proof records generation without watched effect');
  $text=$instruction($genRun);check(str_contains($text,'А вот и расписание на следующий раз'),'gen-only durable proof announces prepared playlist');
  throw new RuntimeException('announcement fixture rollback');
 });
}catch(RuntimeException $e){if($e->getMessage()!=='announcement fixture rollback')throw $e;}
finally{Infra\ConfigManager::getInstance()->flushCache();}
it_done();
