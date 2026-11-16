<?php
require_once dirname(__DIR__).'/integration_helpers.php';
if (PHP_SAPI!=='cli' || !is_file('/.dockerenv') || (it_config()['db']['host']??'')!=='db' || Infra\Env::get('DB_NAME')!=='mlp_phase3' || !is_file(dirname(__DIR__,2).'/.mlp472-owned')) throw new RuntimeException('Owned MLP-472 Docker database required');
it_require_db();
$db=Infra\Database::getInstance()->getConnection();$m=new Domain\EpisodeManager();
$file=dirname(__DIR__,2).'/docs/tests/MLP-472/fixture.json';$mode=$argv[1]??'';
if ($mode==='setup') {
    if (is_file($file)) throw new RuntimeException('Cleanup existing fixture first');
    $s=['marker'=>'it_mlp472_'.bin2hex(random_bytes(6)), 'users'=>[], 'episodes'=>[], 'events'=>$db->query('SELECT id,use_playlist FROM events')->fetch_all(MYSQLI_ASSOC), 'options'=>$db->query("SELECT key_name,value,updated_at FROM site_options WHERE key_name IN ('current_playlist_snapshot_id','current_playlist','ai_enabled')")->fetch_all(MYSQLI_ASSOC)];
    $save=static function()use(&$s,$file){$mask=umask(0077);file_put_contents($file,json_encode($s,JSON_THROW_ON_ERROR));chmod($file,0600);umask($mask);};$save();
    Infra\ConfigManager::getInstance()->setOption('ai_enabled','0');
    $db->query('UPDATE events SET use_playlist=0');
    foreach (['admin','user'] as $role) {
        $login=$s['marker'].'_'.$role;$password=bin2hex(random_bytes(20));$id=(new Domain\UserManager())->createUser($login,$password,$role,'Editor '.$role);
        $s['users'][$role]=compact('id','login','password');$save();Domain\Privacy::setAccountConsent($id,true);
    }
    foreach (['Alpha','Beta','Gamma','Movie','Part A','Part B'] as $i=>$name) {
        $title=($i>=4?'My Little Pony Friendship is Magic - Season 99 Episode '.($i+1).' - ':'').$s['marker'].' '.$name;
        $q=$db->prepare('INSERT INTO episode_list(TITLE,LENGTH) VALUES(?,?)');$length=$i===3?4:1;$q->bind_param('si',$title,$length);$q->execute();$s['episodes'][$name]=$db->insert_id;$save();
    }
    $db->query('UPDATE episode_list SET TWOPART_ID='.$s['episodes']['Part B'].' WHERE ID='.$s['episodes']['Part A']);
    $db->query('UPDATE episode_list SET TWOPART_ID='.$s['episodes']['Part A'].' WHERE ID='.$s['episodes']['Part B']);
    $stories=[];foreach(Domain\EpisodeCatalog::normalize($m->getAllEpisodes()) as $story)if(in_array($story['ids'][0],[$s['episodes']['Alpha'],$s['episodes']['Beta']],true))$stories[]=$story;
    $json=json_encode($stories);$q=$db->prepare('INSERT INTO playlist_snapshots(created_at,payload_json,origin) VALUES(UTC_TIMESTAMP(),?,"test")');$q->bind_param('s',$json);$q->execute();$s['snapshot']=$db->insert_id;$save();
    $s['start']=time()+3600;$start=gmdate('Y-m-d H:i:s',$s['start']);$q=$db->prepare('INSERT INTO events(title,start_time,duration_minutes,use_playlist) VALUES(?,?,60,1)');$q->bind_param('ss',$s['marker'],$start);$q->execute();$s['event']=$db->insert_id;$s['run']=$s['event'].'_'.$s['start'];$save();
    $m->bindOccurrence(['id'=>$s['event'],'run_id'=>$s['run'],'real_start_time'=>$s['start'],'duration_minutes'=>60,'use_playlist'=>1],$s['snapshot']);
    Infra\ConfigManager::getInstance()->setOption('current_playlist_snapshot_id',(string)$s['snapshot']);Infra\ConfigManager::getInstance()->setOption('current_playlist',$json);
    // A prepared announcement is a read-only warning input; no worker runs in this laboratory.
    $q=$db->prepare('INSERT INTO telegram_announcement_campaigns(run_id,stream_number,start_at,fingerprint,facts_json) VALUES(?,3999,?,REPEAT("a",64),"{}")');$q->bind_param('ss',$s['run'],$start);$q->execute();$s['campaign']=$db->insert_id;$save();
    $db->query('INSERT INTO telegram_announcement_posts(campaign_id,kind,due_at,expires_at) VALUES('.$s['campaign'].',"main",UTC_TIMESTAMP(),UTC_TIMESTAMP())');$s['post']=$db->insert_id;$save();
    $db->query('INSERT INTO telegram_announcement_revisions(post_id,version,nonce,state,caption,feedback,created_at) VALUES('.$s['post'].',1,"fixture472","approved","Prepared fixture","",UTC_TIMESTAMP())');
    echo json_encode(['marker'=>$s['marker'],'users'=>$s['users'],'run'=>$s['run'],'snapshot'=>$s['snapshot'],'episodes'=>$s['episodes']],JSON_THROW_ON_ERROR);
} else {
    $s=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
    if ($mode==='inspect') echo json_encode($m->getPlaylistEditor(),JSON_THROW_ON_ERROR);
    elseif ($mode==='cleanup') {
        foreach ($s['users'] as $u) if (((new Domain\UserManager())->getUserById($u['id'])['login']??'')!==$u['login']) throw new RuntimeException('Fixture ownership mismatch');
        $q=$db->prepare('DELETE FROM playlist_occurrences WHERE run_id=?');$q->bind_param('s',$s['run']);$q->execute();
        $db->query('DELETE FROM playlist_snapshots WHERE id='.(int)$s['snapshot']);
        $db->query('DELETE FROM telegram_announcement_revisions WHERE post_id='.(int)$s['post']);$db->query('DELETE FROM telegram_announcement_posts WHERE id='.(int)$s['post']);$db->query('DELETE FROM telegram_announcement_campaigns WHERE id='.(int)$s['campaign']);
        (new Domain\EventManager())->delete($s['event']);
        $q=$db->prepare('UPDATE events SET use_playlist=? WHERE id=?');foreach($s['events'] as $r){$q->bind_param('ii',$r['use_playlist'],$r['id']);$q->execute();}
        $db->query('DELETE FROM episode_list WHERE ID IN ('.implode(',',array_map('intval',$s['episodes'])).')');
        $db->query("DELETE FROM site_options WHERE key_name IN ('current_playlist_snapshot_id','current_playlist','ai_enabled')");$q=$db->prepare('INSERT INTO site_options(key_name,value,updated_at) VALUES(?,?,?)');foreach($s['options'] as $r){$q->bind_param('sss',$r['key_name'],$r['value'],$r['updated_at']);$q->execute();}
        foreach($s['users'] as $u){Domain\Privacy::stageErasure($u['id'],[]);(new Domain\UserManager())->deleteUser($u['id']);}
        unlink($file);echo json_encode(['clean'=>true]);
    } else throw new RuntimeException('Unknown fixture mode');
}
