<?php
require_once __DIR__.'/integration_helpers.php';
if ((it_config()['db']['host']??'')!=='db') it_skip('isolated Docker required');
$db=Infra\Database::getInstance()->getConnection();$db->begin_transaction();$store=new Domain\AnnouncementStore($db);
try {
 $start=time()+7*86400;
 $facts=['occurrence'=>['id'=>0,'run_id'=>'dashboard388_'.bin2hex(random_bytes(8)),'real_start_time'=>$start,'use_playlist'=>1,'is_recurring'=>1,'recurrence_rule'=>'weekly','duration_minutes'=>240],'snapshot'=>['id'=>1,'stories'=>[['ids'=>[1],'titles'=>['Fixture']]]]];
 $schedule=Domain\AnnouncementSchedule::calculate($facts['occurrence']);
 $before=$store->nextNumber();$campaign=$store->ensure($facts,LLM\AnnouncementContent::fingerprint($facts),$schedule,$before);
 $p=$db->query('SELECT current_revision FROM telegram_announcement_posts WHERE campaign_id='.$campaign.' AND kind="main"')->fetch_assoc();$id=(int)$p['current_revision'];$r=$store->revision($id);
 check((int)$r['stream_number']===$before && $store->nextNumber()===$before+1,'counter reserves one stream number and advances next-number setting');
 $date=(new DateTimeImmutable('@'.$start))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('d.m.Y H:i');$caption='#'.$before."\n\nЯ зову смотреть. ".$date.' МСК https://mlp-evening.ru/';
 $store->change($id,'text_pending','text_generating');$store->change($id,'text_generating','image_pending',['caption'=>$caption]);$store->change($id,'image_pending','image_generating');$store->change($id,'image_generating','preview_pending',['image_path'=>'/upload/lyra/fixture388.jpg']);
 check($store->action($id,$r['nonce'],'approve','',null)==='unavailable','Telegram cannot approve a preview not delivered to Telegram');
 check($store->action($id,$r['nonce'],'approve','',null,true)==='approved','dashboard can approve a fully visible caption/image before private delivery');
 $changed=$schedule;$changed['main_at']+=60;
 $store->ensure($facts,LLM\AnnouncementContent::fingerprint($facts),$changed,$before);
 $newTimeId=(int)$db->query('SELECT current_revision FROM telegram_announcement_posts WHERE campaign_id='.$campaign.' AND kind="main"')->fetch_assoc()['current_revision'];
 check($store->action($id,$r['nonce'],'approve','',null,true)==='stale','timing changes invalidate old approval buttons');
 $id=$newTimeId;$r=$store->revision($id);$updated=$r;check($updated['state']==='preview_pending' && !$updated['approved_at'] && $updated['caption']===$caption && $updated['image_path']==='/upload/lyra/fixture388.jpg','timing change revokes approval and retains content');
 $store->action($id,$r['nonce'],'approve','',null,true);
 check($store->edit($id,$r['nonce'],str_replace("\n","\r\n",$caption)."\r\nРедактура владельца.")==='edited','human edit creates revision');
 $new=$db->query('SELECT current_revision FROM telegram_announcement_posts WHERE campaign_id='.$campaign.' AND kind="main"')->fetch_assoc();$edited=$store->revision((int)$new['current_revision']);
 check($edited['id']!=$id && $edited['state']==='preview_pending' && !$edited['approved_at'] && $edited['image_path']===$updated['image_path'],'edited text preserves image and requires new approval');
 check($store->action($id,$r['nonce'],'approve','',null,true)==='stale','stale dashboard/Telegram buttons rejected');
 try {$store->edit((int)$edited['id'],$edited['nonce'],'Без даты и ссылки.');check(false,'invalid edit rejected');}catch(InvalidArgumentException $e){check(true,'invalid edit rejected without replacing revision');}
 $store->change((int)$edited['id'],'preview_pending','uncertain');
 try {$changed['main_at']+=60;$store->ensure($facts,LLM\AnnouncementContent::fingerprint($facts),$changed,$before);check(false,'uncertain delivery schedule frozen');}catch(RuntimeException $e){check(true,'uncertain delivery schedule frozen');}
 check($store->action((int)$edited['id'],$edited['nonce'],'both','',null,true)==='unavailable','uncertain delivery cannot be blindly regenerated');
 $routes=require __DIR__.'/../src/Api/routes.php';check($routes['announcement_post_action']['role']==='admin','post controls administrator-only');
 check($routes['get_announcement_log']['role']==='admin','journal administrator-only');
} finally {$db->rollback();Infra\ConfigManager::getInstance()->flushCache();}
it_done();
