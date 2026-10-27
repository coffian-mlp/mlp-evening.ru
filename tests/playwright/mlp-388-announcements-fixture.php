<?php
require_once dirname(__DIR__).'/integration_helpers.php';
if (PHP_SAPI!=='cli' || !is_file('/.dockerenv') || (it_config()['db']['host']??'')!=='db') exit(1);
$db=Infra\Database::getInstance()->getConnection();$config=Infra\ConfigManager::getInstance();
$path=dirname(__DIR__,2).'/docs/private/mlp388-announcements-fixture.json';
$mode=$argv[1]??'';
if ($mode==='setup') {
 if(is_file($path))throw new RuntimeException('Fixture exists; cleanup first');
 $original=$db->query("SELECT key_name,value FROM site_options WHERE key_name LIKE 'announcements_%'")->fetch_all(MYSQLI_ASSOC);
 $actor=$db->query("SELECT id,role FROM users WHERE login='sol_daybreaker'")->fetch_assoc();if(!$actor)throw new RuntimeException('Local Daybreaker missing');
 (new Domain\UserManager())->updateUser((int)$actor['id'],['roles'=>array_values(array_unique(array_merge(Domain\UserRoles::fromUser($actor),['admin'])))]);
 $start=(new DateTimeImmutable('next Saturday 19:00',new DateTimeZone('Europe/Moscow')))->getTimestamp();
 $stmt=$db->prepare('INSERT INTO events(title,description,start_time,duration_minutes,is_recurring,recurrence_rule,use_playlist,generate_new_playlist) VALUES("MLP-388 UI fixture","fixture",?,240,1,"weekly",1,0)');$date=gmdate('Y-m-d H:i:s',$start);$stmt->bind_param('s',$date);$stmt->execute();$eventId=(int)$db->insert_id;
 $event=array_values(array_filter(Domain\EventManager::expandOccurrences((new Domain\EventManager())->getAllRaw(),21,time()),static fn($r)=>(int)$r['id']===$eventId))[0];
 $payload=json_encode([['ids'=>[1],'titles'=>['Fixture episode'],'length'=>1]]);$q=$db->prepare('INSERT INTO playlist_snapshots(created_at,payload_json,origin) VALUES(UTC_TIMESTAMP(),?,"admin")');$q->bind_param('s',$payload);$q->execute();$snapshot=(int)$db->insert_id;
 $end=gmdate('Y-m-d H:i:s',$start+14400);$meta=json_encode($event);$run=$event['run_id'];
 $q=$db->prepare('INSERT INTO playlist_occurrences(run_id,event_id,start_at,end_at,snapshot_id,state,metadata_json) VALUES(?,?,?,?,?,"pending",?)');$q->bind_param('sissis',$run,$eventId,$date,$end,$snapshot,$meta);$q->execute();
 $config->setOption('announcements_token','123456:'.str_repeat('a',35));$config->setOption('announcements_enabled','0');$config->setOption('announcements_event_id',(string)$eventId);$config->setOption('announcements_channel','@fixture388');$config->setOption('announcements_owner_id','42');
 foreach(Domain\AnnouncementSchedule::defaults() as $key=>$value)$config->setOption($key,$value);
 $facts=(new LLM\AnnouncementContent())->facts($event,time());if(!$facts)throw new RuntimeException('Fixture facts missing');
 $store=new Domain\AnnouncementStore();$campaign=$store->ensure($facts,LLM\AnnouncementContent::fingerprint($facts),Domain\AnnouncementSchedule::calculate($event),$store->nextNumber());
 @mkdir(dirname(__DIR__,2).'/upload/lyra',0755,true);$image='/upload/lyra/ui388_'.bin2hex(random_bytes(6)).'.jpg';copy(dirname(__DIR__,2).'/assets/img/bg.jpg',dirname(__DIR__,2).$image);
 foreach($db->query('SELECT current_revision,kind FROM telegram_announcement_posts WHERE campaign_id='.$campaign)->fetch_all(MYSQLI_ASSOC) as $p) {
  $id=(int)$p['current_revision'];$r=$store->revision($id);$prefix=$p['kind']==='main'?'#'.$r['stream_number']:'Notificatio '.LLM\AnnouncementContent::roman((int)$r['stream_number']);$caption=$prefix."\n\nЯ зову смотреть вместе. ".(new DateTimeImmutable('@'.$start))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('d.m.Y H:i').' МСК https://mlp-evening.ru/';
  $store->change($id,'text_pending','text_generating');$store->change($id,'text_generating','image_pending',['caption'=>$caption]);$store->change($id,'image_pending','image_generating');$store->change($id,'image_generating','preview_pending',['image_path'=>$image]);
 }
 file_put_contents($path,json_encode(compact('original','actor','eventId','snapshot','run','campaign','image'),JSON_THROW_ON_ERROR));chmod($path,0600);echo "Fixture prepared.\n";
} elseif($mode==='cleanup') {
 $f=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
 $posts=array_column($db->query('SELECT id FROM telegram_announcement_posts WHERE campaign_id='.(int)$f['campaign'])->fetch_all(MYSQLI_ASSOC),'id');
 if($posts)$db->query('DELETE FROM telegram_announcement_revisions WHERE post_id IN ('.implode(',',array_map('intval',$posts)).')');
 $db->query('DELETE FROM telegram_announcement_posts WHERE campaign_id='.(int)$f['campaign']);$db->query('DELETE FROM telegram_announcement_campaigns WHERE id='.(int)$f['campaign']);
 $q=$db->prepare('DELETE FROM playlist_occurrences WHERE run_id=?');$q->bind_param('s',$f['run']);$q->execute();$db->query('DELETE FROM playlist_snapshots WHERE id='.(int)$f['snapshot']);$db->query('DELETE FROM events WHERE id='.(int)$f['eventId']);
 $db->query("DELETE FROM site_options WHERE key_name LIKE 'announcements_%'");foreach($f['original'] as $r)$config->setOption($r['key_name'],$r['value']);
 (new Domain\UserManager())->updateUser((int)$f['actor']['id'],['roles'=>Domain\UserRoles::fromUser($f['actor'])]);unlink(dirname(__DIR__,2).$f['image']);unlink($path);echo "Owned local fixture removed, role/settings restored.\n";
}
