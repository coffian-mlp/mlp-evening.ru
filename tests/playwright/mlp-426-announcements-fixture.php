<?php
require_once dirname(__DIR__).'/integration_helpers.php';
if (PHP_SAPI!=='cli' || !is_file('/.dockerenv') || (it_config()['db']['host']??'')!=='db') throw new RuntimeException('Isolated Docker database required');
$db=it_require_db();$config=Infra\ConfigManager::getInstance();
$path=dirname(__DIR__,2).'/docs/private/mlp426-local.json';$mode=$argv[1]??'';
if($mode==='setup') {
    if(is_file($path)) throw new RuntimeException('Cleanup previous fixture first');
    $start=(new DateTimeImmutable('next Saturday 19:00',new DateTimeZone('Europe/Moscow')))->getTimestamp();
    $date=gmdate('Y-m-d H:i:s',$start);$end=gmdate('Y-m-d H:i:s',$start+14400);
    $q=$db->prepare('INSERT INTO events(title,description,start_time,duration_minutes,is_recurring,recurrence_rule,use_playlist,generate_new_playlist) VALUES("MLP-426 fixture","fixture",?,240,1,"weekly",1,0)');$q->bind_param('s',$date);$q->execute();$eventId=(int)$db->insert_id;
    $event=array_values(array_filter(Domain\EventManager::expandOccurrences((new Domain\EventManager())->getAllRaw(),21,time()),static fn($r)=>(int)$r['id']===$eventId))[0];
    $payload=json_encode([['ids'=>[188],'titles'=>['My Little Pony Friendship is Magic - Season 8 Episode 19 - Road to Friendship'],'length'=>1]]);
    $q=$db->prepare('INSERT INTO playlist_snapshots(created_at,payload_json,origin) VALUES(UTC_TIMESTAMP(),?,"admin")');$q->bind_param('s',$payload);$q->execute();$snapshot=(int)$db->insert_id;
    $run=$event['run_id'];$meta=json_encode($event);
    $q=$db->prepare('INSERT INTO playlist_occurrences(run_id,event_id,start_at,end_at,snapshot_id,state,metadata_json) VALUES(?,?,?,?,?,"pending",?)');$q->bind_param('sissis',$run,$eventId,$date,$end,$snapshot,$meta);$q->execute();
    $config->setOption('announcements_enabled','0');$config->setOption('announcements_event_id',(string)$eventId);
    foreach(Domain\AnnouncementSchedule::defaults() as $key=>$value)$config->setOption($key,$value);
    $facts=(new LLM\AnnouncementContent())->facts($event,time());if(!$facts) throw new RuntimeException('Missing fixture facts');
    $store=new Domain\AnnouncementStore();$campaign=$store->ensure($facts,LLM\AnnouncementContent::fingerprint($facts),Domain\AnnouncementSchedule::calculate($event),$store->nextNumber());
    foreach($db->query('SELECT current_revision,kind FROM telegram_announcement_posts WHERE campaign_id='.$campaign)->fetch_all(MYSQLI_ASSOC) as $post) {
        $id=(int)$post['current_revision'];$store->change($id,'text_pending','text_generating');
        $store->change($id,'text_generating','failed',['attempts'=>3,'last_error'=>$post['kind']==='main'?'synopsis_sources_missing:188':'image_daily_limit','retry_at'=>gmdate('Y-m-d H:i:s',time()-60)]);
    }
    file_put_contents($path,json_encode(compact('eventId','snapshot','run','campaign'),JSON_THROW_ON_ERROR));chmod($path,0600);echo "Fixture ready\n";
} elseif($mode==='cleanup') {
    $f=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    $posts=array_column($db->query('SELECT id FROM telegram_announcement_posts WHERE campaign_id='.(int)$f['campaign'])->fetch_all(MYSQLI_ASSOC),'id');
    if($posts)$db->query('DELETE FROM telegram_announcement_revisions WHERE post_id IN ('.implode(',',array_map('intval',$posts)).')');
    $db->query('DELETE FROM telegram_announcement_posts WHERE campaign_id='.(int)$f['campaign']);$db->query('DELETE FROM telegram_announcement_campaigns WHERE id='.(int)$f['campaign']);
    $q=$db->prepare('DELETE FROM playlist_occurrences WHERE run_id=?');$q->bind_param('s',$f['run']);$q->execute();$db->query('DELETE FROM playlist_snapshots WHERE id='.(int)$f['snapshot']);$db->query('DELETE FROM events WHERE id='.(int)$f['eventId']);
    unlink($path);echo "Fixture removed\n";
} else throw new RuntimeException('Use setup or cleanup');
