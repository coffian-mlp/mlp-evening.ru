<?php
require __DIR__.'/integration_helpers.php';
if (PHP_SAPI!=='cli'||!is_file('/.dockerenv')||(it_config()['db']['host']??'')!=='db') it_skip('isolated DB_HOST=db required');
it_require_db();$db=Infra\Database::getInstance()->getConnection();$store=new Domain\AnnouncementStore();
$varsProperty=new ReflectionProperty(Infra\Env::class,'vars');$vars=$varsProperty->getValue();
$db->begin_transaction();
try {
 $db->query("DELETE FROM site_options WHERE key_name IN ('announcements_token','announcements_legacy_token_hash','announcements_legacy_bot_id','announcements_verified_token_hash','announcements_verified_bot_id')");
 $oldVars=$vars;$oldVars['TELEGRAM_ANNOUNCEMENTS_TOKEN']='123456:'.str_repeat('a',35);$varsProperty->setValue(null,$oldVars);
 $legacyUpdate=random_int(5000000,6000000);$legacyOffset=$legacyUpdate+1;$store->setOption('announcements_update_offset',(string)$legacyOffset);
 $db->query("INSERT IGNORE INTO telegram_announcement_updates(update_id,processed_at) VALUES($legacyUpdate,UTC_TIMESTAMP())");
 // Execute the migration's provenance capture statement, without DDL that would commit the fixture.
 $db->query("INSERT IGNORE INTO site_options(key_name,value) SELECT 'announcements_legacy_token_hash',SHA2(value,256) FROM site_options WHERE key_name='announcements_token' AND value<>''");
 check($store->option('announcements_legacy_token_hash','')==='', 'migration does not invent provenance for ENV-only credentials');
 $newToken='234567:'.str_repeat('b',35);$newVars=$vars;$newVars['TELEGRAM_ANNOUNCEMENTS_TOKEN']=$newToken;$varsProperty->setValue(null,$newVars);
 Infra\ConfigManager::getInstance()->setOption('announcements_enabled','1');Infra\ConfigManager::getInstance()->flushCache();
 $settings=Domain\AnnouncementSettings::values();$botId=random_int(800000000,900000000);$polledOffsets=[];$newUpdate=42;
 $client=new Social\TelegramBotClient($newToken,static function($method,$params)use($botId,&$polledOffsets,$newUpdate){
  if($method==='getMe')$result=['is_bot'=>true,'id'=>$botId];
  elseif($method==='getUpdates'){$polledOffsets[]=$params['offset'];$result=$params['offset']<=$newUpdate?[['update_id'=>$newUpdate]]:[];}
  else throw new RuntimeException('Unexpected external action in audit proof');
  return ['status'=>200,'body'=>json_encode(['ok'=>true,'result'=>$result])];
 });
 $worker=new LLM\AnnouncementWorker($store,$client,new LLM\AnnouncementContent(),0,'@fixture_channel',0,1,Domain\AnnouncementSettings::fingerprint($settings));
 $worker->tick();
 check(($polledOffsets[0]??null)===0,'ENV-only bot switch starts independent cursor');
 check($db->query("SELECT update_id FROM telegram_announcement_bot_updates WHERE bot_id=$botId AND update_id=$legacyUpdate")->num_rows===0,'foreign legacy receipt is not imported');
 check($store->option('announcements_update_offset')===(string)$legacyOffset,'unverified legacy cursor remains intact');
 check($db->query("SELECT update_id FROM telegram_announcement_updates WHERE update_id=$legacyUpdate")->num_rows===1,'unverified legacy receipt remains intact');
 check($store->option('announcements_legacy_bot_id','')==='', 'unknown legacy owner is not relabelled as the current bot');
 check($store->pollingOffset($botId)===$newUpdate+1,'lower new bot update is consumed in its own namespace');
 $worker->tick();
 check(($polledOffsets[1]??null)===$newUpdate+1, 'next poll uses new bot progress instead of legacy high offset');
 check($db->query("SELECT update_id FROM telegram_announcement_bot_updates WHERE bot_id=$botId AND update_id=$newUpdate")->num_rows===1,'repeated new bot update has one receipt');
}finally{
 $db->rollback();$varsProperty->setValue(null,$vars);Infra\ConfigManager::getInstance()->flushCache();
}
it_done();
