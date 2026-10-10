<?php
require_once __DIR__.'/../autoload.php';
use LLM\MemoryRelevance;
use LLM\LyraMemory;
$fail = 0;
function relevanceCheck($ok, $label) { global $fail; echo ($ok?'[OK] ':'[FAIL] ').$label."\n"; if (!$ok) $fail++; }
$rows = [];
for ($i=0;$i<110;$i++) $rows[]=['id'=>$i,'text'=>'Свежая заметка о школьнике и чае.'];
$rows[]=['id'=>866,'text'=>'Пат-патокалипсис: паты как лесной пожар, спасает Бон-Бон с арбалетом.'];
$ranked=MemoryRelevance::rank($rows,'А помнишь, как Бон-Бон спасала всех из арбалета?');
relevanceCheck($ranked[0]['id']===866,'old legend beyond previous 100-row window retrieved by Russian topic');
relevanceCheck(MemoryRelevance::rank($rows,'что это как')===$rows,'stopwords retain original order');
relevanceCheck(MemoryRelevance::rank($rows,'совсем неизвестный термин')===$rows,'no matches retain manual/recency order');
relevanceCheck(count($ranked)===count($rows) && end($rows)['id']===866, 'retrieval does not change input records');
$block=LyraMemory::formatBlock([], $ranked, [],400,160,2400);
relevanceCheck(str_contains($block??'','Пат-патокалипсис'),'relevant legend reaches bounded prompt');
preg_match_all('/^Мем чата: .*$/mu',$block??'',$lines);
relevanceCheck(array_sum(array_map(fn($s)=>mb_strlen($s)+1,$lines[0]))<=160,'meme character budget unchanged');
$block=LyraMemory::formatBlock([],[['text'=>str_repeat('x',3000)],['text'=>'short stored fact']],[],400,100,2400);
relevanceCheck(str_contains($block??'','short stored fact'),'oversized entry does not hide following fitting records');
echo $fail ? "FAILED: $fail\n" : "ALL PASS\n"; exit($fail?1:0);
