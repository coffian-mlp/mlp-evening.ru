<?php
require_once dirname(__DIR__).'/integration_helpers.php';
if(PHP_SAPI!=='cli'||!is_file('/.dockerenv')||(it_config()['db']['host']??'')!=='db')throw new RuntimeException('Isolated Docker database required');
$db=Infra\Database::getInstance()->getConnection();$manager=new Domain\EpisodeManager();$config=Infra\ConfigManager::getInstance();
$path=dirname(__DIR__,2).'/docs/private/mlp427-local.json';$mode=$argv[1]??'';
if($mode==='setup') {
    if(is_file($path))throw new RuntimeException('Cleanup previous fixture first');
    if((int)$db->query('SELECT COUNT(*) n FROM playlist_completions')->fetch_assoc()['n'])throw new RuntimeException('Empty isolated viewing history required');
    if((int)$db->query('SELECT COUNT(*) n FROM playlist_occurrences')->fetch_assoc()['n'])throw new RuntimeException('Empty isolated occurrence history required');
    $state=['lengths'=>$db->query('SELECT ID,LENGTH FROM episode_list')->fetch_all(MYSQLI_ASSOC),'episodes'=>[],'previousSnapshotMax'=>(int)$db->query('SELECT COALESCE(MAX(id),0) n FROM playlist_snapshots')->fetch_assoc()['n']];
    file_put_contents($path,json_encode($state,JSON_THROW_ON_ERROR));chmod($path,0600);
    $db->query('UPDATE episode_list SET LENGTH=0');
    foreach(['Recent pair one','Recent pair two','Fresh option'] as $i=>$name) {
        $title='My Little Pony Friendship is Magic - Season 98 Episode '.($i+1).' - '.$name;
        $q=$db->prepare('INSERT INTO episode_list(TITLE,LENGTH) VALUES(?,1)');$q->bind_param('s',$title);$q->execute();$state['episodes'][]=(int)$db->insert_id;
        file_put_contents($path,json_encode($state,JSON_THROW_ON_ERROR));
    }
    [$first,$second]=$state['episodes'];$db->query('UPDATE episode_list SET TWOPART_ID='.$second.' WHERE ID='.$first);$db->query('UPDATE episode_list SET TWOPART_ID='.$first.' WHERE ID='.$second);
    $stories=Domain\EpisodeCatalog::normalize($manager->getAllEpisodes());$payload=json_encode([$stories[0]],JSON_THROW_ON_ERROR);
    $q=$db->prepare('INSERT INTO playlist_snapshots(created_at,payload_json,origin) VALUES(UTC_TIMESTAMP(),?,"test")');$q->bind_param('s',$payload);$q->execute();$state['source']=(int)$db->insert_id;
    file_put_contents($path,json_encode($state,JSON_THROW_ON_ERROR));
    $manager->completeSnapshot($state['source']);
    $config->setOption('current_playlist_snapshot_id',(string)$state['source']);$config->setOption('current_playlist',$payload);
    echo "Fixture ready\n";
} elseif($mode==='cleanup') {
    $state=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    foreach($state['episodes'] as $id) {
        $row=$db->query('SELECT TITLE FROM episode_list WHERE ID='.(int)$id)->fetch_assoc();
        if($row && !str_starts_with($row['TITLE'],'My Little Pony Friendship is Magic - Season 98 Episode '))throw new RuntimeException('Fixture identity mismatch');
    }
    $snapshots=array_column($db->query('SELECT id FROM playlist_snapshots WHERE id>'.(int)$state['previousSnapshotMax'])->fetch_all(MYSQLI_ASSOC),'id');
    if($snapshots) {
        $list=implode(',',array_map('intval',$snapshots));
        $db->query('DELETE FROM playlist_corrections WHERE snapshot_id IN ('.$list.')');$db->query('DELETE FROM playlist_completions WHERE snapshot_id IN ('.$list.')');$db->query('DELETE FROM playlist_snapshots WHERE id IN ('.$list.')');
    }
    foreach($state['episodes'] as $id){$db->query('DELETE FROM watching_now WHERE EPNUM='.(int)$id);$db->query('DELETE FROM episode_list WHERE ID='.(int)$id);}
    foreach($state['lengths'] as $row)$db->query('UPDATE episode_list SET LENGTH='.(int)$row['LENGTH'].' WHERE ID='.(int)$row['ID']);
    unlink($path);echo "Fixture removed\n";
} else throw new RuntimeException('Use setup or cleanup');
