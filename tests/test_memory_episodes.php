<?php
require_once __DIR__ . '/../autoload.php';
use LLM\MemoryEpisodeBuilder;
$fail = 0;
function episodeCheck(bool $ok, string $label): void { global $fail; echo ($ok ? '[OK] ' : '[FAIL] ') . $label . "\n"; if (!$ok) $fail++; }
$builder = new MemoryEpisodeBuilder(12);
$rows = [];
for ($i = 1; $i <= 45; $i++) $rows[] = ['id'=>$i,'user_id'=>($i===2?12:1),'username'=>'Автор','message'=>'Реплика '.$i,'created_at'=>gmdate('Y-m-d H:i:s',1700000000+$i),'edited_at'=>null,'is_deleted'=>0];
$episodes = $builder->chat($rows, 'profile');
episodeCheck(count($episodes)===3, 'cap 20 and overlap 3 split a live tail');
episodeCheck(count($episodes[0]['sources'])===20 && $episodes[1]['sources'][0]['source_id']===18, 'overlap is three messages');
episodeCheck(str_contains($episodes[0]['text'],'[bot]'), 'bot statements are attributed');
episodeCheck($episodes===$builder->chat($rows,'profile'), 'keys and hashes are stable across restart');
$changed=$rows; $changed[0]['message']='Изменённое';
episodeCheck($builder->chat($changed,'profile')[0]['key']===$episodes[0]['key'] && $builder->chat($changed,'profile')[0]['hash']!==$episodes[0]['hash'], 'edit changes hash without changing source anchor');
episodeCheck($builder->chat($rows,'other')[0]['hash']!==$episodes[0]['hash'], 'profile changes source hash');
$pair=array_slice($rows,0,2);$pair[1]['created_at']=gmdate('Y-m-d H:i:s',strtotime($pair[0]['created_at'].' UTC')+600);
episodeCheck(count($builder->chat($pair,'profile'))===1,'exactly 10 minutes stays together');
$pair[1]['created_at']=gmdate('Y-m-d H:i:s',strtotime($pair[0]['created_at'].' UTC')+601);
episodeCheck(count($builder->chat($pair,'profile'))===2,'pause greater than 10 minutes splits without overlap');
$long=$rows[0];$long['message']=str_repeat('я',11601);
$longEpisodes=$builder->chat([$long],'profile');$reconstructed='';
foreach($longEpisodes as $episode){
 episodeCheck(mb_strlen($episode['text'])<=4000,'formatted episode fits character cap');
 $source=$episode['sources'][0];$reconstructed.=mb_substr($long['message'],$source['start_offset'],$source['end_offset']-$source['start_offset']);
}
episodeCheck($reconstructed===$long['message'],'long source offsets preserve the entire text');
$sized=array_slice($rows,0,5);
foreach($sized as $i=>&$row)$row['message']=str_repeat('x',$i===4?3700:800);
unset($row);
$seen=[];
foreach($builder->chat($sized,'profile')as$episode){
 $new=false;
 foreach($episode['sources']as$source){$key=$source['source_id'].':'.$source['start_offset'];if(!isset($seen[$key]))$new=true;$seen[$key]=true;}
 episodeCheck($new,'character cap never emits an overlap-only episode');
}
$excluded=$rows[0];$excluded['is_deleted']=1;
episodeCheck($builder->chat([$excluded],'profile')===[],'deleted messages excluded');
$excluded['is_deleted']=0;$excluded['message']=' ';
episodeCheck($builder->chat([$excluded],'profile')===[],'technical empty sources excluded');
$memory=$builder->memory([['id'=>9,'user_id'=>1,'created_by'=>2,'text'=>'DL-6','kind'=>'meme','source'=>'manual','created_at'=>'2026-01-01 00:00:00','updated_at'=>'2026-01-01 00:00:00']], 'profile');
episodeCheck(count($memory)===1 && $memory[0]['kind']==='memory' && $memory[0]['sources'][0]['source_id']===9,'bot_memory keeps its own provenance');
echo $fail ? "FAILED: $fail\n" : "ALL PASS\n";exit($fail?1:0);
