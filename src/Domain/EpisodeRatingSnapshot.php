<?php
namespace Domain;

use Core\UserError;

/** Pure validation and reproducible calculations for one operator-supplied IMDb slice. */
final class EpisodeRatingSnapshot
{
    public const MAX_VOTES = 2147483647;
    public const MIN_VOTES = 100;
    public const FRESH_SECONDS = 45 * 86400;

    public static function statistics(array $bins): array
    {
        self::require(array_is_list($bins) && count($bins) === 10, 'invalid_histogram_shape');
        $n = 0; $weighted = 0;
        foreach ($bins as $index => $count) {
            self::require(is_int($count) && $count >= 0 && $count <= self::MAX_VOTES, 'invalid_histogram_count');
            $n += $count; $weighted += ($index + 1) * $count;
        }
        self::require($n > 0 && $n <= self::MAX_VOTES, 'invalid_histogram_total');
        $mean = $weighted / $n; $variance = 0.0;
        foreach ($bins as $index => $count) $variance += $count * (($index + 1) - $mean) ** 2;
        return ['n' => $n, 'mean' => $mean, 'sd' => round(sqrt($variance / $n), 9),
            'low_count' => array_sum(array_slice($bins, 0, 3)), 'high_count' => array_sum(array_slice($bins, 7, 3))];
    }

    public static function catalogFingerprint(array $catalog): string
    {
        $rows = [];
        foreach ($catalog as $row) {
            $rows[] = ['id' => (int)$row['ID'], 'title' => (string)$row['TITLE'],
                'twopart_id' => ($row['TWOPART_ID'] ?? null) === null ? null : (int)$row['TWOPART_ID'], 'length' => (int)($row['LENGTH'] ?? 1)];
        }
        usort($rows, static fn($a, $b) => $a['id'] <=> $b['id']);
        return hash('sha256', self::encode($rows));
    }

    public static function validate(array $input, array $catalog, array $approvedMapping, int $now): array
    {
        self::require(strlen(self::encode($input)) <= 1048576, 'snapshot_too_large');
        self::keys($input, ['schema_version','source','parent_series','catalog_fingerprint','scope','records']);
        self::require(($input['schema_version'] ?? null) === 1 && ($input['source'] ?? null) === 'imdb' && ($input['parent_series'] ?? null) === 'tt1751105', 'invalid_snapshot_source');
        $fingerprint = self::catalogFingerprint($catalog);
        self::require(($input['catalog_fingerprint'] ?? null) === $fingerprint, 'catalogue_changed');
        $mapping = self::mapping($approvedMapping, $catalog, $fingerprint);
        self::require(is_array($input['scope'] ?? null), 'invalid_import_scope');
        $scope = self::scope($input['scope'], $catalog);
        self::require(is_array($input['records'] ?? null) && array_is_list($input['records']) && count($input['records']) === count($scope['ids']), 'incomplete_snapshot_scope');
        $records = []; $seen = [];
        foreach ($input['records'] as $record) {
            self::require(is_array($record), 'invalid_snapshot_record');
            $normalized = self::record($record, $mapping, $now);
            $id = $normalized['episode_id'];
            self::require(in_array($id, $scope['ids'], true) && !isset($seen[$id]), 'duplicate_or_outside_scope');
            $seen[$id] = true; $records[] = $normalized;
        }
        usort($records, static fn($a, $b) => $a['episode_id'] <=> $b['episode_id']);
        $result = ['schema_version'=>1,'source'=>'imdb','parent_series'=>'tt1751105','scope'=>$scope,
            'catalog_fingerprint'=>$fingerprint,'mapping_hash'=>hash('sha256',self::encode($mapping)),'records'=>$records];
        $result['batch_hash'] = hash('sha256', self::encode($result));
        $dates = array_column($records, 'retrieved_at'); sort($dates);
        $result['observation_min'] = $dates[0]; $result['observation_max'] = end($dates);
        $result['coverage'] = self::coverage($records, $now);
        return $result;
    }

    private static function keys(array $value, array $allowed): void
    {
        self::require(!array_diff(array_keys($value), $allowed), 'unknown_snapshot_field');
    }

    private static function scope(array $scope, array $catalog): array
    {
        self::keys($scope, ['kind','ids','coverage']);
        self::require(in_array($scope['kind'] ?? '', ['catalogue','explicit_ids'], true) && ($scope['coverage'] ?? '') === 'complete', 'invalid_import_scope');
        $ids = $scope['ids'] ?? null;
        self::require(is_array($ids) && array_is_list($ids) && count($ids) > 0 && count($ids) <= 256, 'invalid_import_ids');
        $catalogIds = array_map(static fn($r)=>(int)$r['ID'], $catalog); sort($catalogIds);
        foreach ($ids as $id) self::require(is_int($id) && in_array($id, $catalogIds, true), 'unknown_scope_id');
        sort($ids); self::require(count(array_unique($ids)) === count($ids), 'duplicate_scope_id');
        if ($scope['kind'] === 'catalogue') self::require($ids === $catalogIds, 'incomplete_catalogue_scope');
        return ['kind'=>$scope['kind'],'ids'=>$ids,'coverage'=>'complete'];
    }

    private static function mapping(array $input, array $catalog, string $fingerprint): array
    {
        self::require(strlen(self::encode($input)) <= 1048576, 'mapping_too_large');
        self::keys($input, ['schema_version','source','parent_series','catalog_fingerprint','records']);
        self::require(($input['schema_version'] ?? null)===1 && ($input['source'] ?? null)==='imdb' && ($input['parent_series'] ?? null)==='tt1751105' && ($input['catalog_fingerprint'] ?? null)===$fingerprint, 'invalid_approved_mapping');
        self::require(is_array($input['records'] ?? null) && array_is_list($input['records']) && count($input['records'])<=256, 'invalid_mapping_records');
        $index=[]; foreach ($catalog as $row) $index[(int)$row['ID']]=$row;
        $result=[]; $seen=[];
        foreach ($input['records'] as $row) {
            self::require(is_array($row), 'invalid_mapping_record');
            self::keys($row,['episode_id','imdb_id','kind','season','episode','identity_url']);
            $id=$row['episode_id'] ?? null; $imdb=$row['imdb_id'] ?? '';
            self::require(is_string($imdb), 'invalid_imdb_id');
            self::require(is_int($id) && isset($index[$id]) && !isset($result[$id]) && !isset($seen[$imdb]), 'duplicate_or_unknown_mapping');
            self::imdbId($imdb); self::url($row['identity_url'] ?? '', $imdb, false);
            self::mappingIdentity($row,$index[$id]);
            $result[$id]=['episode_id'=>$id,'imdb_id'=>$imdb,'kind'=>$row['kind'],'season'=>$row['season'],'episode'=>$row['episode'],'identity_url'=>$row['identity_url']]; $seen[$imdb]=true;
        }
        ksort($result); return $result;
    }

    private static function mappingIdentity(array $row, array $catalog): void
    {
        $meta=EpisodeCatalog::metadata($catalog['TITLE']);
        if (($row['kind'] ?? null)==='episode') {
            self::require($meta!==null && is_int($row['season'] ?? null) && is_int($row['episode'] ?? null) && $meta['season']===$row['season'] && $meta['episode']===$row['episode'], 'wrong_episode_coordinates');
            return;
        }
        self::require(($row['kind'] ?? null)==='special' && $meta===null && array_key_exists('season',$row) && array_key_exists('episode',$row) && $row['season']===null && $row['episode']===null, 'invalid_special_mapping');
    }

    private static function record(array $row, array $mapping, int $now): array
    {
        self::keys($row,['episode_id','imdb_id','rating','votes','histogram','retrieved_at','source_asof','source_url','vote_scope','provenance']);
        $id=$row['episode_id'] ?? null;
        self::require(is_int($id) && isset($mapping[$id]) && ($row['imdb_id'] ?? null)===$mapping[$id]['imdb_id'], 'unapproved_record_identity');
        $rating=$row['rating'] ?? null; $votes=$row['votes'] ?? null;
        self::require((is_float($rating)||is_int($rating)) && is_finite((float)$rating) && $rating>=1 && $rating<=10 && abs($rating*10-round($rating*10))<1e-8, 'invalid_published_rating');
        self::require(is_int($votes) && $votes>0 && $votes<=self::MAX_VOTES && ($row['vote_scope'] ?? null)==='all_countries', 'invalid_votes_scope');
        self::require(array_key_exists('histogram',$row), 'missing_histogram_field');
        self::require($row['histogram']===null || is_array($row['histogram']), 'invalid_histogram_shape');
        self::require(is_array($row['provenance'] ?? null), 'invalid_provenance');
        $stats=$row['histogram']===null ? null : self::statistics($row['histogram']);
        if ($stats!==null) self::require($stats['n']===$votes, 'histogram_votes_mismatch');
        self::url($row['source_url'] ?? '',$row['imdb_id'],true);
        $provenance=self::provenance($row['provenance'] ?? []);
        self::require(array_key_exists('source_asof',$row), 'missing_source_asof');
        return ['episode_id'=>$id,'imdb_id'=>$row['imdb_id'],'rating'=>number_format((float)$rating,1,'.',''),'votes'=>$votes,
            'histogram'=>$row['histogram'],'sd'=>$stats['sd'] ?? null,'retrieved_at'=>self::timestamp($row['retrieved_at'] ?? '',$now),
            'source_asof'=>$row['source_asof']===null ? null : self::timestamp($row['source_asof'],$now),'source_url'=>$row['source_url'],
            'vote_scope'=>'all_countries','provenance'=>$provenance];
    }

    private static function provenance(array $value): array
    {
        self::keys($value,['channel','capture_hash']);
        self::require(($value['channel'] ?? null)==='ordinary_browser_dom', 'invalid_acquisition_channel');
        if (isset($value['capture_hash'])) self::require(is_string($value['capture_hash']) && preg_match('/^[a-f0-9]{64}$/D',$value['capture_hash'])===1, 'invalid_capture_hash');
        self::require(strlen(self::encode($value))<=2048, 'provenance_too_large');
        return $value;
    }

    private static function imdbId(string $id): void
    {
        self::require(preg_match('/^tt[0-9]{7,14}$/D',$id)===1, 'invalid_imdb_id');
    }

    private static function url(mixed $url, string $id, bool $ratings): void
    {
        self::imdbId($id);
        $expected='https://www.imdb.com/title/'.$id.($ratings?'/ratings/':'/');
        self::require($url===$expected, 'wrong_identity_url');
    }

    private static function timestamp(mixed $value, int $now): string
    {
        self::require(is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|\+00:00)$/D',$value)===1, 'invalid_observation_time');
        try {$date=new \DateTimeImmutable($value);} catch (\Exception $error) {throw new UserError('invalid_observation_time');}
        self::require($date->format('Y-m-d\TH:i:s')===substr($value,0,19) && $date->getTimestamp()<=$now+300, 'invalid_or_future_observation');
        return $date->format('Y-m-d H:i:s');
    }

    public static function coverage(array $records, int $now): array
    {
        $mean=0; $hist=0;
        foreach ($records as $row) {
            if (!self::eligible($row,$now)) continue;
            $mean++; if ($row['histogram']!==null) $hist++;
        }
        return ['records'=>count($records),'eligible_rating'=>$mean,'eligible_histogram'=>$hist];
    }

    public static function eligible(array $row, int $now): bool
    {
        $retrieved=strtotime($row['retrieved_at'].' UTC');
        $asof=$row['source_asof']===null ? null : strtotime($row['source_asof'].' UTC');
        return $row['votes']>=self::MIN_VOTES && $retrieved>=$now-self::FRESH_SECONDS && ($asof===null || $asof>=$now-self::FRESH_SECONDS);
    }

    private static function encode(array $value): string
    {
        try { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
        catch (\JsonException $error) { throw new UserError('invalid_snapshot_encoding'); }
    }

    private static function require(bool $valid, string $reason): void
    {
        if (!$valid) throw new UserError($reason);
    }
}
