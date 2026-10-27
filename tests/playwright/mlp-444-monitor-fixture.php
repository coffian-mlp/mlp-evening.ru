<?php
require_once dirname(__DIR__).'/integration_helpers.php';
if(PHP_SAPI!=='cli'||!is_file('/.dockerenv')||(it_config()['db']['host']??'')!=='db')throw new RuntimeException('Isolated Docker required');
it_require_db();$db=Infra\Database::getInstance()->getConnection();$cfg=Infra\ConfigManager::getInstance();$users=new Domain\UserManager();$store=new Domain\StreamMonitorStore();
$file=dirname(__DIR__,2).'/docs/private/mlp444-local.json';$mode=$argv[1]??'';
$save=static function($s)use($file){$mask=umask(0077);file_put_contents($file,json_encode($s,JSON_THROW_ON_ERROR));chmod($file,0600);umask($mask);};
if($mode==='setup'){
    if(is_file($file))throw new RuntimeException('Cleanup previous fixture');
    $s=['users'=>[],'options'=>$db->query('SELECT * FROM site_options')->fetch_all(MYSQLI_ASSOC),'event'=>null,'bot_cursor'=>$db->query('SELECT * FROM telegram_announcement_bot_cursors WHERE bot_id=987333222')->fetch_assoc()];$save($s);
    foreach(['admin'=>['user','admin','streamer'],'host'=>'streamer','viewer'=>'streamer','bot'=>'user'] as $name=>$role){
        $login='it_mlp444_ui_'.$name.'_'.bin2hex(random_bytes(4));$password=bin2hex(random_bytes(20));$id=$users->createUser($login,$password,$role,'Monitor '.$name);
        $s['users'][$name]=compact('login','password','id');$save($s);Domain\Privacy::setAccountConsent($id,true);
    }
    $cfg->setOption('ai_enabled','0');$cfg->setOption('ai_bot_user_id',(string)$s['users']['bot']['id']);$cfg->setOption('stream_source','self_hosted');$cfg->setOption('self_hosted_hls_url','/live/evening/index.m3u8');
    $cfg->setOption('stream_monitor_default_streamer',(string)$s['users']['host']['id']);$cfg->setOption('announcements_token','987333222:'.str_repeat('c',35));
    $cfg->setOption('lyra_bot_verified_username','test_lyra_bot');$cfg->setOption('lyra_bot_verified_username_hash',Domain\LyraNotifications::fingerprint());
    (new Domain\AnnouncementStore())->activatePollingBot(987333222,Domain\LyraNotifications::fingerprint());
    $s['uid']=(string)(800000000+$s['users']['host']['id']);
    $users->linkSocial($s['users']['host']['id'],'telegram',['id'=>$s['uid'],'username'=>'test_streamer','first_name'=>'Host','last_name'=>'','photo_url'=>null]);
    (new Domain\EventManager())->create(['title'=>'MLP444 browser event','description'=>'fixture','start_time'=>gmdate('Y-m-d H:i:s',time()-300),'duration_minutes'=>60,'is_recurring'=>0,'recurrence_rule'=>'','use_playlist'=>0,'generate_new_playlist'=>0,'color'=>'#112233','monitor_stream'=>1,'streamer_user_id'=>$s['users']['host']['id']]);
    $s['event']=(int)$db->insert_id;$save($s);
    $monitor=new Domain\StreamMonitor($store,static fn()=>['state'=>'down'],static fn()=>null,static fn()=>throw new RuntimeException('No external Telegram in fixture'));
    $monitor->tick();$r=$store->rows('SELECT * FROM stream_monitor_runs WHERE event_id=?',[$s['event']])[0];
    $store->sql('UPDATE stream_monitor_runs SET armed=1,lost_at=? WHERE run_id=?',[gmdate('Y-m-d H:i:s',time()-60),$r['run_id']]);$monitor->tick();
    $i=$store->incident((int)$store->run($r['run_id'])['incident_id']);$s['incident']=(int)$i['id'];$s['interaction']=(int)$i['interaction_id'];$s['run']=$r['run_id'];$save($s);
}elseif($mode==='roles_set'||$mode==='roles_get'){
    $s=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);$name=$argv[2]??'';
    if(!isset($s['users'][$name]))throw new RuntimeException('Unknown fixture user');
    $id=(int)$s['users'][$name]['id'];
    if($mode==='roles_get'){echo json_encode($users->getUserById($id)['roles'],JSON_THROW_ON_ERROR);exit;}
    $users->updateUser($id,['roles'=>array_slice($argv,3)]);
}elseif($mode==='accept'){
    $s=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
    if(!(new Domain\LyraNotifications())->accept($argv[2]??'',$s['uid'],$s['uid'],Domain\LyraNotifications::fingerprint()))throw new RuntimeException('Connection failed');
}elseif($mode==='cleanup'){
    $s=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
    if($s['event']){
        foreach($store->rows('SELECT * FROM stream_monitor_runs WHERE event_id=?',[$s['event']]) as $r){
            foreach($store->rows('SELECT id,interaction_id FROM stream_incidents WHERE run_id=?',[$r['run_id']]) as $i){$store->sql('DELETE FROM stream_monitor_deliveries WHERE incident_id=?',[$i['id']]);if($i['interaction_id'])$store->sql('DELETE FROM command_interactions WHERE id=?',[$i['interaction_id']]);}
            $store->sql('DELETE FROM stream_incidents WHERE run_id=?',[$r['run_id']]);$store->sql('DELETE FROM stream_monitor_runs WHERE run_id=?',[$r['run_id']]);
        }
        (new Domain\EventManager())->delete((int)$s['event']);
    }
    foreach($s['users'] as $u){if(($users->getUserById($u['id'])['login']??'')!==$u['login'])throw new RuntimeException('Owner mismatch');(new Domain\LyraNotifications())->eraseUserData($u['id']);$db->query('DELETE FROM chat_messages WHERE user_id='.(int)$u['id']);Domain\Privacy::stageErasure($u['id'],[]);$users->deleteUser($u['id']);}
    $db->query('DELETE FROM site_options');
    foreach($s['options'] as $r){$stmt=$db->prepare('INSERT INTO site_options(key_name,value,updated_at) VALUES(?,?,?)');$stmt->bind_param('sss',$r['key_name'],$r['value'],$r['updated_at']);$stmt->execute();}
    if(!$s['bot_cursor'])$db->query('DELETE FROM telegram_announcement_bot_cursors WHERE bot_id=987333222');
    else $db->query('UPDATE telegram_announcement_bot_cursors SET update_offset='.(int)$s['bot_cursor']['update_offset'].' WHERE bot_id=987333222');
    unlink($file);
}else throw new RuntimeException('Invalid fixture command');
echo "Fixture PASS\n";
