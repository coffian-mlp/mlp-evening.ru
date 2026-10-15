<?php
require_once __DIR__.'/integration_helpers.php';
if((it_config()['db']['host']??'')!=='db')it_skip('Requires isolated DB_HOST=db');
it_require_db();$db=Infra\Database::getInstance()->getConnection();$store=new Domain\AnnouncementStore();
$db->begin_transaction();
try{
 $botA=random_int(100000000,200000000);$botB=$botA+1;$update=random_int(1000000,2000000);
 $tokenA='123456:'.str_repeat('a',35);$tokenB='123457:'.str_repeat('b',35);$rotated='123456:'.str_repeat('c',35);
 $hashA=hash('sha256',$tokenA);$hashB=hash('sha256',$tokenB);
 $store->setOption('announcements_legacy_token_hash',$hashA);$store->setOption('announcements_legacy_bot_id','');$store->setOption('announcements_update_offset',(string)($update+1));
 $db->query("INSERT INTO telegram_announcement_updates(update_id,processed_at) VALUES($update,UTC_TIMESTAMP())");
 $store->activatePollingBot($botA,$hashA);
 check($store->pollingOffset($botA)===0,'migration-time credential hash does not assign the legacy cursor');
 $actions=0;check($store->consume($update,function()use(&$actions){$actions++;},$botA),'unscoped legacy receipt does not suppress the verified bot update');
 $store->activatePollingBot($botB,$hashB);
 check($store->pollingOffset($botB)===0,'another bot begins at its own offset');
 check($store->consume($update,function()use(&$actions){$actions++;},$botB),'same update ID belongs independently to second bot');
 check($actions===2,'cross-bot ID collision does not suppress new action');
 check(!$store->consume($update,function()use(&$actions){$actions++;},$botB),'same-bot duplicate still suppressed');
 $store->activatePollingBot($botA,hash('sha256',$rotated));
 check($store->pollingOffset($botA)===$update+1,'token rotation preserves original bot cursor');
 check(!$store->consume($update,static function(){throw new RuntimeException('duplicate');},$botA),'token rotation preserves original receipts');
 $offset=$store->pollingOffset($botB);
 try{$store->consume($update+5,static function(){throw new RuntimeException('rollback');},$botB);}catch(RuntimeException $e){}
 check($store->pollingOffset($botB)===$offset,'failed action does not advance bot cursor');
 check($store->consume($update+5,static function(){},$botB),'failed action can be retried in same namespace');
 $store->consume($update+2,static function(){},$botB);
 check($store->pollingOffset($botB)===$update+6,'out-of-order update does not rewind cursor');
 // Exercise getMe binding and actual getUpdates arguments on a fresh worker.
 $workerBot=$botB+1;$requests=[];$newToken='234567:'.bin2hex(random_bytes(20));
 $client=new Social\TelegramBotClient($newToken,static function($method,$params)use(&$requests,$workerBot){$requests[]=[$method,$params];$result=$method==='getMe'?['is_bot'=>true,'id'=>$workerBot]:[];return ['status'=>200,'body'=>json_encode(['ok'=>true,'result'=>$result])];});
 $content=new LLM\AnnouncementContent();$worker=new LLM\AnnouncementWorker($store,$client,$content,0,'@fixture_channel',0,1);
 $worker->tick();$worker->tick();
 $polls=array_values(array_filter($requests,fn($r)=>$r[0]==='getUpdates'));$identity=array_filter($requests,fn($r)=>$r[0]==='getMe');
 check(count($identity)===1 && count($polls)===2,'identity cached for unchanged credentials without extra getMe polling');
 check($polls[0][1]['offset']===0 && $polls[1][1]['offset']===0,'new worker never sends previous bot high cursor');
}finally{$db->rollback();Infra\ConfigManager::getInstance()->flushCache();}
it_done();
