<?php
require_once __DIR__.'/integration_helpers.php';
if ((it_config()['db']['host']??'')!=='db') it_skip('requires isolated db');
$probe=it_require_db();$probe->close();
$db=Infra\Database::getInstance()->getConnection();
use Domain\EpisodeRatingManager;
use Domain\EpisodeRatingSnapshot;
use Core\UserError;

class RatingFaultManager extends EpisodeRatingManager
{
    protected function writeRecord(array $row,array $slice): void
    {
        parent::writeRecord($row,$slice);
        throw new RuntimeException('injected_after_record');
    }
}
class RatingSlowManager extends EpisodeRatingManager
{
    protected function writeRecord(array $row,array $slice): void
    {
        parent::writeRecord($row,$slice);
        if (!file_exists($GLOBALS['rating_marker'])) {
            file_put_contents($GLOBALS['rating_marker'],'ready');
            usleep(300000);
        }
    }
}
class RatingPausedCoverageManager extends EpisodeRatingManager
{
    protected function metricCoverage(array $ids,array $header,string $metric,int $now): ?string
    {
        $result=parent::metricCoverage($ids,$header,$metric,$now);
        $marker=$GLOBALS['rating_reader_marker'];file_put_contents($marker,'ready');
        $until=microtime(true)+5;
        while(!is_file($marker.'_done')&&microtime(true)<$until)usleep(10000);
        if(!is_file($marker.'_done'))throw new RuntimeException('partial_writer_timeout');
        return $result;
    }
}
class RatingTransientCoverageManager extends EpisodeRatingManager
{
    public int $calls=0;
    protected function metricCoverage(array $ids,array $header,string $metric,int $now): ?string
    {
        if (++$this->calls===1) throw new mysqli_sql_exception('injected_read_timeout',1205);
        return parent::metricCoverage($ids,$header,$metric,$now);
    }
}
if (in_array($argv[1]??'', ['worker','partial-worker'],true)) {
    $input=json_decode(file_get_contents($argv[2]),true);
    $mapping=json_decode(file_get_contents($argv[3]),true);
    $GLOBALS['rating_marker']=$argv[5]??'';
    $partial=$argv[1]==='partial-worker';
    if($partial){$until=microtime(true)+5;while(!file_exists($GLOBALS['rating_marker'])&&microtime(true)<$until)usleep(10000);}
    $manager=$partial||empty($GLOBALS['rating_marker'])?new EpisodeRatingManager():new RatingSlowManager();
    try { echo json_encode($manager->apply($input,$mapping,$argv[4],(int)$argv[6])); }
    catch (UserError $e) { echo json_encode(['reason'=>$e->getMessage()]); }
    if($partial)file_put_contents($GLOBALS['rating_marker'].'_done','committed');
    exit;
}
function ratingFails(callable $fn,string $reason,string $name): void {
    try { $fn();check(false,$name); }
    catch(UserError $e) { check($e->getMessage()===$reason,$name); }
}
function ratingProcess(array $args): array {
    $pipes=[];$proc=proc_open(array_merge([PHP_BINARY,__FILE__],$args),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]);return [$proc,$pipes];
}
function ratingResult(array $process): array {
    [$proc,$pipes]=$process;$text=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    check(proc_close($proc)===0&&$err==='','actual worker exits clean');return json_decode($text,true)??[];
}
$manager=new EpisodeRatingManager();$config=Infra\ConfigManager::getInstance();$prior=$config->getOptionDetails(EpisodeRatingManager::HEADER_KEY);
$initialIsolation=$db->query('SELECT @@session.transaction_isolation isolation')->fetch_assoc()['isolation'];
$ids=[];$files=[];$now=time();$tag=bin2hex(random_bytes(5));
try {
    for($i=0;$i<6;$i++) {
        $title='My Little Pony Friendship is Magic - Season 99 Episode '.($i+1).' - MLP366 '.$tag;
        $s=$db->prepare('INSERT INTO episode_list(TITLE,LENGTH) VALUES(?,1)');$s->bind_param('s',$title);$s->execute();$ids[]=$db->insert_id;
    }
    $catalog=$db->query('SELECT ID,TITLE,TWOPART_ID,LENGTH FROM episode_list ORDER BY ID')->fetch_all(MYSQLI_ASSOC);
    $fp=EpisodeRatingSnapshot::catalogFingerprint($catalog);
    $mapping=['schema_version'=>1,'source'=>'imdb','parent_series'=>'tt1751105','catalog_fingerprint'=>$fp,'records'=>[]];
    $input=['schema_version'=>1,'source'=>'imdb','parent_series'=>'tt1751105','catalog_fingerprint'=>$fp,'scope'=>['kind'=>'explicit_ids','ids'=>$ids,'coverage'=>'complete'],'records'=>[]];
    $bins=[[0,0,0,0,0,0,0,0,0,100],[50,0,0,0,0,0,0,0,0,50],[40,0,0,0,0,0,0,0,0,60],[20,0,0,0,0,0,0,0,0,80],[49,0,0,0,0,0,0,0,0,50],[0,0,0,0,20,0,0,80,0,0]];
    foreach($ids as $i=>$id) {
        $imdb='tt'.str_pad((string)$id,7,'0',STR_PAD_LEFT);
        $mapping['records'][]=['episode_id'=>$id,'imdb_id'=>$imdb,'kind'=>'episode','season'=>99,'episode'=>$i+1,'identity_url'=>'https://www.imdb.com/title/'.$imdb.'/'];
        $input['records'][]=['episode_id'=>$id,'imdb_id'=>$imdb,'rating'=>$i<4?9.0:($i===4?4.0:3.0),'votes'=>array_sum($bins[$i]),'histogram'=>$bins[$i],
            'retrieved_at'=>gmdate('Y-m-d\TH:i:s\Z',$now),'source_asof'=>null,'source_url'=>'https://www.imdb.com/title/'.$imdb.'/ratings/','vote_scope'=>'all_countries','provenance'=>['channel'=>'ordinary_browser_dom']];
    }
    $before=$config->getOptionDetails(EpisodeRatingManager::HEADER_KEY);
    $dry=$manager->dryRun($input,$mapping,$now);
    check($before===$config->getOptionDetails(EpisodeRatingManager::HEADER_KEY)&&$db->query('SELECT IMDB_ID FROM episode_list WHERE ID='.$ids[0])->fetch_assoc()['IMDB_ID']===null,'dry-run leaves header and rows unchanged');
    $applied=$manager->apply($input,$mapping,$dry['expected_batch_hash'],$now);
    check($applied['status']==='applied'&&$applied['coverage']['eligible_histogram']===5,'atomic import reports known N eligibility');
    $stored=$config->getOptionDetails(EpisodeRatingManager::HEADER_KEY);
    check($manager->apply($input,$mapping,$applied['batch_hash'],$now+1)['status']==='unchanged'&&$stored===$config->getOptionDetails(EpisodeRatingManager::HEADER_KEY),'idempotence does not refresh header or observed dates');
    ratingFails(fn()=>$manager->apply($input,$mapping,null,$now),'rating_batch_changed','stale expected hash rejects');
    $scope=['kind'=>'explicit_ids','ids'=>$ids,'coverage'=>'complete'];
    $intent=['metric'=>'mean_score','direction'=>'best','selection'=>'extreme','source'=>'imdb'];
    $best=$manager->select($intent,$scope,$now);
    check(count($best['candidates'])===3&&$best['candidates'][0]['rating']['tie_count']===4&&$best['candidates'][0]['rating']['non_exhaustive_ties'],'ties are counted before display cap');
    check(array_column($best['candidates'],'episode_id')===array_slice($ids,0,3)&&$best['metadata']['population']===5,'all eligible pool ranked with canonical tie display and N99 exclusion');
    $worst=$manager->select(array_replace($intent,['direction'=>'worst']),$scope,$now);
    check($worst['candidates'][0]['episode_id']===$ids[5],'worst comes from end of whole declared pool');
    $sd=$manager->select(array_replace($intent,['metric'=>'standard_deviation','direction'=>'polarized']),$scope,$now);
    check($sd['candidates'][0]['episode_id']===$ids[1]&&$sd['candidates'][0]['rating']['value']===4.5,'population SD ranks independent of published rating');
    $love=$manager->select(array_replace($intent,['metric'=>'polarization','direction'=>'polarized']),$scope,$now);
    check($love['candidates'][0]['episode_id']===$ids[1]&&$love['candidates'][0]['rating']['value']===1.0,'lovehate distinct metric requires both tails');
    $negative=$manager->select(array_replace($intent,['metric'=>'negative_share','direction'=>'negative_reception']),$scope,$now);
    check($negative['candidates'][0]['episode_id']===$ids[1],'negative_reception direction performs local SQL');
    ratingFails(fn()=>$manager->select(array_replace($intent,['metric'=>'negative_share']),$scope,$now),'invalid_rating_direction','mismatched metric/direction rejects');
    check($manager->select(array_replace($intent,['source'=>'other']),$scope,$now)['reason']==='unsupported_source','no source substitution');
    ratingFails(fn()=>$manager->select($intent,['kind'=>'catalogue'],$now),'incomplete_active_rating_scope','partial active scope cannot claim catalogue winner');
    $qualified=$manager->select($intent,['kind'=>'verified_subset','ids'=>[$ids[0],$ids[1]],'coverage'=>'verified_subset','constraints'=>['Lyra appears']],$now);
    check($qualified['candidates'][0]['rating']['universe']['coverage']==='verified_subset','verified subset retains qualified scope facts');
    foreach(['IMDB_RETRIEVED_AT','IMDB_SOURCE_ASOF'] as $field) {
        $db->query("UPDATE episode_list SET $field='".gmdate('Y-m-d H:i:s',$now+301)."' WHERE ID=".$ids[0]);
        check($manager->select($intent,$scope,$now)['reason']==='incomplete_metric_scope','future '.$field.' cannot produce unrestricted winner');
        $s=$db->prepare("UPDATE episode_list SET $field=? WHERE ID=?");$v=$field==='IMDB_SOURCE_ASOF'?null:gmdate('Y-m-d H:i:s',$now);$s->bind_param('si',$v,$ids[0]);$s->execute();
    }
    $db->query('UPDATE episode_list SET IMDB_HISTOGRAM=NULL WHERE ID='.$ids[0]);
    check($manager->select(array_replace($intent,['metric'=>'standard_deviation','direction'=>'polarized']),$scope,$now)['reason']==='incomplete_metric_scope','missing histogram blocks unrestricted SD winner');
    $s=$db->prepare('UPDATE episode_list SET IMDB_HISTOGRAM=? WHERE ID=?');$json=json_encode($bins[0]);$s->bind_param('si',$json,$ids[0]);$s->execute();
    check($manager->select($intent,$scope,$now+45*86400+1)['reason']==='incomplete_metric_scope','stale observation is excluded without claiming full coverage');
    $db->begin_transaction();ratingFails(fn()=>$manager->apply($input,$mapping,$applied['batch_hash'],$now),'rating_import_nested_transaction','manual outer transaction rejected');$db->rollback();
    Infra\Transaction::run($db,function()use($manager,$input,$mapping,$applied,$now){ratingFails(fn()=>$manager->apply($input,$mapping,$applied['batch_hash'],$now),'rating_import_nested_transaction','owner savepoint transaction rejected');});
    $changed=$input;$changed['records'][0]['rating']=8.0;
    try {(new RatingFaultManager())->apply($changed,$mapping,$applied['batch_hash'],$now);check(false,'injected rollback');}catch(RuntimeException $e){check($e->getMessage()==='injected_after_record','injected failure observed');}
    check($config->getOptionDetails(EpisodeRatingManager::HEADER_KEY)===$stored&&$manager->select($intent,$scope,$now)['candidates'][0]['rating']['tie_count']===4,'mid-write exception restores rows and header');
    $bad=$input;array_pop($bad['records']);ratingFails(fn()=>$manager->apply($bad,$mapping,$applied['batch_hash'],$now),'incomplete_snapshot_scope','partial file rejects without modifying previous slice');
    $base=sys_get_temp_dir().'/mlp366_'.$tag;$file=$base.'.json';$mapFile=$base.'_map.json';$marker=$base.'_marker';$files=[$file,$mapFile,$marker];
    file_put_contents($file,json_encode($changed));file_put_contents($mapFile,json_encode($mapping));chmod($file,0600);chmod($mapFile,0600);
    $process=ratingProcess(['worker',$file,$mapFile,$applied['batch_hash'],$marker,(string)$now]);
    $until=microtime(true)+3;while(!file_exists($marker)&&microtime(true)<$until)usleep(10000);
    check(file_exists($marker),'writer reached uncommitted row boundary');
    $oldRead=$manager->select($intent,$scope,$now);check($oldRead['candidates'][0]['rating']['tie_count']===4,'reader sees complete previous rows/header while writer uncommitted');
    $committed=ratingResult($process);check($committed['status']==='applied'&&$manager->select($intent,$scope,$now)['candidates'][0]['rating']['tie_count']===3,'reader sees complete new slice after commit');
    $next=$changed;$next['records'][1]['rating']=7.0;file_put_contents($file,json_encode($next));
    $workers=[ratingProcess(['worker',$file,$mapFile,$committed['batch_hash'],'',(string)$now]),ratingProcess(['worker',$file,$mapFile,$committed['batch_hash'],'',(string)$now])];
    $results=array_map('ratingResult',$workers);check(count(array_filter($results,fn($r)=>($r['status']??'')==='applied'))===1&&count(array_filter($results,fn($r)=>($r['reason']??'')==='rating_batch_changed'))===1,'real concurrent CAS permits one committed batch');
    $currentHash=json_decode($config->getOptionDetails(EpisodeRatingManager::HEADER_KEY)['value'],true)['batch_hash'];
    $stale=$input;foreach($stale['records'] as &$record)$record['retrieved_at']=gmdate('Y-m-d\TH:i:s\Z',$now-45*86400-1);unset($record);
    $staleResult=$manager->apply($stale,$mapping,$currentHash,$now);
    check($staleResult['coverage']['eligible_rating']===0&&$manager->select($intent,$scope,$now)['reason']==='incomplete_metric_scope','archived old facts remain stored with original dates but never fresh eligible');
    $restored=$manager->apply($next,$mapping,$staleResult['batch_hash'],$now);
    check($restored['batch_hash']===$currentHash,'validated archive restoration restores same data hash');
    $oldMeta=$config->getOptionDetails(EpisodeRatingManager::HEADER_KEY);
    $remap=$next;$remapMapping=$mapping;$other='tt999999999';
    $remap['records'][0]['imdb_id']=$other;$remap['records'][0]['source_url']='https://www.imdb.com/title/'.$other.'/ratings/';
    $remapMapping['records'][0]['imdb_id']=$other;$remapMapping['records'][0]['identity_url']='https://www.imdb.com/title/'.$other.'/';
    ratingFails(fn()=>$manager->apply($remap,$remapMapping,$restored['batch_hash'],$now),'rating_identity_remap','previous external identity cannot silently remap');
    check($oldMeta===$config->getOptionDetails(EpisodeRatingManager::HEADER_KEY),'rejected identity remap preserves whole previous header');
    $unchanged=$db->query('SELECT WANNA_WATCH,TIMES_WATCHED,LENGTH FROM episode_list WHERE ID='.$ids[0])->fetch_assoc();
    check((int)$unchanged['WANNA_WATCH']===0&&(int)$unchanged['TIMES_WATCHED']===0&&(int)$unchanged['LENGTH']===1,'rating writes never alter generator inputs');
    $cli=proc_open([PHP_BINARY,__DIR__.'/../scripts/import_episode_ratings.php','--file',$file,'--mapping-file',$mapFile,'--dry-run'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$report=json_decode(stream_get_contents($pipes[1]),true);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($cli)===0&&$err===''&&$report['status']==='validated'&&$report['changed']===0,'actual CLI default validation reads bounded local files without changes');
    $db->query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $transient=new RatingTransientCoverageManager();
    try { $transient->select($intent,$scope,$now);check(false,'read timeout propagates without a second transaction'); }
    catch(mysqli_sql_exception $error){check($error->getCode()===1205&&$transient->calls===1,'read timeout propagates without a second transaction');}
    check($db->query('SELECT @@session.transaction_isolation isolation')->fetch_assoc()['isolation']==='READ-COMMITTED'&&$manager->select($intent,$scope,$now)['status']==='found','failed consistent read rolls back and fresh selection establishes its own readview');
    $readerMarker=$base.'_reader';$files[]=$readerMarker;$files[]=$readerMarker.'_done';
    $GLOBALS['rating_reader_marker']=$readerMarker;
    $partial=$next;$partial['scope']['ids']=array_slice($ids,0,3);$partial['records']=array_slice($partial['records'],0,3);$partial['records'][2]['rating']=1.0;
    file_put_contents($file,json_encode($partial));
    $writer=ratingProcess(['partial-worker',$file,$mapFile,$restored['batch_hash'],$readerMarker,(string)$now]);
    $consistent=(new RatingPausedCoverageManager())->select($intent,$scope,$now);
    $partialCommit=ratingResult($writer);
    check($partialCommit['status']==='applied'&&$consistent['metadata']['batch_hash']===$restored['batch_hash']&&$consistent['candidates'][0]['rating']['tie_count']===2&&$consistent['metadata']['population']===5,'READ-COMMITTED session keeps one old readview when partial batch commits between coverage and ranking');
    check($db->query('SELECT @@session.transaction_isolation isolation')->fetch_assoc()['isolation']==='READ-COMMITTED','selection does not change session transaction isolation');
    ratingFails(fn()=>$manager->select($intent,$scope,$now),'incomplete_active_rating_scope','new reader cannot mix rows outside current partial header');
    $manager->apply($next,$mapping,$partialCommit['batch_hash'],$now);
    $db->begin_transaction();ratingFails(fn()=>$manager->select($intent,$scope,$now),'rating_selection_nested_transaction','selection refuses caller readview instead of mutating it');$db->rollback();
} finally {
    $db->query('SET SESSION TRANSACTION ISOLATION LEVEL '.str_replace('-',' ',$initialIsolation));
    foreach($files as $file)if(is_file($file))unlink($file);
    foreach($ids as $id)$db->query('DELETE FROM episode_list WHERE ID='.(int)$id);
    if ($prior===null) {$s=$db->prepare('DELETE FROM site_options WHERE key_name=?');$key=EpisodeRatingManager::HEADER_KEY;$s->bind_param('s',$key);$s->execute();}
    else {$s=$db->prepare('UPDATE site_options SET value=?,updated_at=? WHERE key_name=?');$key=EpisodeRatingManager::HEADER_KEY;$s->bind_param('sss',$prior['value'],$prior['updated_at'],$key);$s->execute();}
    $config->flushCache();
}
it_done();
