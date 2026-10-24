<?php
/** Isolated fixture for a complete existing catalogue rating slice. */
function extensionBackup(mysqli $db): array
{
    return ['rows'=>$db->query('SELECT * FROM episode_list ORDER BY ID')->fetch_all(MYSQLI_ASSOC),
        'header'=>Infra\ConfigManager::getInstance()->getOptionDetails(Domain\EpisodeRatingManager::HEADER_KEY)];
}

function extensionSeed(mysqli $db,array $state): array
{
    $fingerprint=Domain\EpisodeRatingSnapshot::catalogFingerprint($state['rows']);
    $input=['schema_version'=>1,'source'=>'imdb','parent_series'=>'tt1751105','catalog_fingerprint'=>$fingerprint,
        'scope'=>['kind'=>'catalogue','ids'=>array_map(static fn($row)=>(int)$row['ID'],$state['rows']),'coverage'=>'complete'],'records'=>[]];
    $mapping=array_diff_key($input,['scope'=>true]);
    // Install fixture identities before apply(), whose remapping protection is deliberately strict.
    foreach ($state['rows'] as $row) {
        $id=(int)$row['ID'];$imdb='tt800'.str_pad((string)$id,7,'0',STR_PAD_LEFT);$meta=Domain\EpisodeCatalog::metadata($row['TITLE']);
        $q=$db->prepare('UPDATE episode_list SET IMDB_ID=? WHERE ID=?');$q->bind_param('si',$imdb,$id);$q->execute();
        $mapping['records'][]=['episode_id'=>$id,'imdb_id'=>$imdb,'kind'=>$meta?'episode':'special','season'=>$meta['season']??null,'episode'=>$meta['episode']??null,'identity_url'=>'https://www.imdb.com/title/'.$imdb.'/'];
        $input['records'][]=['episode_id'=>$id,'imdb_id'=>$imdb,'rating'=>8.2,'votes'=>120,'histogram'=>[0,0,0,0,0,0,0,120,0,0],
            'retrieved_at'=>gmdate('Y-m-d\TH:i:s\Z',time()-86400),'source_asof'=>null,'source_url'=>'https://www.imdb.com/title/'.$imdb.'/ratings/',
            'vote_scope'=>'all_countries','provenance'=>['channel'=>'ordinary_browser_dom']];
    }
    $header=$state['header']?json_decode($state['header']['value'],true):null;
    return (new Domain\EpisodeRatingManager())->apply($input,$mapping,$header['batch_hash']??null);
}

function extensionRestore(mysqli $db,array $state): void
{
    $original=array_map(static fn($row)=>(int)$row['ID'],$state['rows']);
    $entries=json_decode(file_get_contents(dirname(__DIR__).'/resources/catalogue/equestria-girls.json'),true)['entries'];
    foreach ($entries as $entry) {
        $q=$db->prepare('SELECT ID FROM episode_list WHERE TITLE=?');$q->bind_param('s',$entry['title']);$q->execute();
        foreach ($q->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $id=(int)$row['ID'];if(in_array($id,$original,true))continue;
            $q2=$db->prepare('DELETE FROM episode_wish_events WHERE episode_id=?');$q2->bind_param('i',$id);$q2->execute();
            $q2=$db->prepare('DELETE FROM episode_wishes WHERE episode_id=?');$q2->bind_param('i',$id);$q2->execute();
            $db->query('DELETE FROM episode_list WHERE ID='.$id);
        }
    }
    $columns=['TIMES_WATCHED','WANNA_WATCH','legacy_wanna_watch','LENGTH','IMDB_ID','IMDB_RATING','IMDB_VOTES','IMDB_HISTOGRAM','IMDB_SD','IMDB_RETRIEVED_AT','IMDB_SOURCE_ASOF','IMDB_PROVENANCE'];
    foreach ($state['rows'] as $row) {
        $args=[];foreach($columns as $key)$args[]=$row[$key];$args[]=$row['ID'];
        $q=$db->prepare('UPDATE episode_list SET '.implode(',',array_map(static fn($key)=>$key.'=?',$columns)).' WHERE ID=?');
        $q->bind_param(str_repeat('s',count($args)),...$args);$q->execute();
    }
    $key=Domain\EpisodeRatingManager::HEADER_KEY;
    $q=$db->prepare('DELETE FROM site_options WHERE key_name=?');$q->bind_param('s',$key);$q->execute();
    if ($state['header']) {
        $q=$db->prepare('INSERT INTO site_options(key_name,value,updated_at) VALUES(?,?,?)');
        $value=$state['header']['value'];$at=$state['header']['updated_at'];$q->bind_param('sss',$key,$value,$at);$q->execute();
    }
    Infra\ConfigManager::getInstance()->flushCache();
}
