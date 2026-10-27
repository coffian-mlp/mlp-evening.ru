<?php
require_once __DIR__.'/integration_helpers.php';
if((it_config()['db']['host']??'')!=='db')it_skip('Stream monitoring requires isolated Docker');
it_require_db();
$db=Infra\Database::getInstance()->getConnection();$cfg=Infra\ConfigManager::getInstance();$users=new Domain\UserManager();
$store=new Domain\StreamMonitorStore();$notifications=new Domain\LyraNotifications();$events=new Domain\EventManager();
$fail=0;$check=static function($v,$m)use(&$fail){echo($v?'[OK] ':'[FAIL] ').$m."\n";if(!$v)$fail++;};
$denied=static function($call) {try{$call();return false;}catch(Core\UserError $e){return true;}};
$saved=[];$ids=[];$eventIds=[];$suffix=bin2hex(random_bytes(5));$now=time()+100;$state='live';$seq=0;$sent=[];$writes=0;
foreach(['ai_bot_user_id','stream_source','self_hosted_hls_url','stream_monitor_default_streamer','announcements_token','announcements_channel','stream_monitor_heartbeat'] as $key)$saved[$key]=$cfg->getOption($key,null);
try{
    foreach(['host'=>'streamer','other'=>'user','admin'=>'admin','bot'=>'user'] as $name=>$role)$ids[$name]=$users->createUser('it_mlp444_'.$name.'_'.$suffix,bin2hex(random_bytes(20)),$role,'Monitor '.$name);
    $cfg->setOption('ai_bot_user_id',(string)$ids['bot']);$cfg->setOption('stream_source','self_hosted');$cfg->setOption('self_hosted_hls_url','/live/evening/index.m3u8');
    $cfg->setOption('stream_monitor_default_streamer',(string)$ids['host']);$cfg->setOption('announcements_token','123456:'.str_repeat('a',35));$cfg->setOption('announcements_channel','@test_channel');
    $uid=(string)(700000000+$ids['host']);
    $users->linkSocial($ids['host'],'telegram',['id'=>$uid,'username'=>'fixture','first_name'=>'Test','last_name'=>'','photo_url'=>null]);
    $identity=['is_bot'=>true,'id'=>123456,'username'=>'test_lyra_bot'];
    $url=$notifications->begin($ids['host'],$identity,$now);preg_match('/notify_([a-f0-9]{48})$/',$url,$token);
    $hash=Domain\LyraNotifications::fingerprint();
    $check(!$notifications->accept($token[1],'999','999',$hash,$now),'foreign Telegram cannot consume token');
    $check(!$notifications->accept($token[1],$uid,'-10012345',$hash,$now),'group context cannot connect notifications');
    $check(!$notifications->accept($token[1],$uid,$uid,str_repeat('f',64),$now),'different bot credential cannot connect');
    $check($notifications->accept($token[1],$uid,$uid,$hash,$now),'expected Telegram links private contact');
    $check(!$notifications->accept($token[1],$uid,$uid,$hash,$now),'connection token is one-time');
    $check($notifications->contact($ids['host'])===$uid,'contact follows canonical social identity');
    $notifications->disconnect($ids['host']);$check($notifications->contact($ids['host'])===null,'disconnect revokes personal notifications');
    $url=$notifications->begin($ids['host'],$identity,$now);preg_match('/notify_([a-f0-9]{48})$/',$url,$token);
    $check(!$notifications->accept($token[1],$uid,$uid,$hash,$now+600),'link expires exactly at 10 minutes');
    $notifications->accept($token[1],$uid,$uid,$hash,$now);
    $base=['title'=>'Monitor fixture '.$suffix,'description'=>'','start_time'=>gmdate('Y-m-d H:i:s',$now-100),'duration_minutes'=>90,'is_recurring'=>0,'recurrence_rule'=>'','use_playlist'=>0,'generate_new_playlist'=>0,'color'=>'#123456','monitor_stream'=>1,'streamer_user_id'=>$ids['host']];
    $events->create($base);$event=(int)$db->insert_id;$eventIds[]=$event;
    $probe=static function($path)use(&$state,&$seq){return ['state'=>$state,'progress'=>'frame_'.++$seq];};
    $writer=static function($instruction)use(&$writes){$writes++;return null;};
    $telegram=static function($params)use(&$sent){$sent[]=$params;return ['message_id'=>1000+count($sent)];};
    $monitor=new Domain\StreamMonitor($store,$probe,$writer,$telegram);
    for($t=0;$t<=60;$t+=10)$monitor->tick($now+$t);
    $run=$store->rows('SELECT * FROM stream_monitor_runs WHERE event_id=?',[$event])[0];$check((int)$run['armed']===1,'persistent monitor arms only after live minute');
    $state='down';$monitor->tick($now+70);$monitor->tick($now+114);$check(!$store->rows('SELECT * FROM stream_incidents WHERE run_id=?',[$run['run_id']]),'short outage creates no incident');
    $monitor->tick($now+115);$run=$store->run($run['run_id']);$incident=$store->incident((int)$run['incident_id']);
    $check($incident!==null && $writes===1 && !$sent,'45s writes one LLM/fallback chat message');
    $delivery=$store->deliveries((int)$incident['id'])[0];$chat=(new Domain\ChatManager())->getMessageById((int)$delivery['message_id']);
    $check(str_contains($chat['raw_message'],'[[command:')&&str_contains($chat['raw_message'],'Сигнал эфира пропал'),'warning binds generic system controls and fallback text');
    $manager=new Domain\CommandInteractionManager(LLM\CommandInteractions::registry());
    $check(!$manager->readPublic((int)$incident['interaction_id'],$ids['other'],(int)$delivery['message_id'])['can_act'],'viewer cannot control incident');
    $check($manager->readPublic((int)$incident['interaction_id'],$ids['admin'],(int)$delivery['message_id'])['can_act'],'administrator may act for streamer');
    $check($denied(fn()=>$manager->consume((int)$incident['interaction_id'],'repair',$ids['other'])),'forged actor is rejected server-side');
    $monitor->tick($now+120);$check(count($store->deliveries((int)$incident['id']))===1,'repeated ticks do not duplicate first warning');
    $monitor->tick($now+250);$check(count($sent)===1&&(string)$sent[0]['chat_id']===$uid,'3-minute escalation uses linked streamer DM');
    $answer=$monitor->telegramAction('stream:'.$incident['id'].':'.$incident['nonce'].':repair',$ids['host'],$now+251,['sender'=>$uid,'chat'=>$uid,'fingerprint'=>$hash]);
    $check($answer['code']==='acknowledged','Telegram callback accepts the current linked streamer identity');
    $monitor->tick($now+670);
    $check(!array_filter($sent,static fn($p)=>$p['chat_id']==='@test_channel'),'acknowledgement suppresses public channel escalation');
    $monitor->tick($now+851);$check(count($sent)===2,'acknowledged unresolved incident receives one private reminder');
    $monitor->act((int)$incident['id'],$ids['host'],'intentional',$now+852);$monitor->tick($now+1500);
    $check(count($sent)===2,'intentional stop suppresses further reminders');
    $state='live';for($t=1510;$t<=1540;$t+=10)$monitor->tick($now+$t);
    $check($store->incident((int)$incident['id'])['state']==='recovered','stable recovery closes incident');
    $check($denied(fn()=>$monitor->telegramAction('stream:'.$incident['id'].':'.$incident['nonce'].':repair',$ids['host'],$now+1541)),'stale Telegram buttons cannot reopen recovered incident');
    $state='down';$monitor->tick($now+1550);$monitor->tick($now+1595);$run=$store->run($run['run_id']);$second=$store->incident((int)$run['incident_id']);
    $monitor->tick($now+2150);$monitor->tick($now+2160);$check(count(array_filter($sent,static fn($p)=>$p['chat_id']==='@test_channel'))===1,'unanswered prolonged incident sends exactly one channel pause');
    $monitor->tick($now+2170);$check(count(array_filter($sent,static fn($p)=>$p['chat_id']==='@test_channel'))===1,'channel pause is idempotent');
    $state='live';for($t=2180;$t<=2210;$t+=10)$monitor->tick($now+$t);
    $monitor->tick($now+2220);$check(count(array_filter($sent,static fn($p)=>$p['chat_id']==='@test_channel'))===2,'channel recovery follows a confirmed public pause');
    $state='down';$monitor->tick($now+2230);$monitor->tick($now+2275);$run=$store->run($run['run_id']);$third=$store->incident((int)$run['incident_id']);
    $called=false;$raceWriter=static function($text)use(&$state,&$called){$state='live';$called=true;return 'Obsolete message';};
    $race=new Domain\StreamMonitor($store,$probe,$raceWriter,$telegram);$before=count($sent);$race->tick($now+2410);
    $check($called&&count($sent)===$before,'restoration during text generation suppresses obsolete Telegram send');
    $db->begin_transaction();$db->query('SELECT state FROM stream_incidents WHERE id='.(int)$third['id'])->fetch_assoc();
    $otherDb=it_db();$otherDb->query("UPDATE stream_incidents SET state='intentional' WHERE id=".(int)$third['id']);
    $check($denied(fn()=>$monitor->act((int)$third['id'],$ids['host'],'repair',$now+2411)),'old RR snapshot cannot overwrite an intentional response committed by another connection');
    $db->rollback();$otherDb->query("UPDATE stream_incidents SET state='open' WHERE id=".(int)$third['id']);$otherDb->close();
    $state='down';$attempts=0;
    $uncertainSender=static function($params)use(&$attempts,$telegram){if($params['chat_id']==='@test_channel'){$attempts++;throw new Social\TelegramBotException('Injected ambiguous transport',true,['reason'=>'network']);}return $telegram($params);};
    $uncertain=new Domain\StreamMonitor($store,$probe,$writer,$uncertainSender);
    $uncertain->tick($now+2830);$uncertain->tick($now+2840);$uncertain->tick($now+2850);
    $channel=$store->rows("SELECT * FROM stream_monitor_deliveries WHERE incident_id=? AND stage='channel_pause'",[$third['id']])[0]??null;
    $check($attempts===1&&($channel['state']??'')==='uncertain','ambiguous Telegram send is recorded and never retried blindly');
    $users->unlinkSocial($ids['host'],'telegram');
    $check(!$notifications->status($ids['host'])['connected']&&$notifications->actor($uid,$uid,$hash)===null,'social unlink revokes both contact and Telegram actions');
    $check($denied(fn()=>$monitor->act((int)$third['id'],$ids['host'],'repair',$now+2851,['sender'=>$uid,'chat'=>$uid,'fingerprint'=>$hash])),'Telegram proof is revalidated inside the incident transaction after unlink');
    $cfg->setOption('stream_source','external');$monitor->tick($now+2420);
    $check((int)$store->run($run['run_id'])['ended']===1,'external source stops own-source monitoring');
    $public=array_values(array_filter($events->getPublic(),static fn($e)=>(int)$e['id']===$event))[0];
    $check(!isset($public['streamer_user_id'])&&!isset($public['monitor_stream']),'public schedule never exposes internal monitoring fields');
    $events->update($event,array_diff_key($base,array_flip(['monitor_stream','streamer_user_id'])));
    $updated=array_values(array_filter($events->getAllRaw(),static fn($e)=>(int)$e['id']===$event))[0];
    $check((int)$updated['monitor_stream']===1&&(int)$updated['streamer_user_id']===$ids['host'],'legacy event updates preserve optional monitoring fields');
    $store->eraseUserData($ids['host']);
    $records=$store->rows('SELECT * FROM stream_monitor_runs WHERE event_id=?',[$event]);
    $check(!array_filter($records,static fn($r)=>(int)$r['streamer_user_id']!==0||str_contains($r['run_id'],'_u'.$ids['host'].'_')),'privacy erasure removes streamer identity from records and occurrence keys');
}finally{
    foreach($eventIds as $id){
        foreach($store->rows('SELECT * FROM stream_monitor_runs WHERE event_id=?',[$id]) as $r){
            foreach($store->rows('SELECT id,interaction_id FROM stream_incidents WHERE run_id=?',[$r['run_id']]) as $i){$store->sql('DELETE FROM stream_monitor_deliveries WHERE incident_id=?',[$i['id']]);if($i['interaction_id'])$store->sql('DELETE FROM command_interactions WHERE id=?',[$i['interaction_id']]);}
            $store->sql('DELETE FROM stream_incidents WHERE run_id=?',[$r['run_id']]);$store->sql('DELETE FROM stream_monitor_runs WHERE run_id=?',[$r['run_id']]);
        }
        $events->delete($id);
    }
    foreach($ids as $id){$notifications->eraseUserData($id);$db->query('DELETE FROM chat_messages WHERE user_id='.(int)$id);Domain\Privacy::stageErasure($id,[]);$users->deleteUser($id);}
    foreach($saved as $k=>$v){if($v!==null)$cfg->setOption($k,(string)$v);else{$s=$db->prepare('DELETE FROM site_options WHERE key_name=?');$s->bind_param('s',$k);$s->execute();}}
    $cfg->flushCache();
}
echo $fail?"FAIL: $fail\n":"ALL PASS\n";exit($fail?1:0);
