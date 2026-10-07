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
$llm->normalized=json_encode($base);$llm->responses=[$envelope($proof),$envelope($proof)];$before=time();$out=$resolver->resolve('эпизод с лучшим рейтингом',$catalog,time()+55,false);
expect($out['status']==='found'&&$out['candidates'][0]['episode_id']===831,'rating maps canonical code/title without hardcoded winner');
expect(array_column($llm->calls,0)===['normalize','web','web'],'rating makes precisely normalization and two fresh WEB calls');
$a=json_decode($llm->calls[1][1][1]['content'],true);$b=json_decode($llm->calls[2][1][1]['content'],true);
expect($a===$b&&!isset($b['candidates'])&&!isset($b['evidence'])&&!isset($b['comparison']),'second payload independently discovers leader with no first extraction hint');
expect(!isset($b['catalog'])&&str_contains($llm->calls[1][1][0]['content'],'highest rated')&&$llm->calls[1][1][0]['content']===$llm->calls[1][1][2]['content'],'lean WEB query appears first and last without catalogue pollution');
expect(str_contains($llm->calls[1][1][2]['content'],'/episodes/?topRated=DESC')&&str_contains($llm->calls[1][2],'not curated')===false&&str_contains($llm->calls[1][2],'curated personal'),'official query hint does not authorize curated lists as rating proof');
expect($b['original_query']==='эпизод с лучшим рейтингом'&&$b['source']==='IMDb','default source and original intent authoritative in both WEB calls');
expect($llm->calls[0][3]<=$before+8&&$llm->calls[1][3]<=$before+15&&$llm->calls[2][3]<=($before+55)-15,'absolute per-stage limits and formatter reserve stay inside overall55');
foreach (['самый плохой эпизод','самый засранный эпизод','ту серию которую оценили хуже всех'] as $query) {
 $intent=array_replace($base,['direction'=>'worst','search_query'=>'lowest rated episodes']);$p=$proof;$p['direction']='worst';$p['comparison']['boundary']['position']='bottom';
 $llm->normalized=json_encode($intent);$llm->responses=[$envelope($p),$envelope($p)];$llm->calls=[];
 expect($resolver->resolve($query,$catalog,time()+55,false)['status']==='found'&&count($llm->calls)===3,'semantic worst mean direction accepts free negative evaluation: '.$query);
 expect(str_contains($llm->calls[0][2],'самый засранный')&&str_contains($llm->calls[0][2],'Incidental evil/bad plot'),'normalizer contract separates rating slang from incidental plot adjective');
}
$llm->normalized=json_encode(array_replace($base,['requested_source'=>'Rotten Tomatoes']));$llm->calls=[];$out=$resolver->resolve('лучшую по Rotten Tomatoes',$catalog,time()+55,false);expect($out['resolution_snapshot']['reason']==='unsupported_source'&&count($llm->calls)===1,'explicit unsupported source does not request IMDb or lose its intent');
$llm->normalized=json_encode(array_replace($base,['direction'=>'worst','search_query'=>'lowest rated Lyra episodes','scope_constraints'=>['Lyra appears']]));$p=$proof;$p['direction']='worst';$p['universe']['constraints']=['Lyra appears'];$p['comparison']['boundary']['position']='bottom';$llm->responses=[$envelope($p),$envelope($p)];$llm->calls=[];
$out=$resolver->resolve("лучший эпизод где Лира\nУточнение: скорее самый плохой",$catalog,time()+55,false);$b=json_decode($llm->calls[2][1][1]['content'],true);
expect($out['status']==='found'&&$b['intent']['direction']==='worst'&&$b['intent']['scope_constraints']===['Lyra appears'],'latest quoted criterion replaces best while character constraint survives');
foreach ([null,'lowest rated episode','{"intent":"rating"}','{"version":1,"intent":"unknown"}'] as $bad) {$llm->normalized=$bad;$llm->calls=[];expect($resolver->resolve('самый плохой',$catalog,time()+55,false)['status']==='need_clarification'&&count($llm->calls)===1,'invalid structured normalization cannot silently become plot');}
$llm->normalized=json_encode($base);$llm->responses=[null];$llm->calls=[];expect($resolver->resolve('лучший',$catalog,time()+55,false)['status']==='unavailable'&&count($llm->calls)===2,'first unavailable source stops without new retry');
$llm->calls=[];$llm->responses=[];expect($resolver->resolve('лучший',$catalog,time()+19,false)['status']==='unavailable'&&count($llm->calls)===1,'insufficient reserved WEB budget makes no external search');
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
$hugeIntent=$base;$hugeIntent['scope_constraints']=array_fill(0,8,str_repeat('界',600));$llm->normalized=json_encode($hugeIntent);$llm->calls=[];
$hugeResult=$resolver->resolve('самый плохой эпизод',$catalog,time()+55,false);
expect(count($llm->calls)===1&&$hugeResult['resolution_snapshot']['reason']==='oversized_proof'&&strlen(json_encode($hugeResult['resolution_snapshot'],JSON_UNESCAPED_UNICODE))<=8192,'oversized UTF8 normalization remains rating clarification with zero WEB and bounded snapshot');
$hugeIntent['metric']='invented_metric';$invalidFailure=LLM\RatingSearchProof::failure($hugeIntent,'invalid_intent',time());
expect(!isset($invalidFailure['resolution_snapshot']['intent'])&&strlen(json_encode($invalidFailure['resolution_snapshot']))<2048,'oversized malformed enum does not invent fallback metric or winner');
$overflow=(new ReflectionMethod(LLM\PlaylistCommand::class,'resolverOverflowResult'))->invoke(null,$hugeResult);
expect($overflow['status']==='empty'&&$overflow['options']===[]&&strlen(json_encode($overflow,JSON_UNESCAPED_UNICODE))<=2048,'maximum invalid-provider snapshot also yields bounded handler-owned overflow result');
$llm->normalized=substr(json_encode($base),0,-1).',"ignored":1e400}';$llm->calls=[];
$nonfinite=$resolver->resolve('самый плохой',$catalog,time()+55,false);
expect(count($llm->calls)===1&&$nonfinite['resolution_snapshot']['reason']==='invalid_intent'&&strlen(json_encode($nonfinite['resolution_snapshot']))<2048,'nonfinite provider extension fails closed with bounded metadata and no WEB');
$llm->calls=[];$llm->live='По IMDb можно уточнить критерий; процитируй моё сообщение.';
$command->continuationReplyText('clarifying',['code'=>'choice_clarifying','_deadline'=>time()+20,'_action_context'=>['data'=>['resolution_snapshot'=>['intent'=>array_replace($base,['direction'=>'worst'])]]]]);
expect($llm->calls===[]&&str_contains($llm->liveRequest[0],'Источник оценок: IMDb')&&str_contains($llm->liveRequest[0],'средняя оценка'),'subsequent child refinement formats current rating metadata with zero WEB');
echo $fail?"FAILURES: $fail\n":"ALL PASS\n";exit($fail?1:0);
