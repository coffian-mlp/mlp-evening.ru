<?php
require __DIR__.'/integration_helpers.php';
if(PHP_SAPI!=='cli'||!is_file('/.dockerenv')||(it_config()['db']['host']??'')!=='db')it_skip('Requires isolated Docker DB_HOST=db');
it_require_db();$db=Infra\Database::getInstance()->getConnection();$store=new Domain\AnnouncementStore();$config=Infra\ConfigManager::getInstance();
$db->begin_transaction();
try {
    $db->query("DELETE FROM site_options WHERE key_name IN ('announcements_legacy_token_hash','announcements_legacy_bot_id','announcements_verified_token_hash','announcements_verified_bot_id')");
    $a='123456:'.str_repeat('a',35);$b='234567:'.str_repeat('b',35);$update=900000;
    $config->setOption('announcements_enabled','0');$config->setOption('announcements_event_id','0');$config->setOption('announcements_token',$a);
    $store->setOption('announcements_update_offset','900001');$db->query("INSERT IGNORE INTO telegram_announcement_updates(update_id,processed_at) VALUES($update,UTC_TIMESTAMP())");
    Domain\AnnouncementSettings::save(['announcements_enabled'=>'0','announcements_token'=>$b,'announcements_event_id'=>'0','announcements_clear_proxy'=>'1']);
    echo 'Public settings save changed token to B: '.($store->option('announcements_token')===$b?'yes':'no')."\n";
    echo 'Legacy A offset after settings save: '.$store->option('announcements_update_offset')."\n";
    $db->query("INSERT IGNORE INTO site_options(key_name,value) SELECT 'announcements_legacy_token_hash',SHA2(value,256) FROM site_options WHERE key_name='announcements_token' AND value<>''");
    $config->setOption('announcements_enabled','1');$config->flushCache();$v=Domain\AnnouncementSettings::values();$bot=random_int(700000000,800000000);$offset=null;
    $client=new Social\TelegramBotClient($b,static function($method,$params)use($bot,&$offset){if($method==='getMe')$r=['is_bot'=>true,'id'=>$bot];elseif($method==='getUpdates'){$offset=$params['offset'];$r=[];}else throw new RuntimeException('Unexpected external action');return ['status'=>200,'body'=>json_encode(['ok'=>true,'result'=>$r])];});
    (new LLM\AnnouncementWorker($store,$client,new LLM\AnnouncementContent(),0,'@fixture_channel',0,1,Domain\AnnouncementSettings::fingerprint($v)))->tick();
    echo 'Expected B offset: 0; actual: '.($offset??'no poll')."\n";
    echo 'A receipt assigned to B: '.($db->query("SELECT update_id FROM telegram_announcement_bot_updates WHERE bot_id=$bot AND update_id=$update")->num_rows?'yes':'no')."\n";
    check($offset===0,'migration snapshot of current DB token does not attribute A history to B');
    check($db->query("SELECT update_id FROM telegram_announcement_bot_updates WHERE bot_id=$bot AND update_id=$update")->num_rows===0,'foreign receipt is not imported even when migration hash matches');
    check($store->option('announcements_update_offset')==='900001','legacy cursor remains unchanged');
    check($db->query("SELECT update_id FROM telegram_announcement_updates WHERE update_id=$update")->num_rows===1,'legacy receipt remains intact');
    check($store->option('announcements_legacy_bot_id','')==='', 'unverified journal is not assigned to current bot');
    $store->consume(42,static function(){},$bot);
    $store->activatePollingBot($bot,hash('sha256','rotated credential'));
    check($store->pollingOffset($bot)===43,'existing namespace survives credential rotation with legacy high offset present');
}finally{$db->rollback();$config->flushCache();}
it_done();
