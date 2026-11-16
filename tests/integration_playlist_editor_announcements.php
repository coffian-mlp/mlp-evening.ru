<?php
require_once __DIR__.'/integration_helpers.php';
it_require_db();$db=Infra\Database::getInstance()->getConnection();$m=new Domain\EpisodeManager();
try {
    Infra\Transaction::run($db, function()use($db,$m){
        $db->query('UPDATE events SET use_playlist=0');
        $actor=(new Domain\UserManager())->createUser('it_editor_ann_'.bin2hex(random_bytes(5)),'Synthetic472!','admin');
        $ids=[];foreach(['Alpha','Beta','Gamma'] as $name){$db->query('INSERT INTO episode_list(TITLE,LENGTH) VALUES("it472 announce '.$name.'",1)');$ids[]=$db->insert_id;}
        $stories=array_values(array_filter(Domain\EpisodeCatalog::normalize($m->getAllEpisodes()),fn($s)=>in_array($s['ids'][0],$ids,true)));
        $payload=json_encode([$stories[0]]);$q=$db->prepare('INSERT INTO playlist_snapshots(created_at,payload_json,origin) VALUES(UTC_TIMESTAMP(),?,"test")');$q->bind_param('s',$payload);$q->execute();$sid=$db->insert_id;
        $now=time();$start=$now+2*86400;$stamp=gmdate('Y-m-d H:i:s',$start);$q=$db->prepare('INSERT INTO events(title,start_time,duration_minutes,use_playlist,is_recurring,recurrence_rule) VALUES("it472 announcement",?,120,1,1,"weekly")');$q->bind_param('s',$stamp);$q->execute();$eid=$db->insert_id;$run=$eid.'_'.$start;
        $m->bindOccurrence(['id'=>$eid,'run_id'=>$run,'real_start_time'=>$start,'duration_minutes'=>120,'use_playlist'=>1,'is_recurring'=>1,'recurrence_rule'=>'weekly'],$sid);
        Infra\ConfigManager::getInstance()->setOption('current_playlist_snapshot_id',(string)$sid);
        $event=static function()use($eid,$now){foreach(Domain\EventManager::expandOccurrences((new Domain\EventManager())->getAllRaw(),7,$now) as $e)if((int)$e['id']===$eid)return $e;throw new RuntimeException('Missing event');};
        $content=new LLM\AnnouncementContent();$store=new Domain\AnnouncementStore();$facts=$content->facts($event(),$now);$schedule=Domain\AnnouncementSchedule::calculate($event());
        $campaign=$store->ensure($facts,LLM\AnnouncementContent::fingerprint($facts),$schedule,1);
        $db->query('UPDATE telegram_announcement_revisions r JOIN telegram_announcement_posts p ON p.id=r.post_id SET r.caption="Prepared announcement",r.state="approved" WHERE p.campaign_id='.$campaign);
        $revisions=static fn()=>$db->query('SELECT r.id,r.state,r.caption,r.version FROM telegram_announcement_revisions r JOIN telegram_announcement_posts p ON p.id=r.post_id WHERE p.campaign_id='.$campaign.' ORDER BY r.id')->fetch_all(MYSQLI_ASSOC);
        $before=$revisions();$state=$m->getPlaylistEditor();check($state['announcement_prepared'],'prepared announcement warning');
        $state=$m->editUpcomingPlaylist($run,$sid,$state['version'],['operation'=>'replace','index'=>0,'episode_id'=>$ids[1]],$actor);
        $after=$content->facts($event(),$now);check(LLM\AnnouncementContent::fingerprint($after)===LLM\AnnouncementContent::fingerprint($facts),'announcement fingerprint frozen after edit');
        $store->ensure($after,LLM\AnnouncementContent::fingerprint($after),$schedule,1);check($revisions()===$before,'scheduled ensure leaves approved announcement and all revisions unchanged');
        $state=$m->editUpcomingPlaylist($run,$sid,$state['version'],['operation'=>'add','episode_id'=>$ids[2]],$actor);
        $after=$content->facts($event(),$now);$store->ensure($after,LLM\AnnouncementContent::fingerprint($after),$schedule,1);check($revisions()===$before,'repeated editor save leaves prepared announcements unchanged');
        check($m->getOccurrenceSnapshot($run)['stories']!==$facts['snapshot']['stories'],'LLM schedule source sees edited bound playlist independently of announcement');
        while ($state['stories']) $state=$m->editUpcomingPlaylist($run,$sid,$state['version'],['operation'=>'remove','index'=>0],$actor);
        $empty=$content->facts($event(),$now);check($empty && LLM\AnnouncementContent::fingerprint($empty)===LLM\AnnouncementContent::fingerprint($facts),'empty edited playlist keeps prepared announcement facts');
        $store->ensure($empty,LLM\AnnouncementContent::fingerprint($empty),$schedule,1);check($revisions()===$before,'removing all stories does not cancel or revise prepared announcement');
        $telegram=new Social\TelegramBotClient('123456:'.str_repeat('x',30),static function(){throw new RuntimeException('Unexpected announcement transport');});
        $worker=new LLM\AnnouncementWorker($store,$telegram,$content,0,'',$eid,1);
        $post=(int)$db->query('SELECT current_revision FROM telegram_announcement_posts WHERE campaign_id='.$campaign.' LIMIT 1')->fetch_assoc()['current_revision'];
        $fresh=new ReflectionMethod($worker,'fresh');check($fresh->invoke($worker,$store->revision($post),$now)!==null && $revisions()===$before,'worker freshness check retains prepared post after empty edit');
        $db->query('UPDATE events SET title="it472 changed calendar" WHERE id='.$eid);
        $changed=$content->facts($event(),$now);check(LLM\AnnouncementContent::fingerprint($changed)!==LLM\AnnouncementContent::fingerprint($facts),'calendar edit still changes announcement fingerprint');
        $store->ensure($changed,LLM\AnnouncementContent::fingerprint($changed),$schedule,1);check(count($revisions())===count($before)+2,'calendar edit preserves normal announcement invalidation');
        $m->regeneratePlaylist();$fresh=$content->facts($event(),$now);check($fresh['snapshot']['id']!==$sid && $fresh['snapshot']['id']===$m->getOccurrenceSnapshot($run)['id'],'normal regeneration releases announcement snapshot freeze');
        $newState=$m->getPlaylistEditor();$m->editUpcomingPlaylist($run,$newState['snapshot_id'],$newState['version'],['operation'=>'remove','index'=>0],$actor);
        $freshAfterEdit=$content->facts($event(),$now);
        check(LLM\AnnouncementContent::fingerprint($freshAfterEdit)===LLM\AnnouncementContent::fingerprint($fresh),'edit before worker tick retains regenerated pre-edit snapshot, not stale campaign');
        $fresh=$freshAfterEdit;
        $n=count($revisions());$store->ensure($fresh,LLM\AnnouncementContent::fingerprint($fresh),$schedule,1);check(count($revisions())===$n+2,'normal regeneration preserves normal announcement invalidation');
        throw new RuntimeException('rollback editor announcements');
    });
} catch(RuntimeException $e){if($e->getMessage()!=='rollback editor announcements')throw $e;}
finally{Infra\ConfigManager::getInstance()->flushCache();}
it_done();
