<?php
require_once __DIR__.'/../autoload.php';
$failures=0;
$check=static function(bool $ok,string $label)use(&$failures){echo ($ok?'PASS ':'FAIL ').$label."\n";if(!$ok)$failures++;};
foreach ([1=>1,22=>1,24=>1,29=>1,30=>2,44=>2,48=>2,50=>2,53=>2,54=>3,72=>3,73=>3,77=>3,78=>4,99=>4] as $minutes=>$slots) $check(Domain\EpisodeCatalog::durationSlots($minutes)===$slots,'duration '.$minutes.' => '.$slots);
try {Domain\EpisodeCatalog::durationSlots(0);$check(false,'zero duration rejected');}catch(InvalidArgumentException $e){$check(true,'zero duration rejected');}
$entries=json_decode(file_get_contents(__DIR__.'/../resources/catalogue/equestria-girls.json'),true,64,JSON_THROW_ON_ERROR)['entries'];
$check(count($entries)===12 && count(array_unique(array_column(array_column($entries,'observation'),'imdb_id')))===12,'twelve unique IMDb identities');
$catalog=[];$mapping=[];$records=[];
foreach ($entries as $i=>$entry) {
    $id=$i+1;$imdb=$entry['observation']['imdb_id'];
    $catalog[]=['ID'=>$id,'TITLE'=>$entry['title'],'TWOPART_ID'=>null,'LENGTH'=>Domain\EpisodeCatalog::durationSlots($entry['runtime_minutes'])];
    $mapping[]=['episode_id'=>$id,'imdb_id'=>$imdb,'kind'=>'special','season'=>null,'episode'=>null,'identity_url'=>'https://www.imdb.com/title/'.$imdb.'/'];
    $records[]=['episode_id'=>$id]+$entry['observation'];
}
$fingerprint=Domain\EpisodeRatingSnapshot::catalogFingerprint($catalog);
$input=['schema_version'=>1,'source'=>'imdb','parent_series'=>'tt1751105','catalog_fingerprint'=>$fingerprint,'scope'=>['kind'=>'catalogue','ids'=>range(1,12),'coverage'=>'complete'],'records'=>$records];
$approved=['schema_version'=>1,'source'=>'imdb','parent_series'=>'tt1751105','catalog_fingerprint'=>$fingerprint,'records'=>$mapping];
$last=max(array_map(static fn($row)=>strtotime($row['retrieved_at']),$records));
$slice=Domain\EpisodeRatingSnapshot::validate($input,$catalog,$approved,$last+1);
$check($slice['coverage']['eligible_histogram']===12,'all source histograms validate with exact totals and dates');
$check(array_column($catalog,'LENGTH')===[3,3,3,3,1,1,1,2,2,2,2,2],'approved film and special durations');
$check($entries[7]['runtime_minutes']===50 && $entries[11]['runtime_minutes']===44,'full Forgotten Friendship and complete Holidays Unwrapped');
$check(count(array_filter($slice['records'],static fn($row)=>$row['sd']>0))===12,'every new title has calculated standard deviation');
echo $failures?"FAILURES: $failures\n":"ALL PASS\n";exit($failures?1:0);
