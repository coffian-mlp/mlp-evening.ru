<?php
require_once __DIR__.'/integration_helpers.php';
if ((it_config()['db']['host']??'')!=='db') it_skip('isolated Docker required');
it_require_db();
class Synopsis386 extends LLM\LLMManager {
    public array $calls=[];
    public array $fail=[];
    public bool $prose=false;
    public bool $fabricated=false;
    public bool $wrongSeed=false;
    public bool $noSources=false;
    public function __construct() {}
    public function generateBoundedUtility(array $context,string $prompt,int $deadlineSec,int $timeoutSec=20): ?string {
        $input=json_decode($context[0]['content'],true);
        if (isset($input['retrieved_text'])) {
            $episode=$input['episode'];$this->calls[]=['normalize',$episode['episode_id'],$prompt,$deadlineSec];
            if (in_array($episode['episode_id'],$this->fail,true)) return '[]';
            $source=$this->fabricated?'https://invented.invalid':$input['sources'][0];
            return json_encode([$episode+['summary'=>'Проверенное описание.','source'=>$source]]);
        }
        $this->calls[]=['seed',$input[0]['episode_id'],$prompt,$deadlineSec];
        if (in_array($input[0]['episode_id'],$this->fail,true)) return '[]';
        $rows=[];
        foreach($input as $r) $rows[]=['episode_id'=>$r['episode_id'],'title'=>$r['title'],'summary'=>$r['synopsis'],'source'=>$this->wrongSeed?'https://invented.invalid':$r['source']];
        return json_encode($rows);
    }
    public function generateSynopsisUtility(array $context,string $prompt,int $deadlineSec): ?string {
        $input=json_decode($context[0]['content'],true);
        check(count($input)===1,'search receives exactly one episode');
        $episode=$input[0];$this->calls[]=['search',$episode['episode_id'],$prompt,$deadlineSec];
        if (in_array($episode['episode_id'],$this->fail,true)) return null;
        $rows=[$episode+['summary'=>'Проверенное описание.','source'=>$this->fabricated?'https://invented.invalid':'https://evidence.example/episode']];
        return json_encode(['content'=>$this->prose?'Найденное описание серии с цитатами.':json_encode($rows),'sources'=>$this->noSources?[]:[['url'=>'https://evidence.example/episode']]]);
    }
}
$db=Infra\Database::getInstance()->getConnection();$db->begin_transaction();$llm=new Synopsis386();
$story=static fn($id,$title)=>['ids'=>[$id],'titles'=>[$title]];
$id=random_int(100000000,200000000);
try {
    $seeded=[$story($id,'My Little Pony Friendship is Magic - Season 1 Episode 17 - Stare Master')];
    $llm->fail=[$id];
    check(LLM\AnnouncementSynopsis::generate($llm,$seeded)===null && LLM\AnnouncementSynopsis::lastError()==='synopsis_invalid:'.$id,'invalid summary reports episode-specific error');
    $llm->fail=[];$out=LLM\AnnouncementSynopsis::generate($llm,$seeded);
    check($out && $out[0]['episode_id']===$id && LLM\AnnouncementSynopsis::lastError()===null,'verified seed accepted and old error reset');
    $calls=count($llm->calls);LLM\AnnouncementSynopsis::generate($llm,$seeded);
    check(count($llm->calls)===$calls,'per-episode verified cache avoids another model request');
    check(str_contains($llm->calls[0][2],'без роли персонажа') && !str_contains($llm->calls[0][2],'Память'),'neutral stage excludes persona and memory');
    Infra\ConfigManager::getInstance()->setOption('announcements_prompt_synopsis',Domain\AnnouncementPrompts::defaults()['announcements_prompt_synopsis'].' CACHE_REVISION_CANARY');
    LLM\AnnouncementSynopsis::generate($llm,$seeded);
    check(count($llm->calls)===$calls+1 && str_contains(end($llm->calls)[2],'CACHE_REVISION_CANARY'),'edited prompt invalidates episode cache');

    $unknown=[$story($id+1,'Unknown fixture A'),$story($id+2,'Unknown fixture B')];
    $llm->fail=[$id+2];$calls=count($llm->calls);
    check(LLM\AnnouncementSynopsis::generate($llm,$unknown)===null,'one unavailable episode blocks incomplete announcement');
    check(LLM\AnnouncementSynopsis::lastError()==='synopsis_unavailable:'.($id+2),'unavailable model response identifies failed episode');
    $llm->fail=[];$out=LLM\AnnouncementSynopsis::generate($llm,$unknown);
    check($out && array_column($out,'episode_id')===[$id+1,$id+2],'retry returns complete original playlist order');
    check(array_column(array_slice($llm->calls,$calls),1)===[$id+1,$id+2,$id+2],'partial success persisted and only missing episode retried');
    $calls=count($llm->calls);$out=LLM\AnnouncementSynopsis::generate($llm,array_reverse($unknown));
    check(count($llm->calls)===$calls && array_column($out,'episode_id')===[$id+2,$id+1],'reordered or different playlist reuses individual summaries');

    $llm->prose=true;$calls=count($llm->calls);
    check(LLM\AnnouncementSynopsis::generate($llm,[$story($id+3,'Prose fixture')])!==null,'search prose normalized without repeating web search');
    check(array_column(array_slice($llm->calls,$calls),0)===['search','normalize'],'retrieval and JSON normalization are separate bounded calls');
    $llm->prose=false;$llm->fabricated=true;
    check(LLM\AnnouncementSynopsis::generate($llm,[$story($id+4,'Citation fixture')])===null,'fabricated citation rejected during search and normalization');
    $llm->fabricated=false;$llm->wrongSeed=true;
    check(LLM\AnnouncementSynopsis::generate($llm,[$story($id+5,'My Little Pony Friendship is Magic - Season 2 Episode 19 - Putting Your Hoof Down')])!==null,'normalization restores known reference source');
    $llm->noSources=true;
    check(LLM\AnnouncementSynopsis::generate($llm,[$story($id+6,'No evidence fixture')])===null && LLM\AnnouncementSynopsis::lastError()==='synopsis_sources_missing:'.($id+6),'uncited output distinguished from unavailable model and never cached');
    check(LLM\AnnouncementSynopsis::generate($llm,[['ids'=>[1,2],'titles'=>['Broken']]])===null && LLM\AnnouncementSynopsis::lastError()==='synopsis_catalogue_invalid','malformed playlist has explicit failure reason');
} finally { $db->rollback();Infra\ConfigManager::getInstance()->flushCache(); }
it_done();
