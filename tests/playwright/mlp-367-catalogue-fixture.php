<?php
/** Isolated actual owner/HTTP fixture; credentials and baselines stay private. */
require_once dirname(__DIR__) . '/integration_helpers.php';
if (PHP_SAPI !== 'cli' || !is_file('/.dockerenv') || (it_config()['db']['host'] ?? '') !== 'db') exit(1);
it_require_db();
$db = \Infra\Database::getInstance()->getConnection();
$config = \Infra\ConfigManager::getInstance();
$path = dirname(__DIR__, 2) . '/docs/private/mlp367-catalogue-local.json';
$columns = ['IMDB_ID','IMDB_RATING','IMDB_VOTES','IMDB_HISTOGRAM','IMDB_SD','IMDB_RETRIEVED_AT','IMDB_SOURCE_ASOF','IMDB_PROVENANCE'];
function catalogueSave(string $path, array $data): void {
    $mask = umask(0077);
    try { file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR)); chmod($path, 0600); } finally { umask($mask); }
}
function catalogueState(mysqli $db): array {
    $count = static fn($table) => (int)$db->query('SELECT COUNT(*) n FROM '.$table)->fetch_assoc()['n'];
    return ['wishes'=>$count('episode_wishes'),'events'=>$count('episode_wish_events'),'chat'=>$count('chat_messages'),'jobs'=>$count('llm_jobs')];
}
function catalogueImport(array $fixture): void {
    $catalog = (new \Domain\EpisodeManager())->getAllEpisodes();
    $base = ['schema_version'=>1,'source'=>'imdb','parent_series'=>'tt1751105','catalog_fingerprint'=>\Domain\EpisodeRatingSnapshot::catalogFingerprint($catalog)];
    $mapping = $base + ['records'=>[]];
    $input = $base + ['scope'=>['kind'=>'catalogue','ids'=>array_map(static fn($r)=>(int)$r['ID'],$catalog),'coverage'=>'complete'],'records'=>[]];
    foreach ($catalog as $row) {
        $id = (int)$row['ID']; $imdb = $row['IMDB_ID'] ?? ('tt'.(810000000+$id)); $meta = \Domain\EpisodeCatalog::metadata($row['TITLE']);
        $mapping['records'][] = ['episode_id'=>$id,'imdb_id'=>$imdb,'kind'=>$meta?'episode':'special','season'=>$meta['season']??null,'episode'=>$meta['episode']??null,'identity_url'=>'https://www.imdb.com/title/'.$imdb.'/'];
        $record = $fixture['ratings'][$id] ?? ['score'=>5.0,'bins'=>[0,0,0,0,100,0,0,0,0,0],'days'=>0];
        $input['records'][] = ['episode_id'=>$id,'imdb_id'=>$imdb,'rating'=>$record['score'],'votes'=>array_sum($record['bins']),'histogram'=>$record['bins'],'retrieved_at'=>gmdate('Y-m-d\TH:i:s\Z',time()-$record['days']*86400),'source_asof'=>null,'source_url'=>'https://www.imdb.com/title/'.$imdb.'/ratings/','vote_scope'=>'all_countries','provenance'=>['channel'=>'ordinary_browser_dom']];
    }
    $header = json_decode(\Infra\ConfigManager::getInstance()->getOptionDetails(\Domain\EpisodeRatingManager::HEADER_KEY)['value']??'null',true);
    (new \Domain\EpisodeRatingManager())->apply($input,$mapping,$header['batch_hash']??null);
}
function catalogueUser(string $key, string $role, array &$fixture): void {
    if (!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$key) || !in_array($role,['user','admin'],true)) throw new \RuntimeException('Invalid owned case');
    $login='it_user_mlp367_'.bin2hex(random_bytes(6));$password=bin2hex(random_bytes(24));
    $id=(new \Domain\UserManager())->createUser($login,$password,$role,'Catalogue Fixture');
    $fixture['users'][]=$id;$fixture['cases'][$key]=['userId'=>$id,'login'=>$login,'password'=>$password];
}
$mode=$argv[1]??'';
if ($mode==='setup') {
    if (is_file($path)) throw new \RuntimeException('Existing fixture must be cleaned');
    $fixture=['settings'=>$db->query('SELECT key_name,value,updated_at FROM site_options ORDER BY key_name')->fetch_all(MYSQLI_ASSOC),'original_ratings'=>$db->query('SELECT ID,'.implode(',',$columns).' FROM episode_list ORDER BY ID')->fetch_all(MYSQLI_ASSOC),
        'original_menu'=>$db->query('SELECT * FROM menu_items ORDER BY id')->fetch_all(MYSQLI_ASSOC),'users'=>[],'cases'=>[],'episodes'=>[],'ratings'=>[],'menu'=>[]];
    catalogueSave($path,$fixture);
    $config->setOption('ai_enabled','0');
    $names=['Alpha','Beta','Gamma','Delta','Stale','Low'];$scores=[8.2,2.0,9.0,8.2,6.2,6.3];$views=[2,10,2,2,0,1];$legacy=[2,0,10,0,0,0];
    foreach ($names as $i=>$name) {
        $title='My Little Pony Friendship is Magic - Season 97 Episode '.($i+1).' - Catalogue '.$name;
        $s=$db->prepare('INSERT INTO episode_list(TITLE,LENGTH,TIMES_WATCHED,WANNA_WATCH,legacy_wanna_watch) VALUES (?,1,?,?,?)');$s->bind_param('siii',$title,$views[$i],$legacy[$i],$legacy[$i]);$s->execute();$id=(int)$db->insert_id;
        $fixture['episodes'][]=$id;$fixture['ids'][strtolower($name)]=$id;
        $bins=($i===0||$i===3)?[50,0,0,0,0,0,0,0,0,50]:[0,0,0,100,0,0,0,0,0,0];if($i===5)$bins[3]=99;
        $fixture['ratings'][$id]=['score'=>$scores[$i],'bins'=>$bins,'days'=>$i===4?46:0];catalogueSave($path,$fixture);
    }
    foreach (['unknown'=>'Catalogue Unknown Special','unsafe'=>'Catalogue <img src=x onerror="window.mlp367Xss=1"> & literal &quot;'] as $key=>$title) {
        $s=$db->prepare('INSERT INTO episode_list(TITLE,LENGTH) VALUES (?,1)');$s->bind_param('s',$title);$s->execute();$fixture['episodes'][]=(int)$db->insert_id;$fixture['ids'][$key]=(int)$db->insert_id;catalogueSave($path,$fixture);
    }
    $db->query('UPDATE episode_list SET TWOPART_ID='.$fixture['ids']['beta'].' WHERE ID='.$fixture['ids']['alpha']);
    $db->query('UPDATE episode_list SET TWOPART_ID='.$fixture['ids']['alpha'].' WHERE ID='.$fixture['ids']['beta']);
    catalogueImport($fixture);
    $db->query('UPDATE episode_list SET IMDB_RATING=NULL,IMDB_VOTES=NULL,IMDB_HISTOGRAM=NULL,IMDB_SD=NULL WHERE ID='.$fixture['ids']['unknown']);
    catalogueUser('other','user',$fixture);catalogueSave($path,$fixture);
    (new \Domain\EpisodeManager())->wish($fixture['cases']['other']['userId'],$fixture['ids']['alpha'],'fixture:367:'.bin2hex(random_bytes(12)));
    $exists=array_filter($fixture['original_menu'],static fn($row)=>$row['url']==='/episodes.php');
    if (!$exists) {
        (new \Domain\MenuManager())->save(['title'=>'Эпизоды','url'=>'/episodes.php','sort_order'=>25,'is_active'=>1,'visibility'=>'all','show_in_header'=>1,'show_in_burger'=>1]);
        $fixture['menu'][]=(int)$db->query("SELECT id FROM menu_items WHERE url='/episodes.php'")->fetch_assoc()['id'];
    }
    (new \Domain\MenuManager())->flushCache();catalogueSave($path,$fixture);echo "Fixture ready\n";
} elseif ($mode==='user') {
    $fixture=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);catalogueUser($argv[2]??'',$argv[3]??'user',$fixture);catalogueSave($path,$fixture);echo "Actor ready\n";
} elseif ($mode==='render-ssr') {
    $fixture=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);$actor=(int)$fixture['cases'][$argv[2]]['userId'];
    $projection=(new \Domain\EpisodeRatingManager())->getCatalogueProjection($actor);
    $variant=$argv[3]??'';
    $patches=['array'=>['observed_at'=>[]],'null-byte'=>['resets_at'=>"bad\0"],'bad-day'=>['day'=>'2026-02-30'],'bad-count'=>['used'=>3,'remaining'=>3]];
    if($variant==='missing')unset($projection['viewer']['quota']);
    elseif(isset($patches[$variant]))$projection['viewer']['quota']=array_replace($projection['viewer']['quota'],$patches[$variant]);
    else throw new \RuntimeException('Invalid SSR boundary variant');
    $arResult=['catalogue'=>$projection,'admin'=>false];require dirname(__DIR__,2).'/src/Components/EpisodeCatalogue/templates/default/template.php';
} elseif ($mode==='state') {
    $fixture=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);$case=$fixture['cases'][$argv[2]??'']??null;
    $result=catalogueState($db);
    if ($case) {
        $id=(int)$case['userId'];$result['active']=$db->query('SELECT episode_id FROM episode_wishes WHERE user_id='.$id.' AND status="active" ORDER BY episode_id')->fetch_all(MYSQLI_ASSOC);
        $result['accepted']=(int)$db->query('SELECT COUNT(*) n FROM episode_wish_events WHERE user_id='.$id.' AND kind="wish" AND status="accepted"')->fetch_assoc()['n'];
        $result['locks']=(int)$db->query('SELECT COUNT(*) n FROM episode_wish_locks WHERE user_id='.$id)->fetch_assoc()['n'];
    }
    echo json_encode($result,JSON_THROW_ON_ERROR);
} elseif ($mode==='seed-wishes') {
    $fixture=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);$actor=$fixture['cases'][$argv[2]]['userId'];
    foreach (explode(',',$argv[3]??'') as $key) {
        if (!isset($fixture['ids'][$key])) throw new \RuntimeException('Owned episode required');
        $out=(new \Domain\EpisodeManager())->wish($actor,$fixture['ids'][$key],'fixture:367:'.bin2hex(random_bytes(12)));
        if ($out['code']!=='accepted') throw new \RuntimeException('Seed must be accepted');
    }
    echo "Domain wishes seeded\n";
} elseif (in_array($mode,['ban','mute','clear-grant','delete-user'],true)) {
    $fixture=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);$id=(int)$fixture['cases'][$argv[2]]['userId'];
    $clauses=['ban'=>'is_banned=1,ban_until=NULL','mute'=>'muted_until=UTC_TIMESTAMP()+INTERVAL 1 DAY','clear-grant'=>'is_banned=0,ban_until=NULL,muted_until=NULL'];
    if ($mode==='delete-user') $db->query('DELETE FROM users WHERE id='.$id);
    else $db->query('UPDATE users SET '.$clauses[$mode].' WHERE id='.$id);
    echo "Owned actor state changed\n";
} elseif ($mode==='cleanup') {
    $fixture=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);$users=new \Domain\UserManager();
    foreach ($fixture['users'] as $id) {
        $user=$users->getUserById($id);if($user&&!str_starts_with($user['login'],'it_user_mlp367_'))throw new \RuntimeException('Wrong owner');
        foreach (['episode_wishes'=>'user_id','episode_wish_events'=>'user_id','episode_wish_locks'=>'user_id','command_interactions'=>'owner_id'] as $table=>$column)$db->query('DELETE FROM '.$table.' WHERE '.$column.'='.(int)$id);
        $db->query('DELETE FROM chat_messages WHERE user_id='.(int)$id);$db->query('DELETE FROM llm_jobs WHERE JSON_EXTRACT(payload,"$.user_id")='.(int)$id);
        if($user)$users->deleteUser($id);
    }
    foreach ($fixture['episodes'] as $id) $db->query('DELETE FROM episode_list WHERE ID='.(int)$id);
    $query='UPDATE episode_list SET '.implode(',',array_map(static fn($c)=>$c.'=?',$columns)).' WHERE ID=?';
    foreach($fixture['original_ratings'] as $row){$values=array_map(static fn($c)=>$row[$c],$columns);$values[]=$row['ID'];$s=$db->prepare($query);$s->bind_param(str_repeat('s',count($values)),...$values);$s->execute();}
    foreach($fixture['menu'] as $id)(new \Domain\MenuManager())->delete($id);
    $before=array_column($fixture['settings'],null,'key_name');$current=$db->query('SELECT key_name,value,updated_at FROM site_options')->fetch_all(MYSQLI_ASSOC);
    foreach($current as $row)if(!isset($before[$row['key_name']])){$key=$row['key_name'];$s=$db->prepare('DELETE FROM site_options WHERE key_name=?');$s->bind_param('s',$key);$s->execute();}
    foreach($fixture['settings'] as $row){$s=$db->prepare('INSERT INTO site_options(key_name,value,updated_at) VALUES(?,?,?) ON DUPLICATE KEY UPDATE value=VALUES(value),updated_at=VALUES(updated_at)');$s->bind_param('sss',$row['key_name'],$row['value'],$row['updated_at']);$s->execute();}
    $config->flushCache();(new \Domain\MenuManager())->flushCache();unlink($path);echo "Fixture cleaned\n";
} else throw new \RuntimeException('setup|user|state|seed-wishes|ban|mute|clear-grant|delete-user|cleanup');
