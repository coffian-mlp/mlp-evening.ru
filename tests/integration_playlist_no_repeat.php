<?php
require_once __DIR__.'/integration_helpers.php';
if ((it_config()['db']['host']??'')!=='db') it_skip('isolated Docker database required');
$probe=it_require_db();$probe->close();
$db=Infra\Database::getInstance()->getConnection();$db->begin_transaction();
try {
    // Narrow only the isolated test catalogue; all changes are rolled back together.
    $db->query('DELETE FROM playlist_completions');
    $db->query('UPDATE episode_list SET LENGTH=0');
    $ids=[];
    foreach (range(1,5) as $number) {
        $title='My Little Pony Friendship is Magic - Season 98 Episode '.$number.' - No repeat fixture '.$number;
        $q=$db->prepare('INSERT INTO episode_list(TITLE,LENGTH) VALUES(?,1)');$q->bind_param('s',$title);$q->execute();$ids[]=(int)$db->insert_id;
    }
    $db->query('UPDATE episode_list SET TWOPART_ID='.$ids[1].' WHERE ID='.$ids[0]);
    $db->query('UPDATE episode_list SET TWOPART_ID='.$ids[0].' WHERE ID='.$ids[1]);
    $manager=new Domain\EpisodeManager();$stories=Domain\EpisodeCatalog::normalize($manager->getAllEpisodes());
    $flat=static function(array $rows): array {$out=$rows?array_merge(...array_column($rows,'ids')):[];sort($out);return $out;};
    $current=static fn()=> $manager->getCurrentSnapshot()['stories'];
    $manager->regeneratePlaylist();
    check($flat($current())===$ids,'no viewing history leaves whole catalogue eligible');
    $payload=json_encode(array_slice($stories,0,3),JSON_THROW_ON_ERROR);
    $q=$db->prepare('INSERT INTO playlist_snapshots(created_at,payload_json,origin) VALUES(UTC_TIMESTAMP(),?,"test")');$q->bind_param('s',$payload);$q->execute();$source=(int)$db->insert_id;
    $actor=(new Domain\UserManager())->createUser('it_mlp427_'.bin2hex(random_bytes(5)),'Fixture427!Password','admin');
    $base=time()-86400;$prefix='it427:'.bin2hex(random_bytes(6));
    $bind=static function(string $run,int $snapshot,int $start,bool $view=true) use($manager): void {
        $manager->bindOccurrence(['run_id'=>$run,'id'=>999997,'real_start_time'=>$start,'duration_minutes'=>10,'use_playlist'=>$view?1:0,'generate_new_playlist'=>1],$snapshot);
    };
    $bind($prefix.':first',$source,$base);
    $out=$manager->finishOccurrence($prefix.':first',$base+600);
    check($out['view_accounted'] && $flat($current())===[$ids[4]],'auto finish excludes newly accounted viewing before selecting successor');
    check(array_sum(array_column($current(),'length'))===1,'insufficient pool stays short without recent repeats');
    $generated=$out['generated_snapshot_id'];$replay=$manager->finishOccurrence($prefix.':first',$base+601);
    check($replay['generated_snapshot_id']===$generated,'completion replay does not generate another snapshot');
    $manager->correctCompletion($source,[$stories[0]['story_id'],$stories[1]['story_id']],$actor,$prefix.':correct-first',$prefix.':first');
    $manager->regeneratePlaylist();
    check($flat($current())===[$ids[3],$ids[4]],'correction releases episode planned but not actually watched, keeping whole pair excluded');
    $secondSource=$manager->getCurrentSnapshot()['id'];
    $manager->regeneratePlaylist();
    check($flat($current())===[$ids[3],$ids[4]],'manual reroll does not reset exclusion of last watched episodes');
    $bind($prefix.':second',$secondSource,$base+3600);
    $manager->finishOccurrence($prefix.':second',$base+4200);
    check($flat($current())===array_slice($ids,0,3),'after next viewing older episodes return and just-watched ones are excluded');
    $bind($prefix.':night',0,$base+7200,false);
    $manager->finishOccurrence($prefix.':night',$base+7800);
    check($flat($current())===array_slice($ids,0,3),'generation-only event does not replace last actual viewing');
    $bind($prefix.':historical',$secondSource,$base-3600);
    $manager->completeSnapshot($secondSource,$prefix.':historical',null,time());
    $manager->regeneratePlaylist();
    check($flat($current())===array_slice($ids,0,3),'late accounting of an older event does not displace latest chronological viewing');
    $manager->correctCompletion($secondSource,[],$actor,$prefix.':correct-historical',$prefix.':historical');
    $manager->regeneratePlaylist();
    check($flat($current())===array_slice($ids,0,3),'correction of older showing of same snapshot does not change latest exclusion');
    $manager->correctCompletion($secondSource,[$stories[3]['story_id']],$actor,$prefix.':correct-second',$prefix.':second');
    $manager->regeneratePlaylist();
    check($flat($current())===array_slice($ids,0,4),'corrections use occurrence-specific shown list rather than overwritten snapshot summary');
    $manager->correctCompletion($secondSource,[],$actor,$prefix.':clear-second',$prefix.':second');
    $manager->regeneratePlaylist();
    check($flat($current())===$ids,'empty corrected viewing excludes no unshown episodes');
} finally {$db->rollback();Infra\ConfigManager::getInstance()->flushCache();}
it_done();
