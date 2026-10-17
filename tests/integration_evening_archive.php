<?php
require __DIR__.'/integration_helpers.php';
it_require_db();
$db=Infra\Database::getInstance()->getConnection();
$tag='it_archive_'.bin2hex(random_bytes(6));
$fail=0;
$check=static function($value,$label) use(&$fail){echo ($value?'[OK] ':'[FAIL] ').$label."\n";if(!$value)$fail++;};
$stories=[['story_id'=>'pair','ids'=>[1,2],'titles'=>['Part 1','Part 2']],['story_id'=>'single','ids'=>[3],'titles'=>['Single']]];
$payload=json_encode($stories);
$db->query("INSERT INTO playlist_snapshots(created_at,payload_json,origin) VALUES(UTC_TIMESTAMP(),'".$db->real_escape_string($payload)."','test')");
$snapshot=$db->insert_id;
$stamp='2090-01-01 12:00:00';$end='2090-01-01 13:00:00';$now=strtotime('2090-01-01 14:00:00 UTC');
try {
    foreach(['a','b','unknown','cancelled','future'] as $suffix){
        $run=$tag.$suffix;$state=$suffix==='cancelled'?'cancelled':'completed';
        $finish=$suffix==='future'?'2090-01-02 13:00:00':$end;
        $metadata=json_encode(['title'=>'<script>frozen</script>']);
        $s=$db->prepare('INSERT INTO playlist_occurrences(run_id,event_id,start_at,end_at,snapshot_id,state,metadata_json) VALUES(?,0,?,?,?,?,?)');
        $s->bind_param('sssiss',$run,$stamp,$finish,$snapshot,$state,$metadata);$s->execute();
        if(in_array($suffix,['a','b'])){
            $shown=json_encode($suffix==='a'?['pair']:[]);
            $s=$db->prepare('INSERT INTO playlist_completions(completion_key,snapshot_id,run_id,start_at,completed_at,shown_json) VALUES(?,?,?,?,?,?)');
            $s->bind_param('sissss',$run,$snapshot,$run,$stamp,$end,$shown);$s->execute();
        }
    }
    $owner=new Domain\EpisodeManager();
    $rows=array_values(array_filter($owner->getArchivePage(50,0,$now),fn($r)=>str_starts_with($r['run_id'],$tag)));
    $check(count($rows)===3,'exclude future and cancelled occurrences');
    $items=[];foreach($rows as $row)$items[substr($row['run_id'],strlen($tag))]=Domain\EveningArchive::present($row);
    $check($items['a']['watched_count']===2 && $items['a']['episodes'][2]['watched']===false,'two-part program and occurrence-specific watched projection');
    $check($items['b']['known'] && $items['b']['watched_count']===0,'empty confirmed showing is distinct from missing confirmation');
    $check(!$items['unknown']['known'] && $items['unknown']['episodes'][0]['watched']===null,'unknown completion is not invented from another showing of the same snapshot');
    $check($items['a']['date']==='01.01.2090 · 15:00','dates displayed in Moscow time');
    $run=$tag.'a';$s=$db->prepare('UPDATE playlist_completions SET shown_json=JSON_ARRAY("single") WHERE completion_key=?');$s->bind_param('s',$run);$s->execute();
    $rows=$owner->getArchivePage(50,0,$now);$updated=array_values(array_filter($rows,fn($r)=>$r['run_id']===$run))[0];
    $check(Domain\EveningArchive::present($updated)['watched_count']===1,'updated completion is reflected without rebuilding archive');
    $check($items['a']['title']==='<script>frozen</script>' && $items['a']['episodes'][0]['title']==='Part 1','use frozen metadata and titles, not current event/catalog');
}finally{
    $prefix=$tag.'%';
    foreach(['playlist_completions'=>'completion_key','playlist_occurrences'=>'run_id'] as $table=>$column){$s=$db->prepare("DELETE FROM $table WHERE $column LIKE ?");$s->bind_param('s',$prefix);$s->execute();}
    $db->query('DELETE FROM playlist_snapshots WHERE id='.(int)$snapshot);
}
echo $fail?"FAIL: $fail\n":"ALL PASS\n";exit($fail?1:0);
