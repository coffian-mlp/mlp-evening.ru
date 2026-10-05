<?php
require_once __DIR__.'/integration_helpers.php';
if((it_config()['db']['host']??'')!=='db')it_skip('requires isolated db');
$db=it_require_db();
if(($argv[1]??'')==='race') { $out=(new Domain\EpisodeManager())->wish((int)$argv[2],(int)$argv[3],$argv[4],(int)$argv[5]);echo json_encode($out);exit(0); }
$m=new Domain\EpisodeManager();$u=(new Domain\UserManager())->createUser('it_mlp361_'.bin2hex(random_bytes(5)),'Test361!Password','user');$ids=[];
try{
 for($i=0;$i<4;$i++){$db->query("INSERT INTO episode_list(TITLE,LENGTH) VALUES('MLP361 fixture',1)");$ids[]=$db->insert_id;}
 $now=strtotime('2026-10-10 10:00:00 UTC');
 $m->wish($u,$ids[0],'race:seed1:'.$u,$now-604800);$m->wish($u,$ids[1],'race:seed2:'.$u,$now-604800);
 $m->wish($u,$ids[0],'race:daily1:'.$u,$now);$m->wish($u,$ids[1],'race:daily2:'.$u,$now);
 $processes=[];foreach([$ids[2],$ids[3]] as $id){$pipes=[];$proc=proc_open([PHP_BINARY,__FILE__,'race',(string)$u,(string)$id,'race:'.$u.':'.$id,(string)$now],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$processes[]=[$proc,$pipes];}
 $accepted=0;foreach($processes as [$proc,$pipes]){$text=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($proc);if($code!==0)echo 'Worker error: '.$error.$text;check($code===0,'concurrent worker succeeds');$out=json_decode($text,true);if(($out['status']??'')==='accepted')$accepted++;}
 check($accepted===1,'concurrent remaining one');
 $db->query('DELETE FROM episode_wish_events WHERE user_id='.(int)$u);$db->query('DELETE FROM episode_wishes WHERE user_id='.(int)$u);
 foreach($ids as $i=>$id){$out=$m->wish($u,$id,'it361:'.$u.':'.$id,$now);check($out['status']===($i<3?'accepted':'rejected'),'daily three '.$i);}
 $replay=$m->wish($u,$ids[0],'it361:'.$u.':'.$ids[0],$now);check($replay['status']==='accepted','replay');
 $m->cancelWish($u,$ids[0],'it361:cancel:'.$u,$now+1);check($m->wish($u,$ids[0],'it361:early:'.$u,$now+86400)['code']==='cooldown','cancel preserves cooldown');
 check($m->wish($u,$ids[0],'it361:refresh:'.$u,$now+604800)['status']==='accepted','168h refresh');
 check(count($m->getUserWishes($u))===3,'active unique');
 check($m->wish($u,$ids[1],'it361:boundaryearly:'.$u,$now+604799)['code']==='cooldown','168h minus one second');
 $dayEnd=strtotime('2026-10-30 21:59:59 UTC');
 foreach(array_slice($ids,0,3) as $id)check($m->wish($u,$id,'day:'.$u.':'.$id,$dayEnd)['status']==='accepted','day boundary accepted');
 $m->cancelWish($u,$ids[0],'daycancel:'.$u,$dayEnd);
 check($m->wish($u,$ids[3],'dayfourth:'.$u,$dayEnd)['code']==='daily_limit','cancel does not refund daily');
 check($m->wish($u,$ids[3],'daynext:'.$u,$dayEnd+1)['status']==='accepted','Kaliningrad midnight resets quota');
check(!$m->voteForEpisode(999999),'missing vote rejects');
}finally{$db->query('DELETE FROM episode_wish_events WHERE user_id='.(int)$u);$db->query('DELETE FROM episode_wishes WHERE user_id='.(int)$u);$db->query('DELETE FROM episode_wish_locks WHERE user_id='.(int)$u);foreach($ids as $id)$db->query('DELETE FROM episode_list WHERE ID='.(int)$id);$db->query('DELETE FROM users WHERE id='.(int)$u);}

it_done();
