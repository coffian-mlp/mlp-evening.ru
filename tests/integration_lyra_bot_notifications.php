<?php
require_once __DIR__.'/integration_helpers.php';
if((it_config()['db']['host']??'')!=='db')it_skip('Private bot notifications require isolated Docker');
it_require_db();$db=Infra\Database::getInstance()->getConnection();$cfg=Infra\ConfigManager::getInstance();$users=new Domain\UserManager();$store=new Domain\AnnouncementStore();
$saved=[];$keys=['announcements_token','announcements_verified_token_hash','announcements_verified_bot_id','lyra_bot_verified_username','lyra_bot_verified_username_hash'];
foreach($keys as $k)$saved[$k]=$cfg->getOption($k,null);
$fail=0;$check=static function($v,$m)use(&$fail){echo($v?'[OK] ':'[FAIL] ').$m."\n";if(!$v)$fail++;};
$suffix=bin2hex(random_bytes(6));$id=$users->createUser('it_mlp444_poller_'.$suffix,bin2hex(random_bytes(20)),'streamer');$bot=987111222;
$oldCursor=$db->query('SELECT update_offset FROM telegram_announcement_bot_cursors WHERE bot_id='.$bot)->fetch_assoc();$updateId=1900000000+random_int(10000,1000000);
$updates=[];$calls=[];$uid=(string)(900000000+$id);
try{
    $cfg->setOption('announcements_token','987111222:'.str_repeat('b',35));$cfg->setOption('announcements_verified_token_hash','');
    $users->linkSocial($id,'telegram',['id'=>$uid,'username'=>'fixture','first_name'=>'Test','last_name'=>'','photo_url'=>null]);
    $n=new Domain\LyraNotifications();$url=$n->begin($id,['is_bot'=>true,'id'=>$bot,'username'=>'test_lyra_bot']);preg_match('/notify_([a-f0-9]{48})$/',$url,$m);
    $updates=[['update_id'=>$updateId,'message'=>['message_id'=>4,'from'=>['id'=>(int)$uid],'chat'=>['id'=>(int)$uid,'type'=>'private'],'text'=>'/start notify_'.$m[1]]]];
    $client=new Social\TelegramBotClient('987111222:'.str_repeat('b',35),static function($method,$params)use(&$updates,&$calls,$bot){
        $calls[]=[$method,$params];$result=match($method){'getMe'=>['id'=>$bot,'is_bot'=>true,'username'=>'test_lyra_bot'],'getUpdates'=>$updates,'sendMessage'=>['message_id'=>44],default=>true};
        return ['status'=>200,'body'=>json_encode(['ok'=>true,'result'=>$result]),'errno'=>0];
    });
    $poller=new LLM\LyraBotPoller($store,$client,null,static fn()=>null);$poller->tick();
    $check($n->status($id)['connected'],'poller connects verified site identity without announcement owner privileges');
    $check(count(array_filter($calls,static fn($c)=>$c[0]==='sendMessage'))===1,'one confirmation is delivered to actual private sender');
    $check($store->pollingOffset($bot)===$updateId+1,'shared durable bot cursor advances with link transaction');
    $poller->tick();$check(count(array_filter($calls,static fn($c)=>$c[0]==='sendMessage'))===1,'replayed Telegram update cannot link or confirm twice');
    $newUrl=$n->begin($id);$check(str_starts_with($newUrl,'https://t.me/test_lyra_bot?start=notify_'),'profile uses bot identity verified by polling without another network call');
    $content=new class extends LLM\AnnouncementContent {public function editorText(string $fallback): string{return $fallback;}};
    $editor=new LLM\AnnouncementWorker($store,$client,$content,(int)$uid,'@test_channel',0,1);
    $ownerPoller=new LLM\LyraBotPoller($store,$client,$editor,static fn()=>null);
    $updates=[['update_id'=>$updateId+1,'message'=>['message_id'=>5,'from'=>['id'=>(int)$uid],'chat'=>['id'=>(int)$uid,'type'=>'private'],'text'=>'/help']]];
    $other=it_db();$other->query("SELECT GET_LOCK('telegram_announcements',0)");
    try{$ownerPoller->tick();$check(false,'active publisher defers owner action');}catch(LLM\BotPollBusy $e){$check(true,'active publisher defers owner action');}
    $check($store->pollingOffset($bot)===$updateId+1,'deferred owner action does not advance or discard shared update cursor');
    $other->query("DO RELEASE_LOCK('telegram_announcements')");$other->close();$ownerPoller->tick();
    $check($store->pollingOffset($bot)===$updateId+2,'owner action is processed after publication lock is released');
    $cfg->setOption('announcements_token','987111222:'.str_repeat('c',35));$check(!$n->status($id)['connected'],'bot credential replacement invalidates private contact');
}finally{
    (new Domain\LyraNotifications())->eraseUserData($id);Domain\Privacy::stageErasure($id,[]);$users->deleteUser($id);
    $db->query('DELETE FROM telegram_announcement_bot_updates WHERE bot_id='.$bot.' AND update_id IN ('.$updateId.','.($updateId+1).')');
    if($oldCursor)$db->query('UPDATE telegram_announcement_bot_cursors SET update_offset='.(int)$oldCursor['update_offset'].' WHERE bot_id='.$bot);else $db->query('DELETE FROM telegram_announcement_bot_cursors WHERE bot_id='.$bot);
    foreach($saved as $k=>$v){if($v!==null)$cfg->setOption($k,(string)$v);else{$s=$db->prepare('DELETE FROM site_options WHERE key_name=?');$s->bind_param('s',$k);$s->execute();}}$cfg->flushCache();
}
echo $fail?"FAIL: $fail\n":"ALL PASS\n";exit($fail?1:0);
