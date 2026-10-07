<?php
require_once __DIR__.'/../autoload.php';
use LLM\LLMManager;
use LLM\EpisodeResolver;
class RatingResolverLlm extends LLMManager {
    public array $calls=[];
    public ?string $normalized=null;
    public array $responses=[];
    public ?string $live=null;
    public array $liveRequest=[];
    public function liveTextBounded(string $instruction,?string $mustContain,int $deadlineSec,int $timeoutSec=10,?string $trustedTask=null,?array $actionContext=null):?string{$this->liveRequest=func_get_args();return $this->live;}

    public function __construct(){}
    public function generateSearchQueryUtility(array $context,string $prompt,int $deadlineSec,int $timeoutSec=8):?string{$this->calls[]=['normalize',$context,$prompt,$deadlineSec,$timeoutSec];return $this->normalized;}
    public function generateSearchUtility(array $context,string $prompt,?int $deadlineSec=null):?string{$this->calls[]=['web',$context,$prompt,$deadlineSec];return array_shift($this->responses);}
    public function generateBoundedUtility(array $context,string $prompt,int $deadlineSec,int $timeoutSec=20):?string{throw new RuntimeException('Rating must not add a non-WEB verifier');}
}
$fail=0;function expect($ok,$label){global $fail;echo($ok?'PASS ':'FAIL ').$label."\n";if(!$ok)$fail++;}
$llm=new RatingResolverLlm();$resolver=new EpisodeResolver($llm);
$catalog=[['ID'=>831,'TITLE'=>'My Little Pony Friendship is Magic - Season 1 Episode 7 - Dragonshy']];
$url='https://www.imdb.com/title/tt1751105/episodes/?topRated=DESC';
$base=['version'=>1,'intent'=>'rating','direction'=>'best','selection'=>'extreme','metric'=>'mean_score','requested_source'=>null,'scope_constraints'=>[],'search_query'=>'highest rated My Little Pony episodes'];
$proof=['platform'=>'imdb','metric'=>'mean_score','direction'=>'best','selection'=>'extreme','scale'=>['min'=>1,'max'=>10],'source_asof'=>null,'universe'=>['series'=>'My Little Pony: Friendship Is Magic','constraints'=>[],'coverage'=>'source_ranked_boundary'],'comparison'=>['rows'=>[['episode_code'=>'S01E07','title'=>'Dragonshy','value'=>9.5,'rank'=>1,'source_url'=>$url]],'boundary'=>['position'=>'top','rank'=>1,'tied_count'=>1]],'evidence'=>[['source_url'=>$url,'excerpt'=>'Source ranks Dragonshy first at9.5.']]];
$envelope=static fn($p)=>json_encode(['content'=>json_encode($p),'sources'=>[['url'=>$url,'title'=>'IMDb ranking']]]);
$legacy=new LLM\RatingEpisodeSearch($llm);
$legacyScope=['intent'=>$base,'original_query'=>'эпизод с лучшим рейтингом','search_query'=>'highest rated episodes'];
$llm->responses=[$envelope($proof),$envelope($proof)];$before=time();
$out=$legacy->resolve($legacyScope,$catalog,time()+55,static fn()=>831);
expect($out['status']==='found'&&$out['candidates'][0]['rating']['metric']==='mean_score','explicit saved version1 intent preserves legacy WEB compatibility');
expect(array_column($llm->calls,0)===['web','web'],'legacy compatibility performs two independent discoveries without fresh normalizer');
$a=json_decode($llm->calls[0][1][1]['content'],true);$b=json_decode($llm->calls[1][1][1]['content'],true);
expect($a===$b&&!isset($b['candidates'])&&!isset($b['comparison']),'legacy independent second extraction receives no first winner hint');
expect(!isset($b['catalog'])&&str_contains($llm->calls[0][1][0]['content'],'highest rated'),'legacy lean payload excludes catalogue pollution');
$llm->normalized=json_encode($base);$llm->calls=[];
expect($resolver->resolve('самый плохой',$catalog,time()+55,false)['status']==='need_clarification'&&array_column($llm->calls,0)===['normalize'],'fresh version1 rating response cannot choose WEB or old polarization backend');
foreach([null,'lowest rated episode','{"intent":"rating"}','{"version":2,"intent":"unknown"}'] as $bad){$llm->normalized=$bad;$llm->calls=[];expect($resolver->resolve('самый плохой',$catalog,time()+55,false)['status']==='need_clarification'&&count($llm->calls)===1,'invalid structured normalization cannot silently become plot');}
$command=new LLM\PlaylistCommand($llm);
$failure=LLM\RatingSearchProof::failure($base,'incomplete_comparison',time());
$ratingFacts=['candidates'=>[],'action'=>'wish','rating_intent'=>$base,'rating_reason'=>'incomplete_comparison'];
$llm->live='По IMDb сравнение средних оценок пока не подтверждено. Процитируй моё сообщение: можно повторить поиск или сменить источник.';
$text=$command->continuationReplyText('clarifying',['code'=>'search_empty','facts'=>$ratingFacts,'_deadline'=>time()+20]);
expect($text===$llm->live&&str_contains($llm->liveRequest[0],'Источник оценок: IMDb')&&str_contains($llm->liveRequest[0],'средняя оценка'),'empty continuation retains source/metric facts in actual formatter');
expect(str_contains($llm->liveRequest[4],'не называй его запрос расплывчатым')&&str_contains($llm->liveRequest[4],'не требуй вспомнить сцену'),'clear rating empty task explains missing comparison instead of blaming plot description');
$found=LLM\RatingSearchProof::reconcile($base,json_decode($envelope($proof),true),json_decode($envelope($proof),true),$catalog,static fn($r,$c)=>831,time());
$facts=['candidates'=>$found['candidates'],'action'=>'wish','rating_intent'=>$base];
$outcome=['status'=>'rejected','code'=>'confirmation_required','facts'=>$facts];
expect(LLM\PlaylistCommand::replyIsValid('IMDb: средняя оценка 9.5, Dragonshy. Выбирай этот вариант.',$outcome,''),'rating proposal accepts natural source and actual metric value');
foreach (['Средняя оценка 9.5, Dragonshy.','IMDb: оценка 8.0, Dragonshy.','IMDb и Metacritic: оценка 9.5.','IMDb: самый спорный эпизод.'] as $bad) expect(!LLM\PlaylistCommand::replyIsValid($bad,$outcome,''),'rating rejects omitted source/false value/platform/metric: '.$bad);
$empty=['status'=>'rejected','code'=>'search_empty','facts'=>$ratingFacts];
expect(!LLM\PlaylistCommand::replyIsValid('IMDb: самый лучший эпизод — Dragonshy. Процитируй моё сообщение.',$empty,''),'inconclusive proof cannot fabricate ranking winner');
$llm->live=null;$fallback=$command->continuationReplyText('clarifying',['code'=>'search_empty','facts'=>$ratingFacts,'_deadline'=>time()+20]);
expect(str_contains($fallback,'IMDb')&&str_contains($fallback,'не подтверждено'),'live failure retains honest rating source comparison fallback');
expect(!LLM\PlaylistCommand::replyIsValid('По IMDb это самый худший вариант; рейтинг 9.5.',$outcome,''),'best proof cannot invert mean direction into worst claim');
expect(!LLM\PlaylistCommand::replyIsValid('IMDb: средняя оценка 9.5, место 8.',$outcome,''),'rank claim must match deterministic verified rank');
$polarFacts=$facts;$polarFacts['rating_intent']['metric']='polarization';$polarFacts['rating_intent']['direction']='polarized';$polarFacts['rating_intent']['selection']='qualifying';
foreach ($polarFacts['candidates'] as &$c) {$c['rating']['metric']='polarization';$c['rating']['direction']='polarized';$c['rating']['selection']='qualifying';$c['rating']['value']=.4;}unset($c);
$qualifying=['status'=>'rejected','code'=>'confirmation_required','facts'=>$polarFacts];
expect(!LLM\PlaylistCommand::replyIsValid('По IMDb это самый спорный эпизод.',$qualifying,''),'qualifying polarized proof cannot upgrade to most polarized');
expect(LLM\PlaylistCommand::replyIsValid('IMDb: у этого варианта массовые высокие и низкие оценки, он спорный. Выбери кнопкой.',$qualifying,''),'qualifying polarized reply remains natural without comparative superlative');
$hugeIntent=$base;$hugeIntent['version']=2;$hugeIntent['scope_constraints']=array_fill(0,8,str_repeat('界',600));$llm->normalized=json_encode($hugeIntent);$llm->calls=[];
$hugeResult=$resolver->resolve('самый плохой эпизод',$catalog,time()+55,false);
expect(count($llm->calls)===1&&$hugeResult['resolution_snapshot']['reason']==='oversized_proof'&&strlen(json_encode($hugeResult['resolution_snapshot'],JSON_UNESCAPED_UNICODE))<=8192,'oversized UTF8 normalization remains rating clarification with zero WEB and bounded snapshot');
$hugeIntent['metric']='invented_metric';$invalidFailure=LLM\RatingSearchProof::failure($hugeIntent,'invalid_intent',time());
expect(!isset($invalidFailure['resolution_snapshot']['intent'])&&strlen(json_encode($invalidFailure['resolution_snapshot']))<2048,'oversized malformed enum does not invent fallback metric or winner');
$overflow=(new ReflectionMethod(LLM\PlaylistCommand::class,'resolverOverflowResult'))->invoke(null,$hugeResult);
expect($overflow['status']==='empty'&&$overflow['options']===[]&&strlen(json_encode($overflow,JSON_UNESCAPED_UNICODE))<=2048,'maximum invalid-provider snapshot also yields bounded handler-owned overflow result');
$llm->normalized=substr(json_encode(array_replace($base,['version'=>2])),0,-1).',"ignored":1e400}';$llm->calls=[];
$nonfinite=$resolver->resolve('самый плохой',$catalog,time()+55,false);
expect(count($llm->calls)===1&&$nonfinite['resolution_snapshot']['reason']==='invalid_intent'&&strlen(json_encode($nonfinite['resolution_snapshot']))<2048,'nonfinite provider extension fails closed with bounded metadata and no WEB');
$llm->calls=[];$llm->live='По IMDb можно уточнить критерий; процитируй моё сообщение.';
$command->continuationReplyText('clarifying',['code'=>'choice_clarifying','_deadline'=>time()+20,'_action_context'=>['data'=>['resolution_snapshot'=>['intent'=>array_replace($base,['direction'=>'worst'])]]]]);
expect($llm->calls===[]&&str_contains($llm->liveRequest[0],'Источник оценок: IMDb')&&str_contains($llm->liveRequest[0],'средняя оценка'),'subsequent child refinement formats current rating metadata with zero WEB');
$knownWorst=$empty;$knownWorst['facts']['rating_intent']=array_replace($base,['direction'=>'worst']);
expect(!LLM\PlaylistCommand::replyIsValid('По IMDb недостаточно подтверждений. Если уточнишь, про какой сериал или хотя бы сезон речь, ответь с цитатой на моё сообщение.',$empty,''),'captured missing comparison cannot ask which already fixed series');
expect(!LLM\PlaylistCommand::replyIsValid('По средней оценке на IMDb данные не сошлись. Может, уточнишь, что имел в виду: самый низкий рейтинг или просто всеми ругаемый? Ответь мне цитатой.',$knownWorst,''),'captured worst mean cannot reinterpret established criterion as unknown');
expect(LLM\PlaylistCommand::replyIsValid('По IMDb сравнение средней оценки пока не подтверждено. Можем повторить поиск или по твоему желанию ограничить его сезоном. Процитируй моё сообщение.',$knownWorst,''),'known rating allows optional narrowing without claiming ambiguous intent');
$llm->live='По IMDb сравнение пока не подтверждено. Если уточнишь, про какой сериал речь, ответь с цитатой на моё сообщение.';
$corrected=$command->continuationReplyText('clarifying',['code'=>'search_empty','facts'=>$ratingFacts,'_deadline'=>time()+20]);
expect($corrected!==$llm->live&&str_contains($llm->liveRequest[0],'My Little Pony: Friendship is Magic')&&str_contains($llm->liveRequest[4],'не спрашивай, какой сериал'),'actual formatter supplies authoritative series and rejects captured redundant question');
class LocalRatingOwner extends Domain\EpisodeRatingManager {
 public array $calls=[];public array $answer=[];
 public function __construct(){}
 public function select(array $intent,array $scope,?int $now=null):array{$this->calls[]=[$intent,$scope];return $this->answer;}
}
$owner=new LocalRatingOwner();$localResolver=new EpisodeResolver($llm,$owner);
$localIntent=['version'=>2,'intent'=>'rating','direction'=>'polarized','selection'=>'extreme','metric'=>'standard_deviation','requested_source'=>null,'scope_constraints'=>[],'scope_filter'=>['kind'=>'catalogue','seasons'=>[],'codes'=>[]],'search_query'=>'most controversial My Little Pony episodes'];
$localCandidate=['episode_id'=>831,'title'=>'Dragonshy','source_url'=>'https://www.imdb.com/title/tt1234567/ratings/','rating'=>['version'=>2,'platform'=>'imdb','metric'=>'standard_deviation','direction'=>'polarized','selection'=>'extreme','value'=>3.5,'rank'=>1,'tie_count'=>1,'non_exhaustive_ties'=>false,'universe'=>['series'=>'My Little Pony: Friendship Is Magic','constraints'=>[]],'scope'=>['kind'=>'catalogue','coverage'=>'complete'],'votes'=>1200,'published_rating'=>7.5,'retrieved_at'=>'2026-10-07 00:00:00','source_asof'=>null]];
$owner->answer=['status'=>'found','candidates'=>[$localCandidate],'reason'=>null,'metadata'=>['population'=>224]];
$llm->normalized=json_encode($localIntent);$llm->calls=[];
$localOut=$localResolver->resolve('самую спорную серию',$catalog,time()+55,false);
expect($localOut['status']==='found'&&$localOut['resolution_snapshot']['version']===2&&$localOut['candidates'][0]['rating']['metric']==='standard_deviation','new controversial intent stores version2 SD rather than old polarization');
expect(array_column($llm->calls,0)===['normalize']&&$owner->calls[0][1]['kind']==='catalogue','new whole-pool local intent never makes rating WEB calls');
foreach([['direction'=>'worst','metric'=>'mean_score','query'=>'самую засранную серию'],['direction'=>'polarized','metric'=>'polarization','query'=>'одновременно любимую и ненавидимую'],['direction'=>'negative_reception','metric'=>'negative_share','query'=>'больше всего низких голосов']] as $case){
 $intent=array_replace($localIntent,['direction'=>$case['direction'],'metric'=>$case['metric']]);$llm->normalized=json_encode($intent);$llm->calls=[];
 $localResolver->resolve($case['query'],$catalog,time()+55,false);
 expect(count($llm->calls)===1&&end($owner->calls)[0]['metric']===$case['metric'],'typed local metric preserved: '.$case['metric']);
}
$seasonIntent=$localIntent;$seasonIntent['scope_filter']=['kind'=>'season','seasons'=>[1],'codes'=>[]];$llm->normalized=json_encode($seasonIntent);$localResolver->resolve('самую спорную в первом сезоне',$catalog,time()+55,false);
expect(end($owner->calls)[1]['seasons']===[1],'explicit season remains complete local scope');
$codeIntent=$localIntent;$codeIntent['scope_filter']=['kind'=>'explicit_codes','seasons'=>[],'codes'=>['S01E07']];$llm->normalized=json_encode($codeIntent);$localResolver->resolve('из S01E07',$catalog,time()+55,false);
expect(end($owner->calls)[1]['ids']===[831],'explicit code maps through actual catalogue not code-derived local ID');
foreach([['scope_filter'=>['kind'=>'season','seasons'=>['1'],'codes'=>[]]],['metric'=>'made_up'],['requested_source'=>'Rotten Tomatoes'],['scope_filter'=>['kind'=>'explicit_codes','codes'=>['S99E99'],'seasons'=>[]]]] as $change){
 $llm->normalized=json_encode(array_replace($localIntent,$change));$llm->calls=[];$before=count($owner->calls);
 $failedLocal=$localResolver->resolve('рейтинговый запрос',$catalog,time()+55,false);
 expect($failedLocal['status']==='need_clarification'&&count($owner->calls)===$before&&count($llm->calls)===1,'invalid/unsupported local scope does not downgrade or borrow WEB ranking');
}
$llm->normalized=json_encode($localIntent);$owner->answer=['status'=>'unavailable','reason'=>'no_eligible_rows','candidates'=>[],'metadata'=>[]];$llm->calls=[];
$missing=$localResolver->resolve('спорную серию',$catalog,time()+55,false);
expect($missing['resolution_snapshot']['intent']['metric']==='standard_deviation'&&$missing['resolution_snapshot']['reason']==='no_eligible_rows'&&count($llm->calls)===1,'local stale/missing failure retains known criterion without WEB fallback');
$subsetIntent=$localIntent;$subsetIntent['scope_constraints']=['Lyra appears'];
$adapter=new LLM\RatingEpisodeSearch($llm,$owner);$scope=['intent'=>$subsetIntent,'original_query'=>'самую спорную где Лира','search_query'=>'Lyra appears'];$membershipCalls=[];
$adapter->resolve($scope,$catalog,time()+55,static fn()=>831,function($s,$c,$d)use(&$membershipCalls){$membershipCalls[]=[$s,$d];return ['status'=>'found','candidates'=>[['episode_id'=>831]]];});
expect(end($owner->calls)[1]===['kind'=>'verified_subset','ids'=>[831],'coverage'=>'verified_subset']&&$membershipCalls[0][0]['original_query']===$scope['original_query'],'semantic members are ranked honestly as verified subset with original constraints');
$before=count($owner->calls);$adapter->resolve($scope,$catalog,time()+55,static fn()=>831,static fn()=>['status'=>'need_clarification','candidates'=>[]]);
expect(count($owner->calls)===$before,'unverified semantic membership cannot silently become global ranking');
$boundedIntent=$localIntent;$boundedIntent['scope_constraints']=array_fill(0,8,str_repeat('x',600));
$normalSized=['status'=>'need_clarification','candidates'=>[],'resolution_snapshot'=>['version'=>2,'status'=>'need_clarification','intent'=>$boundedIntent,'reason'=>'no_eligible_rows','candidates'=>[]]];
$compactOverflow=(new ReflectionMethod(LLM\PlaylistCommand::class,'resolverOverflowResult'))->invoke(null,$normalSized);
expect(strlen(json_encode($compactOverflow))<=2048&&$compactOverflow['handler_context_updates']['resolution_snapshot']['version']===2&&$compactOverflow['facts']['rating_intent']['metric']==='standard_deviation','valid large v2 intent fallback retains current criterion within compact bounds');
$oversizedInvalid=$localIntent;$oversizedInvalid['metric']='invented';$oversizedInvalid['scope_constraints']=array_fill(0,8,str_repeat('界',600));$llm->normalized=json_encode($oversizedInvalid);$llm->calls=[];
$invalidLocal=$localResolver->resolve('рейтинговый запрос',$catalog,time()+55,false);
expect(empty($invalidLocal['resolution_snapshot']['intent'])&&count($llm->calls)===1,'oversized malformed v2 intent cannot inject arbitrary formatter metric');
echo $fail?"FAILURES: $fail\n":"ALL PASS\n";exit($fail?1:0);
