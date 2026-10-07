<?php
require_once __DIR__ . '/../autoload.php';
use LLM\EpisodeResolver;
use LLM\LLMManager;
use LLM\RouterAIProvider;
class ResolverFakeLlm extends LLMManager {
    public array $calls = [];
    public ?string $search = null;
    public ?string $verify = null;
    public ?string $normalized = '{"version":1,"intent":"plot","search_query":"MLP episode plot"}';
    public function __construct() {}
    public function generateSearchQueryUtility(array $context, string $prompt, int $deadlineSec, int $timeoutSec = 8): ?string { $this->calls[] = ['normalize', $deadlineSec, $context, $prompt]; return $this->normalized; }
    public function generateSearchUtility(array $context, string $prompt, ?int $deadlineSec = null): ?string { $this->calls[] = ['search', $deadlineSec, $context, $prompt]; return $this->search; }
    public function generateBoundedUtility(array $context, string $prompt, int $deadlineSec, int $timeoutSec = 20): ?string { $this->calls[] = ['verify', $deadlineSec, $context, $prompt]; return $this->verify; }
}
$fail=0;function expect($ok,$label){global $fail;echo($ok?'PASS ':'FAIL ').$label."\n";if(!$ok)$fail++;}
$llm=new ResolverFakeLlm();$resolver=new EpisodeResolver($llm);$catalog=[['ID'=>7,'TITLE'=>'Dragonshy'],['ID'=>8,'TITLE'=>'Look Before You Sleep']];
$llm->search=json_encode(['content'=>json_encode(['candidates'=>[['episode_id'=>7,'evidence'=>'Lyra sits on a bench','source_url'=>'https://example.org/episode'],['episode_id'=>999,'evidence'=>'invented','source_url'=>'https://example.org/episode']]]),'sources'=>[['url'=>'https://example.org/episode','title'=>'episode']]]);
$llm->verify='{"verified":[7,999]}';$out=$resolver->resolve('bench',$catalog,time()+55);
expect($out['status']==='found'&&array_column($out['candidates'],'episode_id')===[7],'catalog verified only');
expect(array_column($llm->calls,0)===['normalize','search','verify'],'independent contexts');
$llm->verify='{"verified":[]}';expect($resolver->resolve('bench',$catalog)['status']==='need_clarification','verifier disagreement');
$llm->verify=null;expect($resolver->resolve('bench',$catalog)['status']==='unavailable','verifier outage');
$llm->search='{"content":"{\"candidates\":[]}","sources":[]}';expect($resolver->resolve('bench',$catalog)['status']==='unavailable','no real search citations');
expect($resolver->resolve(str_repeat('x',601),$catalog)['status']==='need_clarification','bounded input');
$p=new RouterAIProvider('test','test',null,100);$clone=$p->withWebSearch(9);$prop=new ReflectionProperty($p,'webSearch');expect($prop->getValue($p)===null,'main provider unchanged');expect($prop->getValue($clone)===['id'=>'web','engine'=>'exa','max_results'=>3],'official plugin scoped payload');
$codedCatalog=[['ID'=>7,'TITLE'=>'My Little Pony Friendship is Magic - Season 1 Episode 7 - Dragonshy'],['ID'=>8,'TITLE'=>'My Little Pony Friendship is Magic - Season 1 Episode 8 - Look Before You Sleep']];
$llm->search=json_encode(['content'=>json_encode(['candidates'=>[['episode_code'=>'S01E07','title'=>'Dragonshy','evidence'=>'bench','source_url'=>'https://example.org/episode']]]),'sources'=>[['url'=>'https://example.org/episode']]]);$llm->verify='{"verified":[7]}';
expect($resolver->resolve('bench',$codedCatalog)['candidates'][0]['episode_id']===7,'short search code/title mapped by server catalog');
$llm->search=json_encode(['content'=>json_encode(['candidates'=>[['episode_code'=>'S01E08','title'=>'Dragonshy','evidence'=>'bench','source_url'=>'https://example.org/episode']]]),'sources'=>[['url'=>'https://example.org/episode']]]);
expect($resolver->resolve('bench',$codedCatalog)['status']==='need_clarification','inconsistent code and title rejected');
$captured=$llm->calls[1][2][0]['content'];
expect(str_contains($captured,'My Little Pony') && !str_contains($captured,'Lyra Heartstrings'),'plain search query scoped to MLP without irrelevant identity');
$capturedVerify=json_decode($llm->calls[2][2][0]['content'],true);
expect(str_contains($capturedVerify['subject'],'My Little Pony') && $capturedVerify['search_query']===$captured && $capturedVerify['original_query']==='bench' && !isset($capturedVerify['recipient_identity']),'verifier same trusted subject and identity');
$llm->calls=[];$resolver->resolve('про тебя',$codedCatalog);
expect(str_contains($llm->calls[1][2][0]['content'],'Lyra Heartstrings'),'pronoun reference adds Lyra identity only when needed');
$before=count($llm->calls);$semantic=[['ID'=>413,'TITLE'=>'My Little Pony Friendship is Magic - Season 1 Episode 1 - Friendship is Magic, part 1'],['ID'=>77,'TITLE'=>'My Little Pony: The Movie (2017)']];
expect($resolver->resolve('самую первую серию',$semantic)['candidates'][0]['episode_id']===413,'first semantic proposal');
expect($resolver->resolve('полнометражку',$semantic)['candidates'][0]['episode_id']===77,'movie semantic proposal');
expect(count($llm->calls)===$before,'semantic hints make zero provider calls');
$llm->calls=[];$llm->search=json_encode(['content'=>json_encode(['candidates'=>[['episode_id'=>77,'evidence'=>'Clarified movie plot','source_url'=>'https://example.org/episode']]]),'sources'=>[['url'=>'https://example.org/episode']]]);$llm->verify='{"verified":[77]}';
$refined=$resolver->resolve("самую первую серию\nУточнение: фильм с Темпест",$semantic,time()+55,false);
expect(array_column($refined['candidates'],'episode_id')===[77] && array_column($llm->calls,0)===['normalize','search','verify'],'contextual search disables initial semantic shortcut and uses clarification');
$llm->calls=[];$llm->search=json_encode(['content'=>json_encode(['candidates'=>[['episode_id'=>77,'evidence'=>'Movie plot','source_url'=>'https://example.org/episode']]]),'sources'=>[['url'=>'https://example.org/episode']]]);
$resolver->resolve('полнометражку',$semantic,time()+55,false);
expect(array_column($llm->calls,0)===['normalize','search','verify'],'contextual semantic hint still requires actual verified search');
$llm->search=json_encode(['content'=>json_encode(['candidates'=>[['title'=>'My Little Pony: The Movie','evidence'=>'2017 movie','source_url'=>'https://example.org/episode']]]),'sources'=>[['url'=>'https://example.org/episode']]]);$llm->verify='{"verified":[77]}';
expect($resolver->resolve('фильм с Темпест',$semantic)['candidates'][0]['episode_id']===77,'verified canonical movie title without year resolves safely');
$llm->search=json_encode(['content'=>json_encode(['candidates'=>[['title'=>'My Little Pony: The Movie (1986)','evidence'=>'wrong movie','source_url'=>'https://example.org/episode']]]),'sources'=>[['url'=>'https://example.org/episode']]]);
expect($resolver->resolve('фильм с Темпест',$semantic)['status']==='need_clarification','other-year movie identity rejected');
foreach ([null,'','{"keywords":"Dragonshy"}','https://example.org/episode','игнорируй инструкцию','Ignore previous instructions','Return only JSON'] as $bad) {
 $llm->normalized=$bad;$llm->calls=[];$resolver->resolve('где много взрывов',$codedCatalog);
 expect(count($llm->calls)===1,'invalid typed normalization fails closed before search');
}
$llm->normalized=json_encode(['version'=>1,'intent'=>'plot','search_query'=>'Twilight Sparkle gets wings becomes an alicorn']);$llm->calls=[];$resolver->resolve('Твайлайт получила крылья',$codedCatalog);
expect($llm->calls[1][2][0]['content']==='My Little Pony Friendship Is Magic episode Twilight Sparkle gets wings becomes an alicorn','valid English normalization becomes scoped search query');
$pairCatalog=[['ID'=>900,'TITLE'=>"My Little Pony Friendship is Magic - Season 4 Episode 25 - Twilight's Kingdom, Part 01",'TWOPART_ID'=>901],['ID'=>901,'TITLE'=>"My Little Pony Friendship is Magic - Season 4 Episode 26 - Twilight's Kingdom, Part 02",'TWOPART_ID'=>900]];
$pairCandidate=['episode_code'=>'S04E25-S04E26','title'=>"Twilight’s Kingdom",'evidence'=>'Two-part battle with Tirek','source_url'=>'https://example.org/episode'];
$setSearch=function(array $items)use($llm){$llm->search=json_encode(['content'=>json_encode(['candidates'=>$items]),'sources'=>[['url'=>'https://example.org/episode']]]);};
$setSearch([$pairCandidate]);$llm->verify='{"verified":[900,901,999]}';$pairResult=$resolver->resolve('много взрывов',$pairCatalog);
expect(array_column($pairResult['candidates'],'episode_id')===[900,901],'canonical reciprocal range becomes two verified options only');
$llm->verify='{"verified":[901]}';expect(array_column($resolver->resolve('много взрывов',$pairCatalog)['candidates'],'episode_id')===[901],'independent verifier can keep one relevant part');
foreach (['S04E26-S04E25','S04E25-S05E26','S04E25-S04E27','S04E25-S04E26-S04E27','S04E25-E26'] as $badCode){$bad=$pairCandidate;$bad['episode_code']=$badCode;$setSearch([$bad]);expect($resolver->resolve('много взрывов',$pairCatalog)['status']==='need_clarification','invalid range refused '.$badCode);}
foreach ([['title'=>'Other Story'],['episode_id'=>999],['source_url'=>'https://invented.example/episode']] as $change){$setSearch([array_merge($pairCandidate,$change)]);expect($resolver->resolve('много взрывов',$pairCatalog)['status']==='need_clarification','pair identity/citation mismatch refused');}
$setSearch([$pairCandidate]);$oneSided=$pairCatalog;$oneSided[1]['TWOPART_ID']=0;expect($resolver->resolve('много взрывов',$oneSided)['status']==='need_clarification','one-sided pair refused');
$unknown=$pairCatalog;array_pop($unknown);expect($resolver->resolve('много взрывов',$unknown)['status']==='need_clarification','missing second part refused');
$wrongTitle=$pairCatalog;$wrongTitle[1]['TITLE']='My Little Pony Friendship is Magic - Season 4 Episode 26 - Other Story, Part 2';expect($resolver->resolve('много взрывов',$wrongTitle)['status']==='need_clarification','different canonical part title refused');
$singles=[['episode_code'=>'S01E07','title'=>'Dragonshy','evidence'=>'Dragon','source_url'=>'https://example.org/episode'],['episode_code'=>'S01E08','title'=>'Look Before You Sleep','evidence'=>'Sleep','source_url'=>'https://example.org/episode']];
$llm->verify='{"verified":[7,8,900,901]}';$setSearch([...$singles,$pairCandidate]);expect(array_column($resolver->resolve('много взрывов',[...$codedCatalog,...$pairCatalog])['candidates'],'episode_id')===[7,8],'range not arbitrarily cut when only one slot remains');
$setSearch([$singles[0],$pairCandidate]);expect(array_column($resolver->resolve('много взрывов',[...$codedCatalog,...$pairCatalog])['candidates'],'episode_id')===[7,900,901],'whole range fits remaining two slots');
$characterCatalog=[['ID'=>100,'TITLE'=>'My Little Pony Friendship is Magic - Season 5 Episode 9 - Slice of Life'],['ID'=>151,'TITLE'=>'My Little Pony Friendship is Magic - Season 7 Episode 8 - Hard to Say Anything'],['ID'=>179,'TITLE'=>'My Little Pony Friendship is Magic - Season 8 Episode 10 - The Break Up Break Down']];
$characterCandidates=[['episode_code'=>'S07E08','title'=>'Hard to Say Anything','evidence'=>'Sugar Belle appears and participates','source_url'=>'https://example.org/episode'],['episode_code'=>'S08E10','title'=>'The Break Up Break Down','evidence'=>'Sugar Belle has a characteristic scene','source_url'=>'https://example.org/episode']];
$llm->calls=[];$llm->normalized=json_encode(['version'=>1,'intent'=>'plot','search_query'=>'Sugar Belle appearances characteristic scenes']);$setSearch($characterCandidates);$llm->verify='{"verified":[151,179]}';
expect(array_column($resolver->resolve('серию про Шугар Белл',$characterCatalog)['candidates'],'episode_id')===[151,179],'broad character interest offers multiple independently verified canonical choices');
expect(str_contains($llm->calls[0][3],'negations and exclusions') && str_contains($llm->calls[0][3],'do not impose a central plot role'),'normalizer preserves constraints without adding a strict character plot condition');
expect(str_contains($llm->calls[1][3],'до трёх') && str_contains($llm->calls[1][3],'центральная роль не обязательна'),'search admits several useful character appearances');
expect(str_contains($llm->calls[2][3],'одно присутствие персонажа их не заменяет'),'verifier retains explicit events negations and exclusions');
$llm->calls=[];$llm->normalized=json_encode(['version'=>1,'intent'=>'plot','search_query'=>'Lyra Heartstrings appearances']);$setSearch([['episode_code'=>'S05E09','title'=>'Slice of Life','evidence'=>'Lyra Heartstrings appears in a characteristic scene','source_url'=>'https://example.org/episode']]);$llm->verify='{"verified":[100]}';
expect($resolver->resolve('серию про тебя',$characterCatalog)['candidates'][0]['episode_id']===100,'recipient character interest resolves a verified appearance without hardcoded episode selection');
expect(json_decode($llm->calls[2][2][0]['content'],true)['recipient_identity']==='Lyra Heartstrings / Лира Хартстрингс','broad verifier retains actual Lyra recipient identity');
$llm->calls=[];$setSearch($characterCandidates);$llm->verify='{"verified":[]}';
expect($resolver->resolve('серию где Шугар Белл победила дракона без Рэрити',$characterCatalog)['status']==='need_clarification','unverified specific event is not replaced by character presence');
expect(json_decode($llm->calls[2][2][0]['content'],true)['original_query']==='серию где Шугар Белл победила дракона без Рэрити','specific positive and negative constraints reach authoritative verifier unchanged');
$badCharacter=$characterCandidates[0];$badCharacter['source_url']='https://invented.example/character';$setSearch([$badCharacter]);$llm->verify='{"verified":[151]}';
expect($resolver->resolve('серию про Шугар Белл',$characterCatalog)['status']==='need_clarification','broad interest never bypasses actual citation provenance');
// First-appearance search uses a distributor alternate title, never a hardcoded catalogue ID.
$appearanceCatalog=[['ID'=>413,'TITLE'=>'My Little Pony Friendship is Magic - Season 1 Episode 01 - Friendship Is Magic, Part 01']];
$appearance=['episode_code'=>'S01E01','title'=>'Mare in the Moon','evidence'=>'Lyra first appears in this episode','source_url'=>'https://example.org/episode'];
$setSearch([$appearance]);$llm->calls=[];$llm->verify='{"verified":[413]}';
$appearanceResult=$resolver->resolve("серию про тебя\nУточнение: Скорее твоё первое появление",$appearanceCatalog,time()+55,false);
expect(array_column($appearanceResult['candidates'],'episode_id')===[413],'first appearance alternate title reaches independent verifier and returns canonical ID');
expect(array_column($llm->calls,0)===['normalize','search','verify'],'alternate title never bypasses independent verification');
$verifyInput=json_decode($llm->calls[2][2][0]['content'],true);
expect($verifyInput['candidates'][0]['reported_title']==='Mare in the Moon' && str_contains($verifyInput['candidates'][0]['canonical_title'],'Part 01'),'verifier sees reported alternate and canonical catalogue identity');
$llm->verify='{"verified":[]}';expect($resolver->resolve('твоё первое появление',$appearanceCatalog,time()+55,false)['status']==='need_clarification','alternate candidate still rejected by independent verifier');
foreach ([['title'=>'Invented Moon'],['episode_code'=>'S01E07'],['episode_code'=>'413'],['episode_code'=>'Friendship Is Magic, Part 01'],['episode_id'=>999],['source_url'=>'https://invented.example/source']] as $bad) {
 $setSearch([array_merge($appearance,$bad)]);$llm->calls=[];$llm->verify='{"verified":[413]}';
 expect($resolver->resolve('твоё первое появление',$appearanceCatalog,time()+55,false)['status']==='need_clarification' && count($llm->calls)===2,'alternate code/title/ID/citation disagreement fails before verifier');
}
$setSearch([$appearance]);$badCanonical=$appearanceCatalog;$badCanonical[0]['TITLE']='My Little Pony Friendship is Magic - Season 1 Episode 01 - Other Story';
expect($resolver->resolve('твоё первое появление',$badCanonical,time()+55,false)['status']==='need_clarification','finite alias requires matching canonical metadata, not arbitrary valid code');
$setSearch([array_merge($appearance,['title'=>'The Mare in the Moon'])]);$llm->verify='{"verified":[413]}';
expect($resolver->resolve('твоё первое появление',$appearanceCatalog,time()+55,false)['status']==='found','known title with definite article admits exact canonical identity');
// Captured deployed provider candidate; replay preserves actual citation membership.
$partOneSearch=['content'=>'```json
{"candidates":[{"episode_code":"S01E01","title":"Mare in the Moon: Part 1","evidence":"Lyra Heartstrings — фоновая единорог из Понивилля; её первое появление в сериале — дебютная серия первого сезона","source_url":"https://equestripedia.org/wiki/Lyra_Heartstrings_(Friendship_is_Magic)"}]}
```','sources'=>[['url'=>'https://equestripedia.org/wiki/Lyra_Heartstrings_(Friendship_is_Magic)','title'=>'Lyra Heartstrings (Friendship is Magic) - Equestripedia'],['url'=>'https://thesouthernnerd.com/2017/08/03/lyra-the-human-obsessed-pony/','title'=>'Lyra, the human-obsessed pony – The Southern Nerd'],['url'=>'http://www.mylittlewiki.org/wiki/The_Mare_in_the_Moon:_Part_1','title'=>'The Mare in the Moon: Part 1 - My Little Wiki']]];
$llm->search=json_encode($partOneSearch,JSON_UNESCAPED_UNICODE);$llm->calls=[];$llm->verify='{"verified":[413]}';
$partOneResult=$resolver->resolve("серию про тебя\nУточнение: Скорее твоё первое появление",$appearanceCatalog,time()+55,false);
expect($partOneResult['status']==='found' && array_column($partOneResult['candidates'],'episode_id')===[413] && array_column($llm->calls,0)===['normalize','search','verify'],'captured deployed Part 1 provider response reaches independent verifier');
if (isset($llm->calls[2])) {
 $partOneInput=json_decode($llm->calls[2][2][0]['content'],true);
 expect($partOneInput['candidates'][0]['reported_title']==='Mare in the Moon: Part 1' && $partOneInput['original_query']==="серию про тебя\nУточнение: Скорее твоё первое появление",'captured alias retains reported identity and original refinement at verifier');
} else expect(false,'captured alias retains reported identity and original refinement at verifier');
foreach (['Mare in the Moon: Part 1','The Mare in the Moon: Part 1'] as $title) {
 $setSearch([array_merge($appearance,['title'=>$title])]);$llm->calls=[];$llm->verify='{"verified":[413]}';
 expect($resolver->resolve('твоё первое появление',$appearanceCatalog,time()+55,false)['status']==='found' && array_column($llm->calls,0)===['normalize','search','verify'],'finite Part 1 alias passes independent verification: '.$title);
 $llm->verify='{"verified":[]}';expect($resolver->resolve('твоё первое появление',$appearanceCatalog,time()+55,false)['status']==='need_clarification','Part 1 alias cannot bypass verifier rejection: '.$title);
}
foreach (['Mare in the Moon: Part 2','The Mare in the Moon: Part 2'] as $title) {
 $setSearch([array_merge($appearance,['title'=>$title])]);$llm->calls=[];$llm->verify='{"verified":[413]}';
 expect($resolver->resolve('твоё первое появление',$appearanceCatalog,time()+55,false)['status']==='need_clarification' && count($llm->calls)===2,'Part 2 title cannot map to S01E01: '.$title);
}
$setSearch([array_merge($appearance,['title'=>'The Mare in the Moon'])]);
$ambiguous=[...$appearanceCatalog,['ID'=>414,'TITLE'=>$appearanceCatalog[0]['TITLE']]];
expect($resolver->resolve('твоё первое появление',$ambiguous,time()+55,false)['status']==='need_clarification','ambiguous code has no alternate admission');
echo $fail?"FAILURES: $fail\n":"ALL PASS\n";exit($fail?1:0);
