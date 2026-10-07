<?php
require_once __DIR__.'/integration_helpers.php';
if((it_config()['db']['host']??'')!=='db')it_skip('requires isolated db');
it_require_db()->close();
$db=Infra\Database::getInstance()->getConnection();
if(($argv[1]??'')==='writer'){
 $marker=$argv[2];$until=microtime(true)+5;while(!is_file($marker)&&microtime(true)<$until)usleep(10000);
 if(!is_file($marker))exit(2);
 $id=(int)$argv[3];$db->begin_transaction();
 $db->query('UPDATE episode_list SET IMDB_RATING=9.7,legacy_wanna_watch=8 WHERE ID='.$id);
 $actor=(int)$argv[4];$target=(int)$argv[5];$stamp=gmdate('Y-m-d H:i:s',(int)$argv[6]);$key='projection:'.$actor;
 $q=$db->prepare('INSERT INTO episode_wish_events(operation_key,user_id,episode_id,kind,status,created_at,outcome_json) VALUES(?,?,?,"wish","accepted",?,"{}")');$q->bind_param('siis',$key,$actor,$target,$stamp);$q->execute();
 $db->commit();file_put_contents($marker.'_done','committed');exit;
}
if(($argv[1]??'')==='nested'){
 $db->begin_transaction();$actor=(int)$argv[3];
 $db->query('SELECT COUNT(*) FROM episode_wish_events WHERE user_id='.$actor)->fetch_row();
 file_put_contents($argv[2],'snapshot');$until=microtime(true)+5;
 while(!is_file($argv[2].'_done')&&microtime(true)<$until)usleep(10000);
 if(!is_file($argv[2].'_done'))exit(2);
 try{$out=(new Domain\EpisodeManager())->wish($actor,(int)$argv[4],$argv[6]??('nested:'.$actor),(int)$argv[5]);$db->commit();echo json_encode($out);}catch(Throwable $e){$db->rollback();throw $e;}exit;
}
if(($argv[1]??'')==='grant-wait'){
 $db->begin_transaction();$actor=(int)$argv[3];
 $db->query('SELECT is_banned FROM users WHERE id='.$actor)->fetch_row();
 file_put_contents($argv[2],'snapshot');$until=microtime(true)+5;
 while(!is_file($argv[2].'_done')&&microtime(true)<$until)usleep(10000);
 try{(new Domain\EpisodeManager())->wish($actor,(int)$argv[4],'grant-wait:'.$actor.':'.$argv[5]);$db->commit();echo json_encode(['blocked'=>false]);}
 catch(Core\UserError $e){$db->rollback();echo json_encode(['blocked'=>true]);}exit;
}
if(($argv[1]??'')==='controller'){
 session_start();$_SESSION['user_id']=(int)$argv[2];
 register_shutdown_function(static function(){if(session_status()===PHP_SESSION_ACTIVE)session_destroy();});
 $_POST=['episode_id'=>$argv[4],'operation_token'=>$argv[5],'user_id'=>2147483646,'admin'=>1];
 $method=$argv[3];Api\EpisodeCatalogueController::$method();exit;
}
class PausedCatalogueRatingManager extends Domain\EpisodeRatingManager {
 protected function catalogueObservations(): array {
  $marker=$GLOBALS['catalogue_marker'];file_put_contents($marker,'ready');$until=microtime(true)+5;
  while(!is_file($marker.'_done')&&microtime(true)<$until)usleep(10000);
  if(!is_file($marker.'_done'))throw new RuntimeException('writer timeout');
  return parent::catalogueObservations();
 }
}
function catalogueChild(array $args): array {
 $pipes=[];$p=proc_open([PHP_BINARY,__FILE__,...$args],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);return [$p,$pipes];
}
function catalogueFinish(array $child): string {
 [$p,$pipes]=$child;$text=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
 check(proc_close($p)===0,'child process completes '.$error);return $text;
}
$m=new Domain\EpisodeManager();$ratings=new Domain\EpisodeRatingManager();$config=Infra\ConfigManager::getInstance();
$oldHeader=$config->getOptionDetails(Domain\EpisodeRatingManager::HEADER_KEY);
$ids=[];$users=[];$now=time();
function catalogueThrows(callable $fn,string $label,string $reason='operation_conflict'): void {
    try{$fn();check(false,$label);}catch(Core\UserError $e){check($reason===''||$e->getMessage()===$reason,$label);}
}
try{
 foreach(['My Little Pony Friendship is Magic - Season 98 Episode 1 - Catalogue <fixture>','My Little Pony Friendship is Magic - Season 98 Episode 2 - Catalogue second','Catalogue special'] as $title){$s=$db->prepare('INSERT INTO episode_list(TITLE,LENGTH,legacy_wanna_watch,TIMES_WATCHED) VALUES(?,1,2,10)');$s->bind_param('s',$title);$s->execute();$ids[]=$db->insert_id;}
 foreach([1,2] as $i)$users[]=(new Domain\UserManager())->createUser('it367_'.bin2hex(random_bytes(6)),'Test367!Password','user');
 $db->query('UPDATE episode_list SET TWOPART_ID='.$ids[1].',LENGTH=2 WHERE ID='.$ids[0]);
 $db->query('UPDATE episode_list SET TWOPART_ID='.$ids[0].',LENGTH=2 WHERE ID='.$ids[1]);
 $catalog=$m->getAllEpisodes();$base=['schema_version'=>1,'source'=>'imdb','parent_series'=>'tt1751105','catalog_fingerprint'=>Domain\EpisodeRatingSnapshot::catalogFingerprint($catalog)];
 $mapping=$base+['records'=>[]];$input=$base+['scope'=>['kind'=>'explicit_ids','ids'=>$ids,'coverage'=>'complete'],'records'=>[]];
 foreach($ids as $i=>$id){$meta=Domain\EpisodeCatalog::metadata($catalog[array_search($id,array_column($catalog,'ID'))]['TITLE']);$imdb='tt'.(800000000+$id);
  $mapping['records'][]=['episode_id'=>$id,'imdb_id'=>$imdb,'kind'=>$meta?'episode':'special','season'=>$meta['season']??null,'episode'=>$meta['episode']??null,'identity_url'=>'https://www.imdb.com/title/'.$imdb.'/'];
  $bins=$i===1?[0,0,0,0,99,0,0,0,0,0]:[0,0,0,0,100,0,0,0,0,0];
  $input['records'][]=['episode_id'=>$id,'imdb_id'=>$imdb,'rating'=>8.2,'votes'=>array_sum($bins),'histogram'=>$bins,'retrieved_at'=>gmdate('Y-m-d\TH:i:s\Z',$now-($i===2?46*86400:0)),'source_asof'=>null,'source_url'=>'https://www.imdb.com/title/'.$imdb.'/ratings/','vote_scope'=>'all_countries','provenance'=>['channel'=>'ordinary_browser_dom']];
 }
 $old=json_decode($oldHeader['value']??'null',true);$ratings->apply($input,$mapping,$old['batch_hash']??null,$now);
 $out=$m->wish($users[0],$ids[0],'it367:legacy:'.$users[0],$now);$m->wish($users[1],$ids[0],'it367:other:'.$users[1],$now);
 $projection=$ratings->getCatalogueProjection($users[0],false,$now);
 $rows=array_column($projection['rows'],null,'id');$a=$rows[$ids[0]];
 check($projection['viewer']['quota']['used']===1&&$projection['viewer']['quota']['remaining']===2,'same actor quota in shared projection');
 check($ratings->getCatalogueProjection()['viewer']['quota']===null,'guest quota null');
 check($a['wishes']===4&&$a['views']===10&&$a['own_active'],'aggregate includes legacy + both active; own state');
 check($a['rating']['score']===8.2&&$a['rating']['sd']===0.0,'weighted score distinct from raw histogram mean; zero SD');
 check(count($a['rating']['histogram'])===10&&$a['related']['id']===$ids[1],'histogram and canonical partner');
 check($rows[$ids[1]]['rating']['status']==='low_votes','low votes observed visible');
 check($rows[$ids[2]]['rating']['status']==='stale'&&$rows[$ids[2]]['rating']['score']===8.2&&$rows[$ids[2]]['season']===null,'stale values retained; special no invented season');
 check(!str_contains(json_encode($projection),'batch_hash')&&!str_contains(json_encode($projection),'provenance')&&!isset($a['admin']),'public explicit whitelist');
 check(!$ratings->getCatalogueProjection()['rows'][array_search($ids[0],array_column($projection['rows'],'id'))]['own_active'],'guest has no own state');
 check(isset(array_column($ratings->getCatalogueProjection(null,true,$now)['rows'],null,'id')[$ids[0]]['admin']),'trusted admin projection');
 $m->cancelWish($users[0],$ids[0],'it367:cancel:'.$users[0],$now);
 check($m->wish($users[0],$ids[0],'it367:legacy:'.$users[0],$now)==$out,'correct legacy replay immutable');
 $fresh=array_column($ratings->getCatalogueProjection($users[0],false,$now)['rows'],null,'id')[$ids[0]];
 check($m->getWishQuota($users[0],$now)['used']===1,'cancel never refunds read quota');
 check(!$fresh['own_active']&&$fresh['wishes']===3,'fresh row after accepted replay retains cancellation');
 catalogueThrows(fn()=>$m->wish($users[1],$ids[0],'it367:legacy:'.$users[0]),'foreign key blocked');
 catalogueThrows(fn()=>$m->wish($users[0],$ids[1],'it367:legacy:'.$users[0]),'target key blocked');
 catalogueThrows(fn()=>$m->cancelWish($users[0],$ids[0],'it367:legacy:'.$users[0]),'kind key blocked');
 $db->query('UPDATE users SET is_banned=1 WHERE id='.$users[0]);
 catalogueThrows(fn()=>$m->wish($users[0],$ids[0],'it367:legacy:'.$users[0]),'ban before saved replay','');
 $db->query('UPDATE users SET is_banned=0,muted_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR) WHERE id='.$users[0]);
 catalogueThrows(fn()=>$m->cancelWish($users[0],$ids[0],'it367:cancel:'.$users[0]),'mute before saved replay','');
 $db->query('UPDATE users SET muted_until=NULL WHERE id='.$users[0]);
 $db->query("UPDATE episode_list SET IMDB_PROVENANCE=JSON_SET(IMDB_PROVENANCE,'$.batch_hash','invalid') WHERE ID=".$ids[0]);
 $bad=array_column($ratings->getCatalogueProjection(null,false,$now)['rows'],null,'id')[$ids[0]];
 check($bad['rating']['score']===null&&$bad['rating']['histogram']===null,'wrong batch unknown');
 $original=$db->query('SELECT * FROM episode_list WHERE ID='.$ids[1])->fetch_assoc();
 $variants=[
  ['IMDB_PROVENANCE','"scalar"','score',null],
  ['IMDB_ID','javascript:bad','score',null],
  ['IMDB_RATING',0,'score',null],
  ['IMDB_VOTES',0,'score',null],
  ['IMDB_RETRIEVED_AT',null,'score',null],
  ['IMDB_RETRIEVED_AT',gmdate('Y-m-d H:i:s',$now+301),'score',null],
  ['IMDB_SOURCE_ASOF','2030-01-01 00:00:00','score',null],
  ['IMDB_HISTOGRAM','[99]','histogram',null],
  ['IMDB_HISTOGRAM','[0,0,0,0,100,0,0,0,0,0]','histogram',null],
  ['IMDB_HISTOGRAM','[-1,0,0,0,100,0,0,0,0,0]','histogram',null],
  ['IMDB_SD',2.0,'sd',null],
 ];
 foreach($variants as [$column,$value,$field,$expected]){
  $q=$db->prepare('UPDATE episode_list SET '.$column.'=? WHERE ID=?');$q->bind_param('si',$value,$ids[1]);$q->execute();
  $r=array_column($ratings->getCatalogueProjection(null,false,$now)['rows'],null,'id')[$ids[1]];
  check($r['rating'][$field]===$expected,'malformed observation isolated '.$column.':'.$field);
  $value=$original[$column];$q->bind_param('si',$value,$ids[1]);$q->execute();
 }
 $db->query('UPDATE episode_list SET TWOPART_ID=ID WHERE ID='.$ids[1]);
 check(array_column($ratings->getCatalogueProjection()['rows'],null,'id')[$ids[1]]['related']===null,'invalid partner never fabricated');
 $db->query('UPDATE episode_list SET TWOPART_ID='.$ids[0].' WHERE ID='.$ids[1]);
 $header=$config->getOptionDetails(Domain\EpisodeRatingManager::HEADER_KEY);
 $config->setOption(Domain\EpisodeRatingManager::HEADER_KEY,'"invalid"');
 check($ratings->getCatalogueProjection()['ratings']['status']==='unavailable','invalid header safely unavailable');
 $config->setOption(Domain\EpisodeRatingManager::HEADER_KEY,$header['value']);
 $key='it367:deleted:'.$users[0];$m->wish($users[0],$ids[2],$key,$now);
 $uuid='12345678-abcd-0123-4567-123456789abc';
 $reply=json_decode(catalogueFinish(catalogueChild(['controller',(string)$users[1],'wish',(string)$ids[1],$uuid])),true);
 check($reply['success']&&$reply['data']['row']['own_active']&&!isset($reply['data']['row']['admin']),'controller trusted actor no submitted admin/actor');
 $cancel=json_decode(catalogueFinish(catalogueChild(['controller',(string)$users[1],'cancelWish',(string)$ids[1],$uuid])),true);
 check(!$cancel['data']['row']['own_active']&&$cancel['data']['quota']['used']===2,'controller cancellation current state and unrefunded quota');
 $replay=json_decode(catalogueFinish(catalogueChild(['controller',(string)$users[1],'wish',(string)$ids[1],$uuid])),true);
 check($replay['data']['outcome']['code']==='accepted'&&!$replay['data']['row']['own_active'],'controller saved acceptance plus current inactive row');
 $missing=json_decode(catalogueFinish(catalogueChild(['controller',(string)$users[1],'wish','2147483646',$uuid])),true);
 check($missing['data']['outcome']['code']==='missing'&&$missing['data']['row']===null,'missing target no fabricated row');
 $invalid=json_decode(catalogueFinish(catalogueChild(['controller',(string)$users[1],'wish','01','bad'])),true);
 check(!$invalid['success'],'controller rejects invalid request');
 $isolation=$db->query('SELECT @@transaction_isolation v')->fetch_assoc()['v'];
 $db->query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
 $GLOBALS['catalogue_marker']=sys_get_temp_dir().'/mlp367_'.bin2hex(random_bytes(6));
 try{
  $child=catalogueChild(['writer',$GLOBALS['catalogue_marker'],(string)$ids[1],(string)$users[1],(string)$ids[2],(string)$now]);
  $beforeProjection=(new PausedCatalogueRatingManager())->getCatalogueProjection($users[1],false,$now);
  $before=array_column($beforeProjection['rows'],null,'id')[$ids[1]];
  catalogueFinish($child);
  $afterProjection=$ratings->getCatalogueProjection($users[1],false,$now);
  $after=array_column($afterProjection['rows'],null,'id')[$ids[1]];
  check($before['wishes']===2&&$before['rating']['score']===8.2,'owned RR catalogue counts + observation coherent during concurrent commit');
  check($beforeProjection['viewer']['quota']['used']===2&&$afterProjection['viewer']['quota']['used']===3,'quota shares RR catalogue read point during concurrent acceptance');
  $q=$db->prepare('DELETE FROM episode_wish_events WHERE operation_key=?');$projectionKey='projection:'.$users[1];$q->bind_param('s',$projectionKey);$q->execute();
  check($after['wishes']===8&&$after['rating']['score']===9.7,'new read observes committed values');
  check($db->query('SELECT @@transaction_isolation v')->fetch_assoc()['v']==='READ-COMMITTED','read leaves session isolation unchanged');
 }finally{$db->query('SET SESSION TRANSACTION ISOLATION LEVEL '.str_replace('-',' ',$isolation));@unlink($GLOBALS['catalogue_marker']);@unlink($GLOBALS['catalogue_marker'].'_done');}
 $db->query("INSERT INTO episode_list(TITLE,LENGTH) VALUES('Catalogue nested quota fixture',1)");$fourth=$db->insert_id;$ids[]=$fourth;
 $marker=sys_get_temp_dir().'/mlp367_nested_'.bin2hex(random_bytes(6));
 try{
  $child=catalogueChild(['nested',$marker,(string)$users[1],(string)$fourth,(string)$now]);
  $until=microtime(true)+5;while(!is_file($marker)&&microtime(true)<$until)usleep(10000);
  check(is_file($marker),'outer RR read view established before latest acceptance');
  check($m->wish($users[1],$ids[2],'nested:third:'.$users[1],$now)['code']==='accepted','concurrent third accepted after outer snapshot');
  file_put_contents($marker.'_done','continue');$nested=json_decode(catalogueFinish($child),true);
  check($nested['code']==='daily_limit','nested stale read view cannot bypass current daily quota');
 }finally{@unlink($marker);@unlink($marker.'_done');}
 $historical=json_decode(catalogueFinish(catalogueChild(['controller',(string)$users[1],'wish',(string)$ids[1],$uuid])),true);
 check($historical['data']['outcome']['facts']['quota_remaining']===1&&$historical['data']['quota']['remaining']===0,'response current exhausted quota never immutable old remaining');
 check(!$historical['data']['row']['own_active'],'historical accepted replay retains currently cancelled row');
 $exhausted=json_decode(catalogueFinish(catalogueChild(['controller',(string)$users[1],'wish',(string)$fourth,$uuid])),true);
 check($exhausted['data']['outcome']['code']==='daily_limit'&&$exhausted['data']['quota']['remaining']===0,'rejected domain result still includes fresh exhausted quota');
 $tomorrow=$now+86400;$nextProjection=$ratings->getCatalogueProjection($users[1],false,$tomorrow);
 check($nextProjection['viewer']['quota']['remaining']===3,'new day projection current quota independent of old event facts');
 foreach(['replay','cooldown'] as $phase){
  $marker=sys_get_temp_dir().'/mlp367_event_'.bin2hex(random_bytes(6));
  $at=$phase==='cooldown'?$now+604801:$now;$target=$phase==='cooldown'?$ids[1]:$fourth;
  $parentKey='nested-'.$phase.':'.$users[1];$childKey=$phase==='replay'?$parentKey:$parentKey.':child';
  try{
   $child=catalogueChild(['nested',$marker,(string)$users[1],(string)$target,(string)$at,$childKey]);
   $until=microtime(true)+5;while(!is_file($marker)&&microtime(true)<$until)usleep(10000);
   $saved=$m->wish($users[1],$target,$parentKey,$at);file_put_contents($marker.'_done','continue');
   $current=json_decode(catalogueFinish($child),true);
   check($phase==='replay'?$current==$saved:$current['code']==='cooldown','nested current event '.$phase.' after established view');
  }finally{@unlink($marker);@unlink($marker.'_done');}
 }
 foreach(['ban','mute','delete'] as $mode){
  $marker=sys_get_temp_dir().'/mlp367_grant_'.bin2hex(random_bytes(6));
  $child=catalogueChild(['grant-wait',$marker,(string)$users[0],(string)$fourth,$mode]);
  $until=microtime(true)+5;while(!is_file($marker)&&microtime(true)<$until)usleep(10000);
  $db->begin_transaction();
  try{
   $db->query('SELECT user_id FROM episode_wish_locks WHERE user_id='.$users[0].' FOR UPDATE')->fetch_row();
   if($mode==='delete')$db->query('DELETE FROM users WHERE id='.$users[0]);
   else $db->query('UPDATE users SET '.($mode==='ban'?'is_banned=1':'muted_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR)').' WHERE id='.$users[0]);
   file_put_contents($marker.'_done','attempt');usleep(100000);$db->commit();
   $denied=json_decode(catalogueFinish($child),true);
   check($denied['blocked'],'current '.$mode.' grant after quota wait rejects stale outer RR actor');
  }finally{if($mode!=='delete')$db->query('UPDATE users SET is_banned=0,muted_until=NULL WHERE id='.$users[0]);@unlink($marker);@unlink($marker.'_done');}
 }
 $db->query('DELETE FROM users WHERE id='.$users[0]);
 catalogueThrows(fn()=>$m->wish($users[0],$ids[2],$key),'deleted actor cannot replay','');
 $ghost=2147483646;
 catalogueThrows(fn()=>$m->wish($ghost,$ids[2],'it367:ghost'),'missing actor rejected before lock','');
 check((int)$db->query('SELECT COUNT(*) n FROM episode_wish_locks WHERE user_id='.$ghost)->fetch_assoc()['n']===0,'missing actor creates no lock');
 $quotaActor=(new Domain\UserManager())->createUser('it367_'.bin2hex(random_bytes(6)),'Test368!Password','user');$users[]=$quotaActor;
 $at=strtotime('2026-10-10T08:00:00Z');$start=strtotime('2026-10-09T22:00:00Z');$end=strtotime('2026-10-10T22:00:00Z');
 $records=[[$ids[0],'wish','accepted',$start-1],[$ids[0],'wish','accepted',$start],[$ids[0],'wish','accepted',$at],[$ids[1],'wish','accepted',$at],[$ids[2],'wish','accepted',$at],[$fourth,'wish','accepted',$at],[$fourth,'wish','rejected',$at],[$fourth,'cancel','cancelled',$at],[$fourth,'legacy','accepted',$at],[$ids[2],'wish','accepted',$end]];
 foreach($records as $i=>[$target,$kind,$status,$stamp]){
  $token='quota:'.$quotaActor.':'.$i;$date=gmdate('Y-m-d H:i:s',$stamp);
  $q=$db->prepare('INSERT INTO episode_wish_events(operation_key,user_id,episode_id,kind,status,created_at,outcome_json) VALUES(?,?,?,?,?,?,"{}")');$q->bind_param('siisss',$token,$quotaActor,$target,$kind,$status,$date);$q->execute();
 }
 foreach([[$ids[1],'cancelled'],[$ids[2],'fulfilled']] as [$target,$state]){
  $date=gmdate('Y-m-d H:i:s',$at);$q=$db->prepare('INSERT INTO episode_wishes(user_id,episode_id,status,accepted_at,updated_at) VALUES(?,?,?,?,?)');$q->bind_param('iisss',$quotaActor,$target,$state,$date,$date);$q->execute();
 }
 check($m->getUserWishes($quotaActor)===[]&&$m->getWishQuota($quotaActor,$at)['used']===4,'cancelled and fulfilled accepted wishes remain daily history');
 $beforeWrites=$db->query('SELECT COUNT(*) n FROM episode_wish_events WHERE user_id='.$quotaActor)->fetch_assoc()['n'];
 $quota=$m->getWishQuota($quotaActor,$at);
 check($quota===['limit'=>3,'used'=>4,'remaining'=>0,'day'=>'2026-10-10','timezone'=>'Europe/Kaliningrad','observed_at'=>'2026-10-10T08:00:00Z','resets_at'=>'2026-10-10T22:00:00Z'],'distinct accepted history uncapped used4, ignored rejected/cancel/legacy, exact UTC DTO');
 check($m->getWishQuota($quotaActor,$start-1)['used']===1&&$m->getWishQuota($quotaActor,$start)['used']===4,'midnight inclusive start and exclusive prior day');
 check($m->getWishQuota($quotaActor,$end)['used']===1,'end boundary moves accepted event to next day');
 check($db->query('SELECT COUNT(*) n FROM episode_wish_events WHERE user_id='.$quotaActor)->fetch_assoc()['n']===$beforeWrites&&(int)$db->query('SELECT COUNT(*) n FROM episode_wish_locks WHERE user_id='.$quotaActor)->fetch_assoc()['n']===0,'quota read writes no event or lock');
 catalogueThrows(fn()=>$m->getWishQuota(0,$at),'invalid quota actor rejected','');
 $db->query('DELETE FROM episode_wish_events WHERE user_id='.$quotaActor);
 $replayUUID='abcdef12-abcd-0123-4567-123456789abc';$savedKey=Api\EpisodeCatalogueController::operationKey($quotaActor,'wish',$fourth,$replayUUID);
 $yesterday=$m->wish($quotaActor,$fourth,$savedKey,$now-86400);
 $m->cancelWish($quotaActor,$fourth,'quota-yesterday-cancel:'.$quotaActor,$now);
 $nextDay=json_decode(catalogueFinish(catalogueChild(['controller',(string)$quotaActor,'wish',(string)$fourth,$replayUUID])),true);
 check($yesterday['facts']['quota_remaining']===2&&$nextDay['data']['outcome']['facts']['quota_remaining']===2&&$nextDay['data']['quota']['remaining']===3&&!$nextDay['data']['row']['own_active'],'actual accepted old-day replay returns current next-day quota and inactive row');

 $db->begin_transaction();try{catalogueThrows(fn()=>$ratings->getCatalogueProjection(), 'nested read refuses','rating_selection_nested_transaction');}finally{$db->rollback();}
}finally{
 foreach($users as $u){$db->query('DELETE FROM episode_wish_events WHERE user_id='.$u);$db->query('DELETE FROM episode_wishes WHERE user_id='.$u);$db->query('DELETE FROM episode_wish_locks WHERE user_id='.$u);$db->query('DELETE FROM users WHERE id='.$u);}
 foreach($ids as $id)$db->query('DELETE FROM episode_list WHERE ID='.$id);
 if($oldHeader===null)$db->query("DELETE FROM site_options WHERE key_name='episode_ratings_snapshot'");else {$s=$db->prepare('UPDATE site_options SET value=?,updated_at=? WHERE key_name=?');$s->bind_param('sss',$oldHeader['value'],$oldHeader['updated_at'],$oldHeader['key_name']);$s->execute();}
 $config->flushCache();
}
it_done();
