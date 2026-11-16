<?php
require_once __DIR__.'/integration_helpers.php';
it_require_db()->close();
use Domain\EventManager;
use Domain\EpisodeManager;
use Infra\ConfigManager;

$db=Infra\Database::getInstance()->getConnection();$events=new EventManager();$episodes=new EpisodeManager();$cfg=ConfigManager::getInstance();
$ids=[];$jobs=[];$snapshot=0;$user=0;$episode=0;
$optionKeys="'stream_source','self_hosted_hls_url','stream_monitor_default_streamer','playlist_rollout_watermark','current_playlist_snapshot_id','current_playlist'";
$options=$db->query('SELECT key_name,value,updated_at FROM site_options WHERE key_name IN ('.$optionKeys.')')->fetch_all(MYSQLI_ASSOC);
$base=['title'=>'it_mlp471_'.bin2hex(random_bytes(5)),'description'=>'Fixture','start_time'=>'2030-01-10 22:30:00','duration_minutes'=>60,'is_recurring'=>1,'recurrence_rule'=>'weekly','use_playlist'=>0,'generate_new_playlist'=>0,'color'=>'#123456'];
try {
    check($events->create($base),'create without new fields remains valid');$id=$db->insert_id;$ids[]=$id;
    $raw=static fn()=>array_values(array_filter($events->getAllRaw(),static fn($r)=>(int)$r['id']===$id))[0];
    check($raw()['recurrence_until']===null && $raw()['recurrence_skip_dates']===null,'legacy create stores NULL restrictions');
    $data=$base+['recurrence_until'=>'2030-01-25','recurrence_skip_dates'=>['2030-01-18','2030-01-11','2030-01-11']];
    check($events->update($id,$data),'update accepts valid Moscow dates');
    check(json_decode($raw()['recurrence_skip_dates'],true)===['2030-01-11','2030-01-18'],'skips normalized, sorted and deduplicated');
    $events->update($id,$base);
    check($raw()['recurrence_until']==='2030-01-25' && count(json_decode($raw()['recurrence_skip_dates'],true))===2,'omitted fields retained on old-client update');
    foreach ([['recurrence_until'=>'2030-01-10'],['recurrence_until'=>['2030-01-25']],['recurrence_until'=>'2030-02-30'],['recurrence_skip_dates'=>'{}'],['recurrence_skip_dates'=>'null'],['recurrence_skip_dates'=>'broken'],['recurrence_skip_dates'=>['2030-01-12']],['recurrence_skip_dates'=>['2030-01-04']],['recurrence_skip_dates'=>['2030-02-01']],['recurrence_skip_dates'=>[['date'=>'2030-01-18']]]] as $invalid) {
        $before=$raw();
        try {$events->update($id,array_replace($data,$invalid));check(false,'invalid recurrence rejected');}
        catch(Core\UserError $e) {check($e->getMessage()!=='','invalid recurrence rejected with user error');}
        check($before===$raw(),'invalid update writes nothing');
    }
    $public=array_values(array_filter($events->getPublic(),static fn($r)=>(int)$r['id']===$id))[0];
    check($public['recurrence_until']==='2030-01-25' && json_decode($public['recurrence_skip_dates'],true)===['2030-01-11','2030-01-18'],'public API fields retain both restrictions');
    check(!isset($public['monitor_stream'],$public['streamer_user_id']),'public fields exclude internal monitor data');
    $expanded=EventManager::expandWithNextOccurrences([$raw()],7,strtotime('2030-01-10 UTC'));
    check(($expanded[0]['real_start_time']??0)===strtotime('2030-01-24 22:30 UTC'),'nearest real occurrence included beyond normal horizon');
    $snap=LLM\ScheduleData::snapshot($expanded,strtotime('2030-01-10 UTC'));
    check(($snap['next']['run_id']??'')===$id.'_'.strtotime('2030-01-24 22:30 UTC'),'schedule data selects real next event after multiple skips');
    $queue=new LLM\JobQueue();$llm=new LLM\LLMManager();
    $chatCount=(int)$db->query('SELECT COUNT(*) FROM chat_messages')->fetch_row()[0];
    foreach (['60m','15m','finished','playlist_finished'] as $kind) {
        $payload=['command'=>['handler_type'=>'schedule'],'event_run_id'=>$id.'_'.strtotime('2030-01-10 22:30 UTC'),'schedule_announcement'=>$kind,'prepared_reply'=>['text'=>'Must not send','quotes'=>[]]];
        $jobId=$queue->enqueueAnnouncement($payload);$jobs[]=$jobId;$job=['id'=>$jobId,'type'=>'dynamic_command','data'=>$payload,'attempts'=>0];
        if (in_array($kind,['finished','playlist_finished'],true)) LLM\ScheduleAnnouncement::processJob($llm,$queue,$job,true);
        else LLM\CommandReplyDelivery::processJob($llm,$queue,$job,true);
        check($db->query('SELECT status FROM llm_jobs WHERE id='.$jobId)->fetch_row()[0]==='done','queued '.$kind.' announcement cancelled before generation/delivery');
    }
    check((int)$db->query('SELECT COUNT(*) FROM chat_messages')->fetch_row()[0]===$chatCount,'excluded queued announcements never write chat');
    $events->update($id,array_replace($data,['is_recurring'=>0]));
    check($raw()['recurrence_until']===null && $raw()['recurrence_skip_dates']===null,'turning recurrence off clears restrictions');

    $users=new Domain\UserManager();$user=$users->createUser('it_mlp471_'.bin2hex(random_bytes(6)),bin2hex(random_bytes(20)),'streamer');Domain\Privacy::setAccountConsent($user,true);
    $cfg->setOption('stream_source','self_hosted');$cfg->setOption('self_hosted_hls_url','/live/evening/index.m3u8');
    $cfg->setOption('stream_monitor_default_streamer',(string)$user);
    $events->update($id,array_replace($data,['monitor_stream'=>1,'streamer_user_id'=>$user]));
    $monitor=new Domain\StreamMonitor(null,static fn()=>throw new RuntimeException('Probe must not run'),static fn()=>null,static fn()=>throw new RuntimeException('External send forbidden'));
    check(($monitor->active(strtotime('2030-01-10 22:35 UTC'))['id']??null)!=$id,'monitor excludes skipped base date');
    check((int)($monitor->active(strtotime('2030-01-24 22:35 UTC'))['id']??0)===$id,'monitor accepts remaining occurrence');
    check(($monitor->active(strtotime('2030-01-31 22:35 UTC'))['id']??null)!=$id,'monitor excludes dates after until');

    // Bind one future real event, then skip it and require transfer to the next actual run.
    $db->query("INSERT INTO episode_list(TITLE,LENGTH) VALUES('My Little Pony Friendship is Magic - Season 99 Episode 71 - Recurrence fixture',2)");$episode=$db->insert_id;
    $story=Domain\EpisodeCatalog::normalize(array_values(array_filter($episodes->getAllEpisodes(),static fn($r)=>(int)$r['ID']===$episode)))[0];
    $payload=json_encode([$story],JSON_THROW_ON_ERROR);$q=$db->prepare('INSERT INTO playlist_snapshots(created_at,payload_json,origin) VALUES(UTC_TIMESTAMP(),?,"test")');$q->bind_param('s',$payload);$q->execute();$snapshot=$db->insert_id;
    $cfg->setOption('current_playlist_snapshot_id',(string)$snapshot);$cfg->setOption('playlist_rollout_watermark','2030-01-01 00:00:00');
    $play=array_replace($data,['use_playlist'=>1,'recurrence_skip_dates'=>[],'recurrence_until'=>'2030-02-08']);$events->update($id,$play);
    $lifecycle=new Domain\PlaylistLifecycle();$before=strtotime('2030-01-10 20:00 UTC');$first=$id.'_'.strtotime('2030-01-10 22:30 UTC');
    $lifecycle->tick($before);
    $binding=static fn($run)=>$db->query("SELECT * FROM playlist_occurrences WHERE run_id='".$db->real_escape_string($run)."'")->fetch_assoc();
    check((int)($binding($first)['snapshot_id']??0)===$snapshot,'initial occurrence binds current snapshot');
    $play['recurrence_skip_dates']=['2030-01-11','2030-01-18'];$events->update($id,$play);$lifecycle->tick($before+1);
    $next=$id.'_'.strtotime('2030-01-24 22:30 UTC');
    check(($binding($first)['state']??'')==='cancelled','existing pending skipped occurrence cancelled');
    check((int)($binding($next)['snapshot_id']??0)===$snapshot && $binding($next)['state']==='pending','same snapshot bound to next actual run beyond seven days');
    check($binding($id.'_'.strtotime('2030-01-17 22:30 UTC'))===null,'no playlist binding for skipped middle date');
    $lifecycle->tick(strtotime('2030-01-11 00:00 UTC'));
    check($episodes->getOccurrenceOutcome($first)===null && $binding($first)['state']==='cancelled','skipped prebound run never completes after its old end');
    $play['recurrence_until']='2030-01-18';$events->update($id,$play);$lifecycle->tick($before+2);
    check($binding($next)['state']==='cancelled','until cancels prebound future run');
    $play['recurrence_until']='2030-02-08';$play['recurrence_skip_dates']=[];$events->update($id,$play);$lifecycle->tick($before+3);
    check($binding($first)['state']==='pending' && (int)$binding($first)['snapshot_id']===$snapshot,'removing skip restores same future run ID and snapshot');
} finally {
    foreach($jobs as $jobId)$db->query('DELETE FROM llm_jobs WHERE id='.(int)$jobId);
    foreach($ids as $eid){$db->query('DELETE FROM playlist_occurrences WHERE event_id='.(int)$eid);$events->delete((int)$eid);}
    if($snapshot)$db->query('DELETE FROM playlist_snapshots WHERE id='.(int)$snapshot);
    if($episode)$db->query('DELETE FROM episode_list WHERE ID='.(int)$episode);
    if($user){Domain\Privacy::stageErasure($user,[]);(new Domain\UserManager())->deleteUser($user);}
    $db->query('DELETE FROM site_options WHERE key_name IN ('.$optionKeys.')');
    $q=$db->prepare('INSERT INTO site_options(key_name,value,updated_at) VALUES(?,?,?)');foreach($options as $r){$q->bind_param('sss',$r['key_name'],$r['value'],$r['updated_at']);$q->execute();}$cfg->flushCache();
}
it_done();
