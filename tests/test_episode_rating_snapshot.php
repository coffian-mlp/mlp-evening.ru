<?php
require_once __DIR__ . '/../autoload.php';
use Domain\EpisodeRatingSnapshot;
$failed=0;
function verifyRating($condition,$name){global $failed;echo ($condition?'PASS ':'FAIL ').$name."\n";if(!$condition)$failed++;}
$perfect=[18,1,3,5,12,10,23,47,119,1025];
$stats=EpisodeRatingSnapshot::statistics($perfect);
verifyRating($stats['n']===1263&&abs($stats['sd']-1.416760573)<1e-9,'observed Perfect Pear population standard deviation');
verifyRating(abs($stats['mean']-9.5)>.01,'published weighted IMDb rating remains separate from histogram mean');
$nonCompete=EpisodeRatingSnapshot::statistics([152,79,46,44,65,50,28,27,4,60]);
verifyRating($nonCompete['n']===555&&abs($nonCompete['sd']-2.979907828)<1e-9,'independent observed Non-Compete population SD matches decimal calculation');
$constant=EpisodeRatingSnapshot::statistics([0,0,0,0,100,0,0,0,0,0]);
verifyRating($constant['sd']===0.0&&$constant['low_count']===0&&$constant['high_count']===0,'genuine constant distribution has SD zero, not unknown');
$a=EpisodeRatingSnapshot::statistics([50,0,0,0,0,0,0,0,0,50]);
$b=EpisodeRatingSnapshot::statistics([0,0,0,0,50,50,0,0,0,0]);
verifyRating($a['mean']===$b['mean']&&$a['sd']>$b['sd'],'same mean does not imply same controversy');
foreach([[],array_fill(0,9,1),array_fill(0,10,0),[-1,0,0,0,0,0,0,0,0,1],[1.5,0,0,0,0,0,0,0,0,1],[2147483647,1,0,0,0,0,0,0,0,0]] as $bad){
 try{EpisodeRatingSnapshot::statistics($bad);verifyRating(false,'malformed/overflow histogram rejected');}catch(Core\UserError $error){verifyRating(true,'malformed/overflow histogram rejected');}
}
$catalog=[['ID'=>912,'TITLE'=>'A','TWOPART_ID'=>null,'LENGTH'=>1],['ID'=>16,'TITLE'=>'B','TWOPART_ID'=>912,'LENGTH'=>2]];
verifyRating(EpisodeRatingSnapshot::catalogFingerprint($catalog)===EpisodeRatingSnapshot::catalogFingerprint(array_reverse($catalog)),'catalog fingerprint ignores read ordering');
$changed=$catalog;$changed[0]['TITLE']='Edited';verifyRating(EpisodeRatingSnapshot::catalogFingerprint($catalog)!==EpisodeRatingSnapshot::catalogFingerprint($changed),'catalog fingerprint detects changed identity');
$catalog=[['ID'=>912,'TITLE'=>'My Little Pony Friendship is Magic - Season 1 Episode 1 - A','TWOPART_ID'=>null,'LENGTH'=>1]];
$fp=EpisodeRatingSnapshot::catalogFingerprint($catalog);$now=strtotime('2026-10-07T12:00:00Z');
$mapping=['schema_version'=>1,'source'=>'imdb','parent_series'=>'tt1751105','catalog_fingerprint'=>$fp,'records'=>[['episode_id'=>912,'imdb_id'=>'tt1234567','kind'=>'episode','season'=>1,'episode'=>1,'identity_url'=>'https://www.imdb.com/title/tt1234567/']]];
$input=['schema_version'=>1,'source'=>'imdb','parent_series'=>'tt1751105','catalog_fingerprint'=>$fp,'scope'=>['kind'=>'catalogue','ids'=>[912],'coverage'=>'complete'],'records'=>[['episode_id'=>912,'imdb_id'=>'tt1234567','rating'=>9.5,'votes'=>1263,'histogram'=>$perfect,'retrieved_at'=>'2026-10-07T10:00:00Z','source_asof'=>null,'source_url'=>'https://www.imdb.com/title/tt1234567/ratings/','vote_scope'=>'all_countries','provenance'=>['channel'=>'ordinary_browser_dom']]]];
$valid=EpisodeRatingSnapshot::validate($input,$catalog,$mapping,$now);
verifyRating($valid['records'][0]['rating']==='9.5'&&$valid['coverage']['eligible_histogram']===1,'strict observed slice stores published score and full distribution separately');
$equivalent=$input;$equivalent['records'][0]['retrieved_at']='2026-10-07T10:00:00.000000+00:00';
verifyRating(EpisodeRatingSnapshot::validate($equivalent,$catalog,$mapping,$now)['batch_hash']===$valid['batch_hash'],'equivalent UTC observation has identical canonical batch');
foreach(['histogram','votes','source_url','retrieved_at','scope','provenance','rating','source_asof'] as $field){
 $bad=$input;
 $values=['histogram'=>'bad','votes'=>1264,'source_url'=>'https://evil.test/','retrieved_at'=>'2026-10-07T12:06:00Z','scope'=>'bad','provenance'=>'bad','rating'=>9.55,'source_asof'=>'2026-02-30T00:00:00Z'];
 if($field==='scope')$bad[$field]=$values[$field];else$bad['records'][0][$field]=$values[$field];
 try{EpisodeRatingSnapshot::validate($bad,$catalog,$mapping,$now);verifyRating(false,'reject invalid '.$field);}catch(Core\UserError $error){verifyRating(true,'reject invalid '.$field);}
}
$stale=$input;$stale['records'][0]['source_asof']='2026-01-01T00:00:00Z';
verifyRating(EpisodeRatingSnapshot::validate($stale,$catalog,$mapping,$now)['coverage']['eligible_rating']===0,'known stale publication cannot be refreshed by observation date');
$unknown=$input;$unknown['records'][0]['histogram']=null;
verifyRating(EpisodeRatingSnapshot::validate($unknown,$catalog,$mapping,$now)['records'][0]['sd']===null,'absent histogram remains unknown');
$rejectSlice=static function($data,$map,$label)use($catalog,$now){try{EpisodeRatingSnapshot::validate($data,$catalog,$map,$now);verifyRating(false,$label);}catch(Core\UserError $error){verifyRating(true,$label);}};
foreach([['extra'=>1],['schema_version'=>2],['parent_series'=>'tt9999999'],['catalog_fingerprint'=>str_repeat('0',64)],['records'=>[]],['records'=>[$input['records'][0],$input['records'][0]]],['scope'=>['kind'=>'explicit_ids','ids'=>[912,912],'coverage'=>'complete']],['scope'=>['kind'=>'catalogue','ids'=>['912'],'coverage'=>'complete']],['scope'=>['kind'=>'catalogue','ids'=>[999],'coverage'=>'complete']]] as $delta)$rejectSlice(array_replace($input,$delta),$mapping,'bad envelope/scope preserves strict identity and completeness');
foreach([['episode_id'=>999],['imdb_id'=>'tt9999999'],['votes'=>0],['vote_scope'=>'us'],['source_asof'=>true],['provenance'=>['channel'=>'scraper']],['provenance'=>['channel'=>'ordinary_browser_dom','capture_hash'=>'fake']],['rating'=>'9.5'],['retrieved_at'=>'2026-10-07T10:00:00+03:00']] as $delta){$bad=$input;$bad['records'][0]=array_replace($bad['records'][0],$delta);$rejectSlice($bad,$mapping,'record cannot coerce IDs, votes, source or observation');}
foreach([['schema_version'=>2],['records'=>[array_replace($mapping['records'][0],['imdb_id'=>['bad']])]],['records'=>[array_replace($mapping['records'][0],['season'=>2])]],['records'=>[array_replace($mapping['records'][0],['kind'=>'special','season'=>null,'episode'=>null])]],['records'=>[$mapping['records'][0],$mapping['records'][0]]]] as $delta)$rejectSlice($input,array_replace($mapping,$delta),'approved mapping cannot remap canonical identity');
$low=$input;$low['records'][0]['votes']=99;$low['records'][0]['histogram']=[0,0,0,0,99,0,0,0,0,0];
verifyRating(EpisodeRatingSnapshot::validate($low,$catalog,$mapping,$now)['coverage']['eligible_rating']===0,'N99 may be stored but never qualifies');
$low['records'][0]['votes']=100;$low['records'][0]['histogram'][4]=100;
verifyRating(EpisodeRatingSnapshot::validate($low,$catalog,$mapping,$now)['coverage']['eligible_histogram']===1,'N100 is eligible with genuine SD0');
$boundary=$input;$boundary['records'][0]['retrieved_at']=gmdate('Y-m-d\TH:i:s\Z',$now-EpisodeRatingSnapshot::FRESH_SECONDS);
verifyRating(EpisodeRatingSnapshot::validate($boundary,$catalog,$mapping,$now)['coverage']['eligible_rating']===1,'45-day observed boundary remains eligible');
$boundary['records'][0]['retrieved_at']=gmdate('Y-m-d\TH:i:s\Z',$now-EpisodeRatingSnapshot::FRESH_SECONDS-1);
verifyRating(EpisodeRatingSnapshot::validate($boundary,$catalog,$mapping,$now)['coverage']['eligible_rating']===0,'45-days plus one second is stale');
$specialCatalog=[['ID'=>77,'TITLE'=>'My Little Pony: The Movie (2017)','TWOPART_ID'=>null,'LENGTH'=>2]];
$specialFp=EpisodeRatingSnapshot::catalogFingerprint($specialCatalog);$specialMap=$mapping;$specialMap['catalog_fingerprint']=$specialFp;$specialMap['records'][0]=array_replace($specialMap['records'][0],['episode_id'=>77,'kind'=>'special','season'=>null,'episode'=>null]);
$special=$input;$special['catalog_fingerprint']=$specialFp;$special['scope']['ids']=[77];$special['records'][0]['episode_id']=77;
verifyRating(EpisodeRatingSnapshot::validate($special,$specialCatalog,$specialMap,$now)['coverage']['eligible_histogram']===1,'approved special identity is independent of ordinary season code');
echo $failed?"FAILURES: $failed\n":"ALL PASS\n";exit($failed?1:0);
