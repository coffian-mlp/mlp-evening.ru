<?php
require_once __DIR__ . '/../autoload.php';
use LLM\EpisodeResolver;
use LLM\LLMManager;
use LLM\RouterAIProvider;
class ResolverFakeLlm extends LLMManager {
    public array $calls = [];
    public ?string $search = null;
    public ?string $verify = null;
    public function __construct() {}
    public function generateSearchUtility(array $context, string $prompt, ?int $deadlineSec = null): ?string { $this->calls[] = ['search', $deadlineSec]; return $this->search; }
    public function generateBoundedUtility(array $context, string $prompt, int $deadlineSec, int $timeoutSec = 20): ?string { $this->calls[] = ['verify', $deadlineSec]; return $this->verify; }
}
$fail=0;function expect($ok,$label){global $fail;echo($ok?'PASS ':'FAIL ').$label."\n";if(!$ok)$fail++;}
$llm=new ResolverFakeLlm();$resolver=new EpisodeResolver($llm);$catalog=[['ID'=>7,'TITLE'=>'Dragonshy'],['ID'=>8,'TITLE'=>'Look Before You Sleep']];
$llm->search=json_encode(['content'=>json_encode(['candidates'=>[['episode_id'=>7,'evidence'=>'Lyra sits on a bench','source_url'=>'https://example.org/episode'],['episode_id'=>999,'evidence'=>'invented','source_url'=>'https://example.org/episode']]]),'sources'=>[['url'=>'https://example.org/episode','title'=>'episode']]]);
$llm->verify='{"verified":[7,999]}';$out=$resolver->resolve('bench',$catalog,time()+55);
expect($out['status']==='found'&&array_column($out['candidates'],'episode_id')===[7],'catalog verified only');
expect(array_column($llm->calls,0)===['search','verify'],'independent contexts');
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
echo $fail?"FAILURES: $fail\n":"ALL PASS\n";exit($fail?1:0);
