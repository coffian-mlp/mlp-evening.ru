<?php
require_once dirname(__DIR__).'/integration_helpers.php';
if(PHP_SAPI!=='cli'||!is_file('/.dockerenv')||(it_config()['db']['host']??'')!=='db')exit(1);
it_require_db();$db=Infra\Database::getInstance()->getConnection();$m=new Domain\EpisodeManager();$users=new Domain\UserManager();
$path=dirname(__DIR__,2).'/docs/private/mlp395-local.json';$action=$argv[1]??'';
if($action==='setup'){
 if(is_file($path))throw new RuntimeException('Existing fixture');
 $s=['login'=>'it_mlp395_'.bin2hex(random_bytes(6)),'password'=>bin2hex(random_bytes(24))];$s['user']=$users->createUser($s['login'],$s['password'],'admin');
 $db->query("INSERT INTO episode_list(TITLE,LENGTH) VALUES('My Little Pony Friendship is Magic - Season 97 Episode 01 - HTTP correction fixture',1)");$s['episode']=$db->insert_id;
 $story=Domain\EpisodeCatalog::normalize(array_values(array_filter($m->getAllEpisodes(),fn($r)=>(int)$r['ID']===$s['episode'])))[0];$json=json_encode([$story]);
 $q=$db->prepare('INSERT INTO playlist_snapshots(created_at,payload_json,origin) VALUES(UTC_TIMESTAMP(),?,"test")');$q->bind_param('s',$json);$q->execute();$s['snapshot']=$q->insert_id;
 $now=time()-172800;$m->wish($s['user'],$s['episode'],'fixture395:wish:'.$s['user'],$now);
 foreach(['A','B'] as $i=>$letter){$run='fixture395:'.$s['user'].':'.$letter;$s[$letter]=$run;$start=$now+3600+$i*86400;$m->bindOccurrence(['run_id'=>$run,'id'=>0,'real_start_time'=>$start,'duration_minutes'=>10],$s['snapshot']);$m->completeSnapshot($s['snapshot'],$run,null,$start+600);}
 $old=umask(0077);file_put_contents($path,json_encode($s,JSON_THROW_ON_ERROR));umask($old);chmod($path,0600);
}else{
 $s=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
 if(!str_starts_with($users->getUserById($s['user'])['login']??'','it_mlp395_'))throw new RuntimeException('Unexpected fixture owner');
 $uid=(int)$s['user'];$eid=(int)$s['episode'];$sid=(int)$s['snapshot'];
 if($action==='check'){
  $r=$db->query("SELECT status,fulfilled_completion_key FROM episode_wishes WHERE user_id=$uid AND episode_id=$eid")->fetch_assoc();
  if($r['status']!=='fulfilled'||$r['fulfilled_completion_key']!==$s['B'])throw new RuntimeException('Other showing did not preserve fulfillment');
 }elseif($action==='cleanup'){
  $db->query("DELETE FROM playlist_corrections WHERE snapshot_id=$sid");$db->query("DELETE FROM playlist_completions WHERE snapshot_id=$sid");$db->query("DELETE FROM playlist_occurrences WHERE snapshot_id=$sid");$db->query("DELETE FROM playlist_snapshots WHERE id=$sid");
  $db->query("DELETE FROM episode_wish_events WHERE user_id=$uid");$db->query("DELETE FROM episode_wishes WHERE user_id=$uid");$db->query("DELETE FROM episode_wish_locks WHERE user_id=$uid");$db->query("DELETE FROM watching_now WHERE EPNUM=$eid");$db->query("DELETE FROM episode_list WHERE ID=$eid");$users->deleteUser($uid);unlink($path);
 }else throw new RuntimeException('Unknown operation');
}
echo "Fixture operation PASS\n";
