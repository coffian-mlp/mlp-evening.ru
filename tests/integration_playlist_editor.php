<?php
require_once __DIR__.'/integration_helpers.php';
$db = it_require_db();
$core = Infra\Database::getInstance()->getConnection();
$m = new Domain\EpisodeManager();
$now = time();
$deny = static function(callable $fn, string $label): void {
    try { $fn(); check(false, $label); } catch (Core\UserError $e) { check(true, $label); }
};
try {
    Infra\Transaction::run($core, function() use ($core, $m, $now, $deny) {
        $core->query('UPDATE events SET use_playlist=0');
        $actor = (new Domain\UserManager())->createUser('it_editor_'.bin2hex(random_bytes(5)), 'Synthetic472!', 'admin');
        $ids = [];
        foreach (['Alpha','Beta','Gamma','Delta','Pair A','Pair B','Movie'] as $i=>$name) {
            $title = $i===4 || $i===5 ? 'My Little Pony Friendship is Magic - Season 99 Episode '.($i+1).' - '.$name : 'it472 '.$name;
            $q=$core->prepare('INSERT INTO episode_list(TITLE,LENGTH) VALUES(?,?)');$length=$i===6?4:1;$q->bind_param('si',$title,$length);$q->execute();$ids[]=$core->insert_id;
        }
        $core->query('UPDATE episode_list SET TWOPART_ID='.$ids[5].' WHERE ID='.$ids[4]);
        $core->query('UPDATE episode_list SET TWOPART_ID='.$ids[4].' WHERE ID='.$ids[5]);
        $stories=Domain\EpisodeCatalog::normalize($m->getAllEpisodes());$stories=json_decode(json_encode($stories),true);$by=[];foreach($stories as $s)foreach($s['ids'] as $id)$by[$id]=$s;
        $payload=json_encode([$by[$ids[0]],$by[$ids[1]]],JSON_THROW_ON_ERROR);
        $q=$core->prepare('INSERT INTO playlist_snapshots(created_at,payload_json,origin) VALUES(UTC_TIMESTAMP(),?,"test")');$q->bind_param('s',$payload);$q->execute();$sid=$core->insert_id;
        $start=gmdate('Y-m-d H:i:s',$now+3600);
        $q=$core->prepare('INSERT INTO events(title,start_time,duration_minutes,use_playlist) VALUES("it472 editor",?,60,1)');$q->bind_param('s',$start);$q->execute();$eid=$core->insert_id;$run=$eid.'_'.($now+3600);
        $m->bindOccurrence(['id'=>$eid,'run_id'=>$run,'real_start_time'=>$now+3600,'duration_minutes'=>60,'use_playlist'=>1],$sid);
        Infra\ConfigManager::getInstance()->setOption('current_playlist_snapshot_id',(string)$sid);
        $state=$m->getPlaylistEditor();
        check($state['snapshot_id']===$sid && $state['capacity_slots']===2,'nearest playlist and whole-slot capacity');
        $version=$state['version'];
        $apply=static function(array $op)use($m,$run,$actor,&$state){$state=$m->editUpcomingPlaylist($run,$state['snapshot_id'],$state['version'],$op,$actor);return $state;};
        $apply(['operation'=>'add','episode_id'=>$ids[5]]);
        check($state['stories'][2]['ids']===[$ids[4],$ids[5]] && $state['used_slots']===4 && $state['over_capacity'],'add second part creates full canonical story, overload permitted');
        $deny(fn()=>$m->editUpcomingPlaylist($run,$sid,$version,['operation'=>'remove','index'=>0],$actor),'stale version rejected');
        $deny(fn()=>$apply(['operation'=>'add','episode_id'=>$ids[4]]),'duplicate canonical story rejected');
        $deny(fn()=>$apply(['operation'=>'add','episode_id'=>2147483647]),'missing episode rejected');
        $deny(fn()=>$apply(['operation'=>'replace','index'=>0,'episode_id'=>$ids[1]]),'replacement duplicate rejected');
        $apply(['operation'=>'replace','index'=>0,'episode_id'=>$ids[2]]);
        check($state['stories'][0]==$by[$ids[2]] && $state['stories'][1]==$by[$ids[1]],'replace preserves unrelated story and weight');
        $apply(['operation'=>'move','index'=>2,'to_index'=>0]);
        check($state['stories'][0]['ids']===[$ids[4],$ids[5]],'move preserves paired story');
        $apply(['operation'=>'remove','index'=>1]);
        check(count($state['stories'])===2,'remove story');
        $apply(['operation'=>'add','episode_id'=>$ids[6]]);
        check($state['stories'][2]['length']===4,'movie keeps catalogue length');
        check($m->getSavedPlaylist()[0]['ids']===[$ids[4],$ids[5]] && $m->getOccurrenceSnapshot($run)['stories']===$state['stories'],'public and occurrence readers see edited payload');
        $rows=$core->query('SELECT * FROM audit_logs WHERE action="playlist_edit" AND user_id='.$actor)->fetch_all(MYSQLI_ASSOC);
        check(count($rows)===5,'one audit per successful operation only');
        $first=json_decode($rows[0]['details'],true);check($first['before'][0]==$by[$ids[0]] && count($first['after'])===3 && $first['run_id']===$run && $rows[0]['created_at']!=='','audit stores before, after, actor, time and run');
        check((int)$core->query('SELECT SUM(TIMES_WATCHED+WANNA_WATCH+legacy_wanna_watch) n FROM episode_list WHERE ID IN ('.implode(',',$ids).')')->fetch_assoc()['n']===0,'views and wishes untouched');
        $results=$m->searchPlaylistStories('it472');check(count($results)>0 && count($results)<=20,'bounded title search');
        check($m->searchPlaylistStories("' OR 1=1 --")===[],'search does not interpret SQL');
        $cfg=Infra\ConfigManager::getInstance();
        foreach (['ai_enabled'=>'1','ai_bot_user_id'=>(string)$actor,'ai_memory_enabled'=>'0','ai_proxy_url'=>''] as $key=>$value) $cfg->setOption($key,$value);
        $provider=new class implements LLM\LLMProviderInterface {
            public array $seen=[];
            public function withTimeout(int $seconds): self { return $this; }
            public function askChat(array $messagesContext,string $systemPrompt): ?string { $this->seen=$messagesContext;return 'Расписание готово.'; }
        };
        $llm=new class extends LLM\LLMManager { public function botSay(string $text,array $quotedIds=[]) { return 1; } };
        foreach (['providers','fastProviders'] as $property) (new ReflectionProperty(LLM\LLMManager::class,$property))->setValue($llm,[$provider]);
        (new ReflectionProperty(LLM\LLMManager::class,'vlessLink'))->setValue($llm,null);
        check($llm->processTrigger('dynamic_command',['message'=>'Расписание','event_run_id'=>$run,'command'=>['handler_type'=>'schedule','system_prompt'=>'']]),'real schedule command completes through fake provider without chat publication');
        $prompt=end($provider->seen)['content']??'';
        check(str_contains($prompt,'it472 editor') && str_contains($prompt,'it472 Movie') && str_contains($prompt,'Pair A') && !str_contains($prompt,'it472 Alpha'),'ScheduleData prompt includes edited occurrence stories and excludes replaced title');
        $ordinary=(new Domain\UserManager())->createUser('it_editor_user_'.bin2hex(random_bytes(5)), 'Synthetic472!', 'user');
        $deny(fn()=>$m->editUpcomingPlaylist($run,$sid,$state['version'],['operation'=>'remove','index'=>0],$ordinary),'non-admin domain write rejected');
        $deny(fn()=>$apply(['operation'=>'move','index'=>0,'to_index'=>99]),'invalid move rejected');
        $deny(fn()=>$apply(['operation'=>'remove','index'=>-1]),'invalid index rejected');
        $originalPayload=$m->getSnapshot($sid)['stories'];
        $activeRun='it472active:'.$actor;
        $m->bindOccurrence(['id'=>$eid,'run_id'=>$activeRun,'real_start_time'=>$now-600,'duration_minutes'=>120,'use_playlist'=>1],$sid);
        $apply(['operation'=>'remove','index'=>2]);
        check($state['snapshot_id']!==$sid && $m->getSnapshot($sid)['stories']===$originalPayload,'shared snapshot copied without mutating active occurrence');
        check($m->getCurrentSnapshot()['id']===$sid && $m->getPublicOccurrencePlaylists()[$run]===$state['stories'],'active global playlist preserved, public per-run map sees upcoming edit');
        $sid=$state['snapshot_id'];
        $old=$state;
        $core->query('UPDATE playlist_snapshots SET completed_at=UTC_TIMESTAMP() WHERE id='.$sid);
        $deny(fn()=>$apply(['operation'=>'remove','index'=>0]),'completed snapshot rejected');
        $core->query('UPDATE playlist_snapshots SET completed_at=NULL WHERE id='.$sid);
        $core->query('UPDATE playlist_occurrences SET start_at=UTC_TIMESTAMP() WHERE run_id="'.$run.'"');
        $deny(fn()=>$apply(['operation'=>'remove','index'=>0]),'started occurrence rejected');
        $q=$core->prepare('UPDATE playlist_occurrences SET start_at=? WHERE run_id=?');$q->bind_param('ss',$start,$run);$q->execute();
        $deny(fn()=>$m->editUpcomingPlaylist('not-nearest',$sid,$old['version'],['operation'=>'remove','index'=>0],$actor),'wrong occurrence rejected');
        $core->query('UPDATE events SET use_playlist=0 WHERE id='.$eid);
        $deny(fn()=>$apply(['operation'=>'remove','index'=>0]),'calendar flag changed before lifecycle rejected');
        throw new RuntimeException('rollback fixture');
    });
} catch (RuntimeException $e) { if ($e->getMessage()!=='rollback fixture') throw $e; }
finally { Infra\ConfigManager::getInstance()->flushCache(); }
it_done();
