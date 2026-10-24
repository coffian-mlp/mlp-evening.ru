<?php
require_once dirname(__DIR__).'/integration_helpers.php';
if (PHP_SAPI!=='cli'||!is_file('/.dockerenv')||(it_config()['db']['host']??'')!=='db') throw new RuntimeException('Isolated Docker required');
it_require_db()->close();require_once dirname(__DIR__).'/catalogue_extension_helpers.php';
$db=Infra\Database::getInstance()->getConnection();$path=dirname(__DIR__,2).'/docs/private/mlp435-local.json';
$mode=$argv[1]??'';
if($mode==='setup') {
    if(is_file($path))throw new RuntimeException('Fixture already exists');
    $state=extensionBackup($db);file_put_contents($path,json_encode($state,JSON_THROW_ON_ERROR));chmod($path,0600);
    extensionSeed($db,$state);
    $entries=json_decode(file_get_contents(dirname(__DIR__,2).'/resources/catalogue/equestria-girls.json'),true)['entries'];
    $m=new Domain\EpisodeRatingManager();$dry=$m->extendCatalogue($entries);$m->extendCatalogue($entries,true,$dry['expected_catalog'],$dry['expected_batch']);
    $state['ids']=[];
    foreach($entries as $entry){$q=$db->prepare('SELECT ID FROM episode_list WHERE IMDB_ID=?');$id=$entry['observation']['imdb_id'];$q->bind_param('s',$id);$q->execute();$state['ids'][$id]=(int)$q->get_result()->fetch_assoc()['ID'];}
    file_put_contents($path,json_encode($state,JSON_THROW_ON_ERROR));echo "Fixture ready\n";
} elseif($mode==='cleanup') {
    $state=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);extensionRestore($db,$state);unlink($path);echo "Fixture removed\n";
} else throw new RuntimeException('Use setup or cleanup');
