<?php
require_once __DIR__.'/integration_helpers.php';
if ((it_config()['db']['host']??'')!=='db') it_skip('isolated Docker required');
it_require_db()->close();
require_once __DIR__.'/catalogue_extension_helpers.php';
$db=Infra\Database::getInstance()->getConnection();
$path=dirname(__DIR__).'/resources/catalogue/equestria-girls.json';
$entries=json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR)['entries'];
class ExtensionFaultManager extends Domain\EpisodeRatingManager {
    protected function writeRecord(array $row,array $slice): void {parent::writeRecord($row,$slice);throw new RuntimeException('extension-write-fault');}
}
class ExtensionPausedManager extends Domain\EpisodeRatingManager {
    private bool $paused=false;
    protected function writeRecord(array $row,array $slice): void {
        parent::writeRecord($row,$slice);
        if(!$this->paused){$this->paused=true;file_put_contents($GLOBALS['extension_marker'],'ready');usleep(500000);}
    }
}
if (($argv[1]??'')==='worker') {
    $GLOBALS['extension_marker']=$argv[4];
    echo json_encode((new ExtensionPausedManager())->extendCatalogue($entries,true,$argv[2],$argv[3]),JSON_THROW_ON_ERROR);exit;
}
$state=extensionBackup($db);$marker=sys_get_temp_dir().'/extension435_'.bin2hex(random_bytes(8));
$fail=static function(callable $operation,string $reason):bool {try{$operation();return false;}catch(Core\UserError $e){return $e->getMessage()===$reason;}};
$rows=static fn()=>$db->query('SELECT * FROM episode_list ORDER BY ID')->fetch_all(MYSQLI_ASSOC);
try {
    extensionSeed($db,$state);$manager=new Domain\EpisodeRatingManager();
    $db->query('UPDATE episode_list SET TIMES_WATCHED=7,legacy_wanna_watch=2 WHERE ID='.(int)$state['rows'][0]['ID']);
    $base=$rows();$header=Infra\ConfigManager::getInstance()->getOptionDetails(Domain\EpisodeRatingManager::HEADER_KEY);
    $dry=$manager->extendCatalogue($entries);
    check($dry['status']==='validated'&&$dry['added']===12&&$rows()===$base,'dry run validates full extension without inserting rows or altering observations');
    check(Infra\ConfigManager::getInstance()->getOptionDetails(Domain\EpisodeRatingManager::HEADER_KEY)===$header,'dry run preserves header including timestamp');
    $broken=$entries;$broken[0]['observation']['histogram'][0]++;
    check($fail(fn()=>$manager->extendCatalogue($broken),'histogram_votes_mismatch'),'inconsistent new histogram rejected');
    $broken=$entries;$broken[0]['observation']['source_url']='https://www.imdb.com/title/tt1234567/ratings/';
    check($fail(fn()=>$manager->extendCatalogue($broken),'wrong_identity_url'),'wrong source identity rejected');
    $broken=$entries;$broken[]=$broken[0];
    check($fail(fn()=>$manager->extendCatalogue($broken),'duplicate_catalogue_extension'),'duplicate input rejected');
    check($fail(fn()=>$manager->extendCatalogue($entries,true,$dry['expected_catalog'],str_repeat('0',64)),'catalogue_extension_changed'),'concurrent rating batch change blocks apply');
    check($fail(fn()=>$manager->extendCatalogue($entries,true,str_repeat('0',64),$dry['expected_batch']),'catalogue_extension_changed'),'concurrent catalogue change blocks apply');
    $first=(int)$state['rows'][0]['ID'];
    $db->query('UPDATE episode_list SET IMDB_SD=1 WHERE ID='.$first);
    check($fail(fn()=>$manager->extendCatalogue($entries),'invalid_existing_distribution'),'corrupt previous distribution blocks extension');
    $db->query('UPDATE episode_list SET IMDB_SD=0 WHERE ID='.$first);
    $db->begin_transaction();
    try {check($fail(fn()=>$manager->extendCatalogue($entries),'rating_import_nested_transaction'),'external transaction rejected');} finally {$db->rollback();}
    try {(new ExtensionFaultManager())->extendCatalogue($entries,true,$dry['expected_catalog'],$dry['expected_batch']);check(false,'write fault rolls back');}
    catch(RuntimeException $e){check($e->getMessage()==='extension-write-fault','write fault injected after actual update');}
    check($rows()===$base&&Infra\ConfigManager::getInstance()->getOptionDetails(Domain\EpisodeRatingManager::HEADER_KEY)===$header,'failed extension rolls back all new rows, old observations and active header');
    $pipes=[];$child=proc_open([PHP_BINARY,__FILE__,'worker',$dry['expected_catalog'],$dry['expected_batch'],$marker],[1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
    $until=microtime(true)+5;while(!is_file($marker)&&microtime(true)<$until)usleep(10000);
    if(!is_file($marker))throw new RuntimeException('extension worker timeout');
    $during=$manager->getCatalogueProjection();
    $result=json_decode(stream_get_contents($pipes[1]),true);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    check(proc_close($child)===0&&$errors===''&&$result['status']==='applied'&&$result['added']===12,'separate writer commits complete extension');
    check(count($during['rows'])===count($base)&&$during['ratings']['status']==='available','reader during write sees complete previous catalogue and ratings');
    $after=$rows();$projection=$manager->getCatalogueProjection(null,true);$public=array_column($projection['rows'],null,'id');
    check(count($after)===count($base)+12&&$projection['ratings']['status']==='available','all old and new rows expose ratings after one commit');
    $old=array_column($after,null,'ID');
    foreach ($base as $row) {
        $new=$old[$row['ID']];
        foreach (['TIMES_WATCHED','WANNA_WATCH','legacy_wanna_watch','TITLE','LENGTH','TWOPART_ID','IMDB_ID','IMDB_RATING','IMDB_VOTES','IMDB_HISTOGRAM','IMDB_SD','IMDB_RETRIEVED_AT','IMDB_SOURCE_ASOF'] as $key) if($row[$key]!==$new[$key])throw new RuntimeException('Old field changed: '.$key);
    }
    check(true,'existing counters, content, scores, distributions and original observation dates preserved exactly');
    $newRows=array_slice($after,count($base));$lengths=[];
    foreach($newRows as $row){$lengths[]=(int)$row['LENGTH'];$p=$public[$row['ID']];if($p['rating']['sd']===null||count($p['rating']['histogram'])!==10||$p['views']||$p['wishes'])throw new RuntimeException('New observation unavailable');}
    check($lengths===[3,3,3,3,1,1,1,2,2,2,2,2],'new durations use 24-minute slots with five-minute tolerance');
    $selected=Domain\PlaylistSelector::select(Domain\EpisodeCatalog::normalize($newRows),8,static fn()=>0.0);
    check(array_sum(array_column($selected,'length'))===8,'mixed films and specials fit an eight-slot playlist');
    $db->query('UPDATE episode_list SET TIMES_WATCHED=4,legacy_wanna_watch=1 WHERE ID='.(int)$newRows[0]['ID']);$after=$rows();
    $rerun=$manager->extendCatalogue($entries,true,$dry['expected_catalog'],$dry['expected_batch']);
    check($rerun['status']==='unchanged'&&$rerun['added']===0&&$rows()===$after,'exact replay with old tokens is read-only and creates no duplicates');
    $changed=$entries;$changed[0]['runtime_minutes']=100;
    check($fail(fn()=>$manager->extendCatalogue($changed),'catalogue_entry_conflict'),'existing title customization not overwritten');
    $changed=$entries;array_pop($changed);$changed[]=['title'=>'New standalone fixture','runtime_minutes'=>24,'observation'=>$entries[11]['observation']];
    check($fail(fn()=>$manager->extendCatalogue($changed),'catalogue_entry_conflict'),'same IMDb identity cannot be rebound to a new title');
    $changed[11]['observation']['imdb_id']='tt9999999999';$changed[11]['observation']['source_url']='https://www.imdb.com/title/tt9999999999/ratings/';
    check($fail(fn()=>$manager->extendCatalogue($changed),'partial_catalogue_extension'),'partially present extension fails closed');
} finally {extensionRestore($db,$state);if(is_file($marker))unlink($marker);}
it_done();
