<?php
require_once __DIR__.'/integration_helpers.php';
if ((it_config()['db']['host'] ?? '') !== 'db') it_skip('requires isolated Docker database');
it_require_db()->close();
$db=Infra\Database::getInstance()->getConnection();
$c=Infra\ConfigManager::getInstance();
$keys=['announcements_enabled','announcements_token','announcements_owner_id','announcements_channel','announcements_proxy_url','announcements_event_id','announcements_first_number','announcements_not_before','ai_proxy_url'];
$original=[];
foreach($keys as $key) {$q=$db->prepare('SELECT value FROM site_options WHERE key_name=?');$q->bind_param('s',$key);$q->execute();$original[$key]=$q->get_result()->fetch_assoc();}
try {
 $c->setOption('announcements_enabled','0');$c->setOption('announcements_token','123456:'.str_repeat('a',35));$c->setOption('announcements_owner_id','42');$c->setOption('announcements_event_id','0');$c->setOption('announcements_first_number','179');$c->setOption('announcements_channel','@mlp_evening');$c->setOption('announcements_proxy_url','socks5h://telegram.example:1080');$c->setOption('ai_proxy_url','socks5h://llm.example:1080');
 Domain\AnnouncementSettings::save(['announcements_enabled'=>'0','announcements_token'=>'','announcements_proxy_url'=>'','announcements_first_number'=>'180','announcements_first_date'=>'2026-10-17']);
 $v=Domain\AnnouncementSettings::values();
 check($v['announcements_first_number']==='180' && $v['announcements_token']==='123456:'.str_repeat('a',35),'save writes fields and preserves blank token');
 check($v['announcements_proxy_url']==='socks5h://telegram.example:1080' && $c->getOption('ai_proxy_url')==='socks5h://llm.example:1080','Telegram proxy independent from LLM proxy');
 try {Domain\AnnouncementSettings::save(['announcements_first_number'=>'181','announcements_first_date'=>'2026-02-30']);check(false,'invalid date rejected');}catch(InvalidArgumentException $e){check(Domain\AnnouncementSettings::values()['announcements_first_number']==='180','invalid input leaves all settings unchanged');}
 Domain\AnnouncementSettings::save(['announcements_clear_proxy'=>'1']);check(Domain\AnnouncementSettings::values()['announcements_proxy_url']==='','direct connection stored explicitly');
 try{Domain\AnnouncementSettings::save(['announcements_enabled'=>'1','announcements_owner_id'=>'0']);check(false,'incomplete activation rejected');}catch(InvalidArgumentException $e){check(Domain\AnnouncementSettings::values()['announcements_enabled']==='0' && Domain\AnnouncementSettings::values()['announcements_owner_id']==='42','failed activation leaves all settings unchanged');}
 $c->setOption('announcements_enabled','1');$snapshot=Domain\AnnouncementSettings::values();$calls=0;
 $transport=new Social\TelegramBotClient($snapshot['announcements_token'],static function()use(&$calls){$calls++;return ['status'=>200,'body'=>'{"ok":true,"result":[]}'];});
 $content=new class extends LLM\AnnouncementContent {public function __construct(){}};
 $worker=new LLM\AnnouncementWorker(new Domain\AnnouncementStore(),$transport,$content,42,'@mlp_evening',0,179,Domain\AnnouncementSettings::fingerprint($snapshot));
 $c->setOption('announcements_proxy_url','socks5h://changed.example:1080');$worker->tick();
 check($calls===0,'worker rejects configuration captured before settings change');
 $c->setOption('announcements_proxy_url','');$c->setOption('announcements_enabled','0');$worker->tick();
 check($calls===0,'disabled worker makes no Telegram request');
} finally {
 foreach($original as $key=>$row){if($row)$c->setOption($key,$row['value']);else{$q=$db->prepare('DELETE FROM site_options WHERE key_name=?');$q->bind_param('s',$key);$q->execute();}}$c->flushCache();
}
it_done();
