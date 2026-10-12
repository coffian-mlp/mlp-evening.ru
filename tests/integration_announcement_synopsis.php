<?php
require_once __DIR__.'/integration_helpers.php';
if ((it_config()['db']['host']??'')!=='db') it_skip('isolated Docker required');
class Synopsis386 extends LLM\LLMManager {
 public int $neutral=0;
 public int $search=0;
 public bool $partial=false;
 public bool $fabricated=false;
 public bool $swapped=false;
 public string $prompt='';
 public function __construct(){}
 public function generateBoundedUtility(array $context,string $prompt,int $deadlineSec,int $timeoutSec=20):?string {
  $this->neutral++;$this->prompt=$prompt;$input=json_decode($context[0]['content'],true);$rows=[];
  foreach($input as $r)$rows[]=['episode_id'=>$r['episode_id'],'title'=>$r['title'],'summary'=>$r['synopsis'],'source'=>$r['source']];
  if($this->swapped && count($rows)===2)[$rows[0]['source'],$rows[1]['source']]=[$rows[1]['source'],$rows[0]['source']];
  return json_encode($this->partial?[]:$rows);
 }
 public function generateSynopsisUtility(array $context,string $prompt,int $deadlineSec):?string {
  $this->search++;$input=json_decode($context[0]['content'],true);$rows=[];
  foreach($input as $r)$rows[]=$r+['summary'=>'Verified fixture synopsis.','source'=>$this->fabricated?'https://invented.invalid':'https://evidence.example/episode'];
  return json_encode(['content'=>json_encode($rows),'sources'=>[['url'=>'https://evidence.example/episode']]]);
 }
}
$db=Infra\Database::getInstance()->getConnection();$db->begin_transaction();$llm=new Synopsis386();
try {
 $id=random_int(100000000,200000000);
 $stories=[['ids'=>[$id],'titles'=>['My Little Pony Friendship is Magic - Season 1 Episode 17 - Stare Master']]];
 $llm->partial=true;check(LLM\AnnouncementSynopsis::generate($llm,$stories)===null,'missing summary is not cached or invented');
 $llm->partial=false;$out=LLM\AnnouncementSynopsis::generate($llm,$stories);check($out && $out[0]['episode_id']===$id,'neutral seeded synopsis obtained');
 check(str_contains($llm->prompt,'без роли персонажа') && !str_contains($llm->prompt,'Память'),'first stage is independent of persona and memory');
 LLM\AnnouncementSynopsis::generate($llm,$stories);check($llm->neutral===2,'cached verified result reused for subsequent posts and rewrites');
 Infra\ConfigManager::getInstance()->setOption('announcements_prompt_synopsis',Domain\AnnouncementPrompts::defaults()['announcements_prompt_synopsis'].' CACHE_REVISION_CANARY');
 LLM\AnnouncementSynopsis::generate($llm,$stories);check($llm->neutral===3 && str_contains($llm->prompt,'CACHE_REVISION_CANARY'),'edited synopsis prompt invalidates cache and reaches neutral generator');
 $paired=[['ids'=>[$id+2,$id+3],'titles'=>['My Little Pony Friendship is Magic - Season 1 Episode 17 - Stare Master','My Little Pony Friendship is Magic - Season 2 Episode 19 - Putting Your Hoof Down']]];
 $llm->swapped=true;check(LLM\AnnouncementSynopsis::generate($llm,$paired)===null,'verified reference cannot be assigned to another episode');$llm->swapped=false;
 $unknown=[['ids'=>[$id+1],'titles'=>['Unknown fixture title']]];
 $llm->fabricated=true;check(LLM\AnnouncementSynopsis::generate($llm,$unknown)===null,'invented source outside provider evidence rejected');
 $llm->fabricated=false;check(LLM\AnnouncementSynopsis::generate($llm,$unknown)!==null && $llm->search===2,'missing local reference uses provider-grounded retrieval');
}finally{$db->rollback();Infra\ConfigManager::getInstance()->flushCache();}
it_done();
