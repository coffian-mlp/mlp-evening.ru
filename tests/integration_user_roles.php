<?php
require_once __DIR__.'/integration_helpers.php';
if((it_config()['db']['host']??'')!=='db')it_skip('Multiple roles require isolated Docker');
it_require_db();Domain\Auth::check();$db=Infra\Database::getInstance()->getConnection();$users=new Domain\UserManager();$cfg=Infra\ConfigManager::getInstance();$store=new Domain\StreamMonitorStore();
$ids=[];$events=[];$suffix=bin2hex(random_bytes(6));$saved=[];$oldSession=$_SESSION??[];$fail=0;
$check=static function($v,$m)use(&$fail){echo($v?'[OK] ':'[FAIL] ').$m."\n";if(!$v)$fail++;};
$denied=static function($call){try{$call();return false;}catch(Core\UserError $e){return true;}};
foreach(['announcements_token','stream_monitor_default_streamer'] as $k)$saved[$k]=$cfg->getOption($k,null);
try{
    foreach(['admin'=>['admin'],'mixed'=>['moderator','streamer'],'viewer'=>['user'],'target'=>['user']] as $name=>$roles)$ids[$name]=$users->createUser('it_mlp445_'.$name.'_'.$suffix,bin2hex(random_bytes(20)),$roles);
    $admin=$users->getUserById($ids['admin']);$mixed=$users->getUserById($ids['mixed']);
    $check($admin['roles']===['user','admin']&&$mixed['roles']===['user','moderator','streamer'],'all stored and returned role sets retain user');
    $check($mixed['role']==='moderator'&&!Domain\UserRoles::has($admin,'streamer'),'old scalar remains a compatible projection without implicit streamer');
    $all=array_column($users->getAllUsers(),null,'id');$check($all[$ids['mixed']]['roles']===$mixed['roles'],'administrator list returns the full role set');
    $directory=array_column($users->streamerDirectory(),null,'id');$check(isset($directory[$ids['mixed']])&&!isset($directory[$ids['admin']]),'only explicit streamers are event candidates');
    $users->updateProfile($ids['viewer'],['roles'=>['admin'],'role'=>'admin','roles_json'=>'["admin"]','_roles_json'=>'["admin"]']);
    $check($users->getUserById($ids['viewer'])['roles']===['user'],'profile update cannot grant roles through any representation');
    $check($denied(fn()=>$users->updateUser($ids['viewer'],['roles'=>['admin']],$ids['viewer'])),'ordinary actor cannot grant administrative membership');
    $check($denied(fn()=>$users->updateUser($ids['viewer'],['roles'=>['root']])),'unknown role rejected');
    $check($denied(fn()=>$users->updateUser($ids['viewer'],['roles_json'=>'["admin"]'])),'direct raw role payload rejected');
    $users->updateUser($ids['viewer'],['roles'=>['streamer']],$ids['admin']);
    $_SESSION=['user_id'=>$ids['viewer'],'role'=>'admin'];Domain\Auth::refreshRoles();
    $check(Domain\Auth::hasRole('user')&&Domain\Auth::hasRole('streamer')&&!Domain\Auth::isModerator()&&!Domain\Auth::isAdmin(),'fresh session never promotes a streamer to moderator or administrator');
    $cfg->setOption('announcements_token','987555111:'.str_repeat('d',35));
    $uid=(string)(850000000+$ids['viewer']);$users->linkSocial($ids['viewer'],'telegram',['id'=>$uid,'username'=>'fixture','first_name'=>'Test','last_name'=>'','photo_url'=>null]);
    $n=new Domain\LyraNotifications();$url=$n->begin($ids['viewer'],['is_bot'=>true,'id'=>987555111,'username'=>'test_lyra_bot']);preg_match('/notify_([a-f0-9]{48})$/',$url,$m);
    $check($n->accept($m[1],$uid,$uid,Domain\LyraNotifications::fingerprint()),'explicit streamer can connect private notifications');
    $cfg->setOption('stream_monitor_default_streamer',(string)$ids['viewer']);
    $data=['title'=>'Role fixture '.$suffix,'description'=>'','start_time'=>gmdate('Y-m-d H:i:s',time()-60),'duration_minutes'=>60,'is_recurring'=>0,'recurrence_rule'=>'','use_playlist'=>0,'generate_new_playlist'=>0,'color'=>'#112233','monitor_stream'=>1,'streamer_user_id'=>$ids['viewer']];
    (new Domain\EventManager())->create($data);$event=(int)$db->insert_id;$events[]=$event;
    $run='it_roles_'.$suffix;$store->sql('INSERT INTO stream_monitor_runs(run_id,event_id,streamer_user_id,ends_at,path) VALUES(?,?,?,?,?)',[$run,$event,$ids['viewer'],gmdate('Y-m-d H:i:s',time()+3600),'evening']);
    $store->sql('INSERT INTO stream_incidents(run_id,nonce,lost_at) VALUES(?,?,?)',[$run,bin2hex(random_bytes(8)),gmdate('Y-m-d H:i:s')]);$incident=(int)$db->insert_id;
    $store->sql('UPDATE stream_monitor_runs SET incident_id=? WHERE run_id=?',[$incident,$run]);$store->enqueue($incident,'private_warning');
    $users->updateUser($ids['viewer'],['roles'=>[]],$ids['admin']);Domain\Auth::refreshRoles();
    $check(Domain\Auth::hasRole('user')&&!Domain\Auth::hasRole('streamer')&&Domain\Auth::check(),'role removal preserves login and mandatory user access');
    (new Domain\ChatManager())->assertCanSend($ids['viewer']);$check(true,'baseline user still has chat access after removing every optional role');
    $check($n->contact($ids['viewer'])===null&&!$n->status($ids['viewer'])['connected'],'role removal disconnects Telegram access');
    $check($denied(fn()=>$n->begin($ids['viewer'],['is_bot'=>true,'id'=>987555111,'username'=>'test_lyra_bot'])),'non-streamer cannot mint a new connection link');
    $e=array_values(array_filter((new Domain\EventManager())->getAllRaw(),static fn($e)=>(int)$e['id']===$event))[0];
    $check(!$e['monitor_stream']&&$e['streamer_user_id']===null&&(int)$cfg->getOption('stream_monitor_default_streamer')===0,'revocation disables monitoring and removes affected responsibility/default');
    $check((int)$store->run($run)['ended']===1&&$store->incident($incident)['state']==='ended'&&$store->deliveries($incident)[0]['state']==='cancelled','revocation closes existing incidents and pending deliveries');
    $db->query("UPDATE users SET role='admin' WHERE id=".(int)$ids['viewer']);
    $check($users->applyModeration($ids['viewer'],$ids['target'],'mute',1)['error']!==null,'stale compatibility projection cannot grant moderation against canonical membership');
    $db->query("UPDATE users SET role='user' WHERE id=".(int)$ids['viewer']);
    $users->updateUser($ids['mixed'],['role'=>'admin']);$check($users->getUserById($ids['mixed'])['roles']===['user','admin','streamer'],'legacy scalar writes preserve explicit streamer membership');
    $db->begin_transaction();$db->query('SELECT roles_json FROM users WHERE id='.(int)$ids['admin'])->fetch_assoc();
    $other=it_db();$other->query("UPDATE users SET role='user',roles_json=JSON_ARRAY('user') WHERE id=".(int)$ids['admin']);
    $check($denied(fn()=>$users->updateUser($ids['viewer'],['roles'=>['admin']],$ids['admin'])),'stale RR snapshot cannot grant roles after actor administrator revocation');
    $db->rollback();$other->close();
    $data['streamer_user_id']=$ids['admin'];$check($denied(fn()=>(new Domain\EventManager())->create($data)),'administrator without streamer role cannot be assigned as streamer');
}finally{
    if(isset($run)){
        foreach($store->rows('SELECT id FROM stream_incidents WHERE run_id=?',[$run]) as $i)$store->sql('DELETE FROM stream_monitor_deliveries WHERE incident_id=?',[$i['id']]);
        $store->sql('DELETE FROM stream_incidents WHERE run_id=?',[$run]);$store->sql('DELETE FROM stream_monitor_runs WHERE run_id=?',[$run]);
    }
    foreach($events as $id)(new Domain\EventManager())->delete($id);
    foreach($ids as $id){Domain\Privacy::unlockAccount($id);(new Domain\LyraNotifications())->eraseUserData($id);Domain\Privacy::stageErasure($id,[]);$users->deleteUser($id);}
    foreach($saved as $k=>$v){if($v!==null)$cfg->setOption($k,(string)$v);else{$s=$db->prepare('DELETE FROM site_options WHERE key_name=?');$s->bind_param('s',$k);$s->execute();}}$cfg->flushCache();$_SESSION=$oldSession;
}
echo $fail?"FAIL: $fail\n":"ALL PASS\n";exit($fail?1:0);
