<?php
namespace Domain;

use Core\UserError;
use Infra\ConfigManager;
use Infra\Database;
use Infra\Transaction;

/** Owner of the atomic operator snapshot and local ranking reads. */
class EpisodeRatingManager
{
    public const HEADER_KEY = 'episode_ratings_snapshot';
    private \mysqli $db;
    private ConfigManager $config;

    public function __construct()
    {
        $this->db=Database::getInstance()->getConnection();
        $this->config=ConfigManager::getInstance();
    }

    private function sql(string $query, array $args=[]): \mysqli_stmt
    {
        $stmt=$this->db->prepare($query);
        if ($args) $stmt->bind_param(str_repeat('s',count($args)),...$args);
        $stmt->execute(); return $stmt;
    }

    private function catalog(bool $lock=false): array
    {
        return $this->sql('SELECT ID,TITLE,TWOPART_ID,LENGTH FROM episode_list ORDER BY ID'.($lock?' FOR UPDATE':''))->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function dryRun(array $input, array $approvedMapping, ?int $now=null): array
    {
        $slice=EpisodeRatingSnapshot::validate($input,$this->catalog(),$approvedMapping,$now??time());
        $header=$this->header();
        $report=$this->summary($slice,'validated',($header['batch_hash']??null)===$slice['batch_hash']?0:count($slice['records']));
        $report['expected_batch_hash']=$header['batch_hash']??null;
        return $report;
    }

    private function header(): ?array
    {
        $value=$this->config->getOptionDetails(self::HEADER_KEY)['value']??null;
        if ($value===null) return null;
        $header=json_decode($value,true);
        if (!is_array($header)||($header['schema_version']??null)!==1) throw new UserError('invalid_rating_header');
        return $header;
    }

    public function apply(array $input, array $approvedMapping, ?string $expectedBatchHash, ?int $now=null): array
    {
        $this->requireOwnedTransaction();
        $now??=time();
        $slice=EpisodeRatingSnapshot::validate($input,$this->catalog(),$approvedMapping,$now);
        if ((int)$this->sql("SELECT GET_LOCK('episode_ratings_import',5) acquired")->get_result()->fetch_assoc()['acquired']!==1) throw new UserError('rating_import_busy');
        try {
            return Transaction::run($this->db,function()use($slice,$expectedBatchHash,$now){
                $header=$this->header();
                if (($header['batch_hash']??null)!==$expectedBatchHash) throw new UserError('rating_batch_changed');
                if (EpisodeRatingSnapshot::catalogFingerprint($this->catalog(true))!==$slice['catalog_fingerprint']) throw new UserError('catalogue_changed');
                if (($header['batch_hash']??null)===$slice['batch_hash']) return $this->summary($slice,'unchanged',0);
                foreach ($slice['records'] as $row) $this->writeRecord($row,$slice);
                $this->writeHeader($slice,$now);
                return $this->summary($slice,'applied',count($slice['records']));
            });
        } finally {
            $this->sql("SELECT RELEASE_LOCK('episode_ratings_import')");
            $this->config->flushCache();
        }
    }

    private function requireOwnedTransaction(string $reason='rating_import_nested_transaction'): void
    {
        if (Transaction::depth($this->db)>0) throw new UserError($reason);
        $this->db->query('SAVEPOINT mlp366_import_probe');
        try { $this->db->query('RELEASE SAVEPOINT mlp366_import_probe'); }
        catch (\mysqli_sql_exception $error) {
            if ($error->getCode()===1305) return;
            throw $error;
        }
        throw new UserError($reason);
    }

    protected function writeRecord(array $row,array $slice): void
    {
        $old=$this->sql('SELECT IMDB_ID FROM episode_list WHERE ID=?',[$row['episode_id']])->get_result()->fetch_assoc();
        if ($old['IMDB_ID']!==null && $old['IMDB_ID']!==$row['imdb_id']) throw new UserError('rating_identity_remap');
        $provenance=$row['provenance']+['batch_hash'=>$slice['batch_hash'],'mapping_hash'=>$slice['mapping_hash'],'source_url'=>$row['source_url'],'vote_scope'=>$row['vote_scope']];
        $hist=$row['histogram']===null?null:json_encode($row['histogram'],JSON_THROW_ON_ERROR);
        $this->sql('UPDATE episode_list SET IMDB_ID=?,IMDB_RATING=?,IMDB_VOTES=?,IMDB_HISTOGRAM=?,IMDB_SD=?,IMDB_RETRIEVED_AT=?,IMDB_SOURCE_ASOF=?,IMDB_PROVENANCE=? WHERE ID=?',
            [$row['imdb_id'],$row['rating'],$row['votes'],$hist,$row['sd'],$row['retrieved_at'],$row['source_asof'],json_encode($provenance,JSON_THROW_ON_ERROR),$row['episode_id']]);
    }

    private function writeHeader(array $slice,int $now): void
    {
        $header=array_intersect_key($slice,array_flip(['schema_version','source','batch_hash','catalog_fingerprint','scope','coverage','observation_min','observation_max']));
        $header['activated_at']=gmdate('Y-m-d H:i:s',$now);
        $json=json_encode($header,JSON_THROW_ON_ERROR);
        if(strlen($json)>8192)throw new UserError('rating_header_too_large');
        if(!$this->config->setOption(self::HEADER_KEY,$json))throw new \RuntimeException('Rating header save failed');
    }

    private function summary(array $slice,string $status,int $changed): array
    {
        return ['status'=>$status,'batch_hash'=>$slice['batch_hash'],'catalog_fingerprint'=>$slice['catalog_fingerprint'],'coverage'=>$slice['coverage'],'changed'=>$changed];
    }

    public function select(array $intent,array $scope,?int $now=null): array
    {
        $now??=time(); $metric=$intent['metric']??'';
        $expressions=['mean_score'=>'IMDB_RATING','standard_deviation'=>'IMDB_SD',
            'negative_share'=>'CAST((JSON_EXTRACT(IMDB_HISTOGRAM,"$[0]")+JSON_EXTRACT(IMDB_HISTOGRAM,"$[1]")+JSON_EXTRACT(IMDB_HISTOGRAM,"$[2]")) AS DECIMAL(65,30))/IMDB_VOTES',
            'polarization'=>'2*LEAST(CAST((JSON_EXTRACT(IMDB_HISTOGRAM,"$[0]")+JSON_EXTRACT(IMDB_HISTOGRAM,"$[1]")+JSON_EXTRACT(IMDB_HISTOGRAM,"$[2]")) AS DECIMAL(65,30))/IMDB_VOTES,CAST((JSON_EXTRACT(IMDB_HISTOGRAM,"$[7]")+JSON_EXTRACT(IMDB_HISTOGRAM,"$[8]")+JSON_EXTRACT(IMDB_HISTOGRAM,"$[9]")) AS DECIMAL(65,30))/IMDB_VOTES)'];
        if (!isset($expressions[$metric]) || !in_array($intent['selection']??'', ['extreme','leading_group','qualifying'],true)) throw new UserError('invalid_rating_intent');
        $directions=['mean_score'=>['best','worst'],'standard_deviation'=>['polarized'],'negative_share'=>['negative_reception'],'polarization'=>['polarized']];
        if (!in_array($intent['direction']??'', $directions[$metric],true)) throw new UserError('invalid_rating_direction');
        $source=$intent['source']??$intent['requested_source']??'imdb';
        if (!is_string($source) || strtolower($source)!=='imdb') return $this->unavailable('unsupported_source');
        return $this->consistentRead(function()use($scope,$intent,$metric,$expressions,$now){
            $header=$this->header();if(!$header)return $this->unavailable('missing_snapshot');
            $catalog=$this->catalog();
            if(EpisodeRatingSnapshot::catalogFingerprint($catalog)!==($header['catalog_fingerprint']??''))return $this->unavailable('catalogue_changed');
            $ids=$this->scopeIds($scope,$catalog,$header);
            if(!$ids)return $this->unavailable('empty_scope');
            $failure=$this->metricCoverage($ids,$header,$metric,$now);
            if ($failure!==null) return $this->unavailable($failure);
            $rows=$this->eligibleRows($ids,$header,$metric,$expressions[$metric],$now,$intent['direction']==='worst');
            return $this->ranked($rows,$intent,$scope,$header);
        });
    }

    private function consistentRead(callable $read): array
    {
        $this->requireOwnedTransaction('rating_selection_nested_transaction');
        $this->db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->db->begin_transaction();
        try {
            $result=$read();
            $this->db->commit();
            return $result;
        } catch (\Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    private function scopeIds(array $scope,array $catalog,array $header): array
    {
        $kind=$scope['kind']??'';
        if(!in_array($kind,['catalogue','season','explicit_ids','verified_subset'],true))throw new UserError('invalid_selection_scope');
        $coverage=$kind==='verified_subset'?'verified_subset':'complete';
        if (isset($scope['coverage']) && $scope['coverage']!==$coverage) throw new UserError('invalid_scope_coverage');
        $all=array_map(static fn($r)=>(int)$r['ID'],$catalog);
        if($kind==='catalogue')$ids=$all;
        elseif($kind==='season'){
            $seasons=$scope['seasons']??[];
            if(!is_array($seasons)||!$seasons||array_filter($seasons,static fn($s)=>!is_int($s)||$s<1||$s>99))throw new UserError('invalid_season_scope');
            $ids=[];foreach($catalog as $row){$m=EpisodeCatalog::metadata($row['TITLE']);if($m&&in_array($m['season'],$seasons,true))$ids[]=(int)$row['ID'];}
        }else{
            $ids=$scope['ids']??[];
            if(!is_array($ids)||!array_is_list($ids)||count($ids)>256||array_filter($ids,static fn($id)=>!is_int($id)||!in_array($id,$all,true)))throw new UserError('invalid_selection_ids');
        }
        if(array_diff($ids,$header['scope']['ids']??[]))throw new UserError('incomplete_active_rating_scope');
        return array_values(array_unique($ids));
    }

    protected function metricCoverage(array $ids,array $header,string $metric,int $now): ?string
    {
        $rows=$this->sql('SELECT ID,IMDB_ID,IMDB_RATING,IMDB_VOTES,IMDB_HISTOGRAM,IMDB_SD,IMDB_RETRIEVED_AT,IMDB_SOURCE_ASOF,IMDB_PROVENANCE FROM episode_list WHERE ID IN ('.implode(',',array_fill(0,count($ids),'?')).')',$ids)->get_result()->fetch_all(MYSQLI_ASSOC);
        if (count($rows)!==count($ids)) return 'incomplete_metric_scope';
        foreach ($rows as $row) {
            $p=json_decode($row['IMDB_PROVENANCE']??'',true);
            if (($p['batch_hash']??null)!==$header['batch_hash'] || $row['IMDB_VOTES']===null) return 'incomplete_metric_scope';
            if ((int)$row['IMDB_VOTES']<EpisodeRatingSnapshot::MIN_VOTES) continue;
            if (!$this->readyMetric($row,$metric,$now)) return 'incomplete_metric_scope';
        }
        return null;
    }

    private function readyMetric(array $row,string $metric,int $now): bool
    {
        if ($row['IMDB_ID']===null || $row['IMDB_RATING']===null || $row['IMDB_RETRIEVED_AT']===null) return false;
        $dates=[$row['IMDB_RETRIEVED_AT']];
        if ($row['IMDB_SOURCE_ASOF']!==null) $dates[]=$row['IMDB_SOURCE_ASOF'];
        foreach ($dates as $value) {
            $time=strtotime($value.' UTC');
            if ($time===false || $time<$now-EpisodeRatingSnapshot::FRESH_SECONDS || $time>$now+300) return false;
        }
        return $metric==='mean_score' || ($row['IMDB_HISTOGRAM']!==null && $row['IMDB_SD']!==null);
    }

    private function eligibleRows(array $ids,array $header,string $metric,string $expression,int $now,bool $ascending): array
    {
        $cutoff=gmdate('Y-m-d H:i:s',$now-EpisodeRatingSnapshot::FRESH_SECONDS);
        $query='SELECT *,'.$expression.' AS metric_value FROM episode_list WHERE ID IN ('.implode(',',array_fill(0,count($ids),'?')).') AND IMDB_VOTES>=100 AND IMDB_RETRIEVED_AT>=? AND IMDB_RETRIEVED_AT<=? AND (IMDB_SOURCE_ASOF IS NULL OR (IMDB_SOURCE_ASOF>=? AND IMDB_SOURCE_ASOF<=?)) AND JSON_UNQUOTE(JSON_EXTRACT(IMDB_PROVENANCE,"$.batch_hash"))=?';
        $query.= $metric==='mean_score'?' AND IMDB_RATING IS NOT NULL':' AND IMDB_HISTOGRAM IS NOT NULL AND IMDB_SD IS NOT NULL';
        $query.=' ORDER BY metric_value '.($ascending?'ASC':'DESC').',ID';
        $rows=$this->sql($query,array_merge($ids,[$cutoff,gmdate('Y-m-d H:i:s',$now+300),$cutoff,gmdate('Y-m-d H:i:s',$now+300),$header['batch_hash']]))->get_result()->fetch_all(MYSQLI_ASSOC);
        if($metric==='polarization')$rows=array_values(array_filter($rows,static function($r){$s=EpisodeRatingSnapshot::statistics(json_decode($r['IMDB_HISTOGRAM'],true));return $s['low_count']>=20&&$s['high_count']>=20&&$s['low_count']*5>=$s['n']&&$s['high_count']*5>=$s['n'];}));
        return $rows;
    }

    private function ranked(array $rows,array $intent,array $scope,array $header): array
    {
        if(!$rows)return $this->unavailable('no_eligible_rows');
        $rank=0;$previous=null;$eligible=[];
        $ascending=$intent['direction']==='worst';
        usort($rows,function($a,$b)use($intent,$ascending){
            $c=$this->compareMetric($a,$b,$intent['metric']);
            return ($ascending?$c:-$c) ?: $this->canonicalOrder($a,$b);
        });
        foreach($rows as $index=>$row){
            $value=$row['metric_value'];
            if($previous===null||$this->compareMetric($row,$previous,$intent['metric'])!==0)$rank=$index+1;
            $previous=$row;
            if(($intent['selection']==='extreme'&&$rank>1)||($intent['selection']==='leading_group'&&$rank>10))continue;
            if($intent['selection']==='qualifying'&&$intent['metric']==='standard_deviation'&&($rank>10||(float)$value<=0))continue;
            $row['_rank']=$rank;
            $row['_tie_count']=count(array_filter($rows,fn($other)=>$this->compareMetric($row,$other,$intent['metric'])===0));
            $eligible[]=$row;
        }
        $candidates=[];$display=array_slice($eligible,0,3);
        foreach($display as $row){
            $shown=count(array_filter($display,fn($other)=>$this->compareMetric($row,$other,$intent['metric'])===0));
            $row['_non_exhaustive']=$shown<$row['_tie_count'];
            $candidates[]=$this->candidate($row,$intent,$scope,$header,count($rows));
        }
        return ['status'=>$candidates?'found':'unavailable','reason'=>$candidates?null:'no_eligible_rows','candidates'=>$candidates,
            'metadata'=>['source'=>'imdb','batch_hash'=>$header['batch_hash'],'scope'=>$scope,'population'=>count($rows),'matching'=>count($eligible),'truncated'=>count($eligible)>3,'coverage'=>$header['coverage']]];
    }

    private function canonicalOrder(array $a,array $b): int
    {
        $x=EpisodeCatalog::metadata($a['TITLE']);$y=EpisodeCatalog::metadata($b['TITLE']);
        return [$x['season']??PHP_INT_MAX,$x['episode']??PHP_INT_MAX,(int)$a['ID']] <=> [$y['season']??PHP_INT_MAX,$y['episode']??PHP_INT_MAX,(int)$b['ID']];
    }

    private function compareMetric(array $a,array $b,string $metric): int
    {
        if(in_array($metric,['mean_score','standard_deviation'],true))return (float)$a['metric_value']<=>(float)$b['metric_value'];
        $x=EpisodeRatingSnapshot::statistics(json_decode($a['IMDB_HISTOGRAM'],true));$y=EpisodeRatingSnapshot::statistics(json_decode($b['IMDB_HISTOGRAM'],true));
        $xn=$metric==='negative_share'?$x['low_count']:min($x['low_count'],$x['high_count']);
        $yn=$metric==='negative_share'?$y['low_count']:min($y['low_count'],$y['high_count']);
        return $xn*$y['n']<=>$yn*$x['n'];
    }

    private function candidate(array $row,array $intent,array $scope,array $header,int $population): array
    {
        $meta=EpisodeCatalog::metadata($row['TITLE']);$p=json_decode($row['IMDB_PROVENANCE'],true);
        return ['episode_id'=>(int)$row['ID'],'title'=>$meta['name']??$row['TITLE'],'season'=>$meta['season']??null,'episode'=>$meta['episode']??null,
            'episode_code'=>$meta?sprintf('S%02dE%02d',$meta['season'],$meta['episode']):null,'source_url'=>$p['source_url'],
            'rating'=>['version'=>2,'platform'=>'imdb','metric'=>$intent['metric'],'direction'=>$intent['direction'],'selection'=>$intent['selection'],
                'value'=>(float)$row['metric_value'],'votes'=>(int)$row['IMDB_VOTES'],'rank'=>$row['_rank'],'population'=>$population,
                'published_rating'=>(float)$row['IMDB_RATING'],'standard_deviation'=>$row['IMDB_SD']===null?null:(float)$row['IMDB_SD'],
                'tie_count'=>$row['_tie_count'],'non_exhaustive_ties'=>$row['_non_exhaustive'],
                'universe'=>['series'=>'My Little Pony: Friendship is Magic','constraints'=>$scope['constraints']??[],'coverage'=>$scope['coverage']??($scope['kind']==='verified_subset'?'verified_subset':'complete')],
                'retrieved_at'=>$row['IMDB_RETRIEVED_AT'],'source_asof'=>$row['IMDB_SOURCE_ASOF'],'scope'=>$scope,'batch_hash'=>$header['batch_hash']],
            'evidence'=>'Local validated IMDb observation; metric '.$intent['metric']];
    }

    private function unavailable(string $reason): array
    {
        return ['status'=>'unavailable','reason'=>$reason,'candidates'=>[],'metadata'=>[]];
    }
}
