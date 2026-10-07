<?php
require_once __DIR__.'/../autoload.php';
use LLM\RatingSearchProof;
$fail=0;function expect($ok,$label){global $fail;echo ($ok?'PASS ':'FAIL ').$label."\n";if(!$ok)$fail++;}
$catalog=[['ID'=>413,'TITLE'=>'Dragonshy'],['ID'=>77,'TITLE'=>'Look Before You Sleep'],['ID'=>91,'TITLE'=>'Slice of Life'],['ID'=>94,'TITLE'=>'Winter Wrap Up']];
$map=static function($row,$catalog){foreach($catalog as $c)if($c['TITLE']===($row['title']??''))return $c['ID'];return null;};
$intent=['version'=>1,'intent'=>'rating','direction'=>'best','selection'=>'extreme','metric'=>'mean_score','requested_source'=>null,'scope_constraints'=>[],'search_query'=>'highest rated episodes'];
$url='https://www.imdb.com/title/tt1751105/episodes/?topRated=DESC';
$proof=['platform'=>'imdb','metric'=>'mean_score','direction'=>'best','selection'=>'extreme','scale'=>['min'=>1,'max'=>10],'source_asof'=>null,'universe'=>['series'=>'My Little Pony: Friendship Is Magic','constraints'=>[],'coverage'=>'complete_rows','population_count'=>2],'comparison'=>['rows'=>[['title'=>'Dragonshy','value'=>9.5,'source_url'=>$url],['title'=>'Look Before You Sleep','value'=>9.0,'source_url'=>$url]]],'evidence'=>[['source_url'=>$url,'excerpt'=>'Complete comparison: Dragonshy 9.5, Look Before You Sleep 9.0.']]];
$wrap=static fn($p)=>['content'=>json_encode($p),'sources'=>[['url'=>$url,'title'=>'IMDb episode ratings']]];
$run=static fn($a,$b=null,$i=null)=>RatingSearchProof::reconcile($i??$intent,$wrap($a),$wrap($b??$a),$catalog,$map,time());
$out=$run($proof);expect($out['status']==='found'&&array_column($out['candidates'],'episode_id')===[413],'complete comparison selects actual leader rather than merely high score');
expect(strlen(json_encode($out['resolution_snapshot'],JSON_UNESCAPED_UNICODE))<=8192,'compact inspectable persisted proof fits owner bound');

$worst=array_replace($intent,['direction'=>'worst']);$p=$proof;$p['direction']='worst';expect(array_column($run($p,null,$worst)['candidates'],'episode_id')===[77],'worst compares ascending same population');
$changed=$proof;$changed['comparison']['rows'][0]['value']=8.5;expect($run($proof,$changed)['status']==='need_clarification','independent rankings disagreement rejects rather than trusting first winner');
foreach (['population_count'=>3,'constraints'=>['Lyra appears'],'coverage'=>'distribution_only'] as $k=>$v) {$p=$proof;$p['universe'][$k]=$v;expect($run($p)['status']==='need_clarification','incomplete or wrong universe rejects '.$k);}
foreach (['platform'=>'rottentomatoes','scale'=>['min'=>0,'max'=>100],'source_asof'=>'2000-01-01'] as $k=>$v) {$p=$proof;$p[$k]=$v;expect($run($p)['status']==='need_clarification','invalid header rejects '.$k);}
foreach (['value'=>'9.5','rank'=>'1','title'=>'Invented Episode','source_url'=>'https://imdb.com.evil.example/rating'] as $k=>$v) {$p=$proof;$p['comparison']['rows'][0][$k]=$v;expect($run($p)['status']==='need_clarification','forged scalar/identity/citation rejects '.$k);}
$bad=$wrap($proof);$bad['sources'][0]['url']='https://imdb.com.evil.example/rating';expect(RatingSearchProof::reconcile($intent,$bad,$bad,$catalog,$map,time())['status']==='need_clarification','citation hostname spoof rejected even when annotation exists');
$other=array_replace($intent,['requested_source'=>'Rotten Tomatoes']);expect($run($proof,null,$other)['resolution_snapshot']['reason']==='unsupported_source','explicit unsupported source never silently becomes IMDb');
$p=$proof;$p['universe']['coverage']='source_ranked_boundary';unset($p['universe']['population_count']);$p['comparison']['rows']=[$p['comparison']['rows'][0]];$p['comparison']['rows'][0]['rank']=8;
expect($run($p)['status']==='need_clarification','high score or number8 without rank1 boundary cannot prove best');
$p['comparison']['boundary']=['position'=>'top','rank'=>1,'tied_count'=>1];$p['comparison']['rows'][0]['rank']=1;expect($run($p)['status']==='found','independently cited rank1 boundary is accepted');
$p['comparison']['rows'][0]['rank']=8;expect($run($p)['status']==='need_clarification','forged rank1 boundary cannot bless rank8 candidate');
$band=array_replace($intent,['selection'=>'leading_group']);$p['selection']='leading_group';unset($p['comparison']['boundary']);expect($run($p,null,$band)['status']==='found','explicit leading group accepts proven rank8 without calling it first');
$p=$proof;$p['universe']['population_count']=4;$p['comparison']['rows']=[];foreach($catalog as $c)$p['comparison']['rows'][]=['title'=>$c['TITLE'],'value'=>9.5,'source_url'=>$url];
$tied=$run($p);expect(count($tied['candidates'])===3&&$tied['candidates'][0]['rating']['tie_count']===4&&$tied['candidates'][0]['rating']['non_exhaustive_ties'],'ties greater than three remain explicitly non-exhaustive and equal');
$polar=array_replace($intent,['direction'=>'polarized','selection'=>'qualifying','metric'=>'polarization']);
$p=$proof;$p['direction']='polarized';$p['selection']='qualifying';$p['metric']='polarization';$p['universe']['coverage']='distribution_only';unset($p['universe']['population_count']);$p['comparison']['rows']=[$p['comparison']['rows'][0]];unset($p['comparison']['rows'][0]['value']);
$p['distribution']=[['title'=>'Dragonshy','source_url'=>$url,'total'=>100,'bins'=>[['min'=>1,'max'=>3,'count'=>20],['min'=>4,'max'=>7,'count'=>60],['min'=>8,'max'=>10,'count'=>20]]]];
$out=$run($p,null,$polar);expect($out['status']==='found'&&$out['candidates'][0]['rating']['value']===.4,'polarization value is computed from proven high and low tails');
$bad=$p;$bad['comparison']['rows'][0]['value']=1;expect($run($bad,null,$polar)['status']==='need_clarification','invented polarization value cannot override per-row histogram');
foreach ([99,101] as $n){$bad=$p;$bad['distribution'][0]['total']=$n;expect($run($bad,null,$polar)['status']==='need_clarification','sample threshold and changed histogram denominator fail '.$n);}
$bad=$p;$bad['distribution'][0]['bins'][1]=['min'=>3,'max'=>8,'count'=>60];expect($run($bad,null,$polar)['status']==='need_clarification','cross-tail bin cannot be guessed split');
$bad=$p;$bad['distribution'][0]['bins'][2]['count']=0;$bad['distribution'][0]['bins'][0]['count']=40;expect($run($bad,null,$polar)['status']==='need_clarification','low mean alone or one-sided reception is not polarization');
$most=array_replace($polar,['selection'=>'extreme']);$bad=$p;$bad['selection']='extreme';expect($run($bad,null,$most)['status']==='need_clarification','most polarized needs complete comparison rather than qualifying distribution');
$bad=$p;$bad['selection']='extreme';$bad['universe']['coverage']='complete_rows';$bad['universe']['population_count']=1;expect($run($bad,null,$most)['status']==='found','most polarized computes maximum from complete validated distribution population');
$bad=$proof;$bad['evidence'][0]['excerpt']=str_repeat('x',1001);expect($run($bad)['status']==='need_clarification','excerpt cap rejects rather than silently truncating comparison evidence');
$oversized=$wrap($proof);$oversized['content']=str_repeat(' ',131073);expect(RatingSearchProof::reconcile($intent,$oversized,$oversized,$catalog,$map,time())['status']==='need_clarification','transient cap enforced before parsing');
$bad=$proof;$bad['source_asof']='2026-99-01';expect($run($bad)['status']==='need_clarification','invalid source date cannot become retrieved date');
expect($run($proof)['candidates'][0]['rating']['source_asof']===null,'unknown source date stays unknown');
$fenced=$wrap($proof);$fenced['content']="```json\n".json_encode($proof)."\n```\nComparison explanation after the initial object.";
expect(RatingSearchProof::reconcile($intent,$fenced,$fenced,$catalog,$map,time())['status']==='found','leading fenced object accepts captured provider trailing explanation');
foreach (['Explanation first\n'.$fenced['content'],$fenced['content'].'\n```json {} ```','```json {broken} ```'] as $text) {
 $bad=$fenced;$bad['content']=$text;
 expect(RatingSearchProof::reconcile($intent,$bad,$bad,$catalog,$map,time())['status']==='need_clarification','protocol never hunts a later JSON object or repairs malformed content');
}
$extra=$wrap($proof);$extra['sources'][]=['url'=>'https://www.gridratings.com/episode','title'=>'Unreferenced search hit'];
expect(RatingSearchProof::reconcile($intent,$extra,$extra,$catalog,$map,time())['status']==='found','unreferenced foreign annotation does not change explicitly requested IMDb source');
$foreign=$proof;$foreign['comparison']['rows'][0]['source_url']='https://www.gridratings.com/episode';$extra['content']=json_encode($foreign);
expect(RatingSearchProof::reconcile($intent,$extra,$extra,$catalog,$map,time())['status']==='need_clarification','foreign cited proof remains forbidden even with its real annotation');
$emptyProof=$proof;$emptyProof['comparison']['rows']=[];$extra['content']=json_encode($emptyProof);
expect(RatingSearchProof::reconcile($intent,$extra,$extra,$catalog,$map,time())['resolution_snapshot']['reason']!=='unsupported_source','captured empty comparison with unused foreign hit reports lack of proof, not unsupported requested source');
$groupProof=$proof;$groupProof['selection']='leading_group';$groupProof['comparison']['rows'][0]['rank']=1;$groupProof['comparison']['rows'][1]['rank']=2;
$groupIntent=array_replace($intent,['selection'=>'leading_group']);$groupResult=$run($groupProof,null,$groupIntent);
expect(array_column(array_column($groupResult['candidates'],'rating'),'tie_count')===[1,1],'different leading-group values are not described as equally rated ties');
$ranked=$proof;$ranked['comparison']['rows'][0]['rank']=8;$ranked['comparison']['rows'][1]['rank']=1;
expect($run($ranked)['status']==='need_clarification','complete population rejects declared ranks that contradict numeric comparison');
$ranked=$proof;$ranked['comparison']['rows'][0]['rank']=1;$ranked['comparison']['rows'][1]['rank']=1;
expect($run($ranked)['status']==='need_clarification','complete population cannot claim unequal values as tied first');
$ranked['comparison']['rows'][1]['value']=9.5;
expect($run($ranked)['status']==='found'&&array_column(array_column($run($ranked)['candidates'],'rating'),'rank')===[1,1],'complete population computes competition ranks for genuine ties');
$negative=array_replace($intent,['direction'=>'negative_reception','metric'=>'negative_share']);$negProof=$proof;$negProof['direction']='negative_reception';$negProof['metric']='negative_share';
unset($negProof['comparison']['rows'][0]['value'],$negProof['comparison']['rows'][1]['value']);
$negProof['distribution']=[];
foreach (['Dragonshy'=>80,'Look Before You Sleep'=>20] as $title=>$low) $negProof['distribution'][]=['title'=>$title,'total'=>100,'source_url'=>$url,'bins'=>[['min'=>1,'max'=>3,'count'=>$low],['min'=>4,'max'=>7,'count'=>100-$low],['min'=>8,'max'=>10,'count'=>0]]];
$negResult=$run($negProof,null,$negative);
expect($negResult['status']==='found'&&$negResult['candidates'][0]['rating']['value']===.8,'negative share compares computed low votes, independent of mean score or positive tail');
unset($negProof['distribution'][1]);expect($run($negProof,null,$negative)['status']==='need_clarification','every negative-share comparator requires its own complete distribution');
$malformed=$proof;$malformed['universe']='not an object';expect($run($malformed)['status']==='need_clarification','malformed nested object fails closed without TypeError escaping');
$largeCatalog=[];$largeProof=$proof;$largeProof['comparison']['rows']=[];$largeProof['universe']['population_count']=120;
for($n=0;$n<120;$n++) {$largeCatalog[]=['ID'=>700+$n,'TITLE'=>'Episode '.$n];$largeProof['comparison']['rows'][]=['title'=>'Episode '.$n,'value'=>9.5,'source_url'=>$url];}
$largeResult=RatingSearchProof::reconcile($intent,$wrap($largeProof),$wrap($largeProof),$largeCatalog,$map,time());
expect($largeResult['resolution_snapshot']['reason']==='oversized_proof'&&$largeResult['candidates']===[],'large complete comparison becomes bounded clarification snapshot before persistence');
echo $fail?"FAILURES: $fail\n":"ALL PASS\n";exit($fail?1:0);
