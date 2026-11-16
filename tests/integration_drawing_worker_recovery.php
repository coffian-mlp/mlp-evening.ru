<?php
ob_start();
require __DIR__.'/integration_helpers.php';
$peer=it_require_db();
if(!is_file(dirname(__DIR__).'/.mlp468-owned'))it_skip('Requires drawing_environment.sh synthetic laboratory');
require __DIR__.'/helpers/drawing_fixture.php';
require __DIR__.'/helpers/drawing_process.php';
$state=drawingFixture('setup');$db=Infra\Database::getInstance()->getConnection();$cfg=Infra\ConfigManager::getInstance();$queue=new LLM\JobQueue();
$dir=dirname(__DIR__).'/cache/mlp468';$children=[];
$traceCount=static function(string $kind)use($dir):int{if(!is_file($dir.'/trace.jsonl'))return 0;$rows=array_map(static fn($s)=>json_decode($s,true),file($dir.'/trace.jsonl',FILE_IGNORE_NEW_LINES));return count(array_filter($rows,static fn($r)=>($r['kind']??'')===$kind));};
$editState=static function(array $values)use($dir):void{$s=json_decode(file_get_contents($dir.'/state.json'),true);file_put_contents($dir.'/state.json',json_encode(array_merge($s,$values)));};
$inlineJob=static function(string $handler='image')use($state,$queue):int{
    static $sequence=0;
    $command=$state[$handler];$text=$command['command_prefix'].($handler==='image'?' синтетический замок ':' синтетическая тема ').++$sequence;
    $source=(new Domain\ChatManager())->addMessage($state['owner'],'Рисующий',$text);
    return $queue->reserveInline('dynamic_command',['command'=>$command,'message'=>$text,'message_id'=>$source,'user_id'=>$state['owner'],'username'=>'Рисующий'],0);
};
try {
    $changed=drawingEnqueue($state,'сцена до изменения');$source=(int)$queue->drawingRow($changed)['data']['message_id'];
    (new Domain\ChatManager())->editMessage($source,$state['owner'],'/нарисуй изменённая сцена');
    $images=$traceCount('image');drawingFinish(drawingStart('tick'));
    check($queue->drawingRow($changed)['status']==='failed'&&$traceCount('image')===$images,'edited queued source is rejected before handoff and any image transport');
    $source=(new Domain\ChatManager())->addMessage($state['other'],'Другой','/нарисуй чужой источник');
    $foreign=$queue->enqueue('dynamic_command',['command'=>$state['image'],'message'=>'/нарисуй чужой источник','message_id'=>$source,'user_id'=>$state['owner'],'username'=>'Рисующий'],0);
    drawingFinish(drawingStart('tick'));
    check($queue->drawingRow($foreign)['status']==='failed'&&$traceCount('image')===$images,'drawing admission rejects a source belonging to another actor');
    // Reaper wins against a still-running process: its late image cannot commit or publish.
    drawingFixture('block');$id=drawingEnqueue($state,'устаревшая lease');$before=LLM\ImageGenerator::todayCount();
    $a=drawingStart('tick');$children[]=$a;drawingWait($dir.'/entered');
    $images=$traceCount('image');drawingFinish(drawingStart('prepare',$id));
    check($traceCount('image')===$images,'second real prepare process cannot enter the same image transport');
    $db->query('UPDATE llm_jobs SET claimed_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 601 SECOND) WHERE id='.$id);
    drawingFinish(drawingStart('recover'));drawingFixture('release');drawingFinish($a);array_pop($children);
    check($queue->drawingRow($id)['status']==='failed' && !drawingInspect($state)['pictures'],'late response after stale reaper cannot publish');
    check(LLM\ImageGenerator::todayCount()===$before,'stale response does not increment successful logical job count');

    // Disable AI after the first transient HTTP attempt began, before a retry can start.
    drawingFixture('block');$editState(['fail_image'=>true]);$id=drawingEnqueue($state,'выключение между попытками');$images=$traceCount('image');
    $a=drawingStart('tick');$children[]=$a;drawingWait($dir.'/entered');$cfg->setOption('ai_enabled','0');
    drawingFixture('release');drawingFinish($a);array_pop($children);
    check($traceCount('image')===$images+1 && $queue->drawingRow($id)['status']==='failed','fresh AI disable prevents second image attempt');
    $cfg->setOption('ai_enabled','1');$editState(['fail_image'=>false]);

    // A persisted image before accounting/attribution is recoverable exactly once.
    $id=drawingEnqueue($state,'окно сохранения результата');drawingFinish(drawingStart('tick'));
    $row=$queue->drawingRow($id);$url=$row['data']['drawing_v1']['image_url'];$day=$row['data']['drawing_v1']['generated_day'];
    (new Core\FileCache('imagegen'))->updateAtomic($day,static function(array $row)use($id):array{unset($row['receipts']['drawing_'.$id]);$row['count']--;return $row;});
    $queue->changeDrawing($id,static function(array $row):array{unset($row['data']['drawing_v1']['counted']);return ['data'=>$row['data']];});
    $q=$db->prepare('DELETE FROM privacy_files WHERE path=?');$q->bind_param('s',$url);$q->execute();
    $db->query('UPDATE llm_jobs SET claimed_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 601 SECOND) WHERE id='.$id);
    $images=$traceCount('image');$before=LLM\ImageGenerator::todayCount();drawingFinish(drawingStart('recover'));drawingFinish(drawingStart('deliver',$id));drawingFinish(drawingStart('recover'));
    check($traceCount('image')===$images && LLM\ImageGenerator::todayCount()===$before+1,'URL-before-count recovery accounts once without image transport');
    $q=$db->prepare('SELECT user_id FROM privacy_files WHERE path=?');$q->bind_param('s',$url);$q->execute();
    check((int)($q->get_result()->fetch_row()[0]??0)===$state['owner'],'recovered saved image retains attributable privacy ownership');

    // Source edit after preparation but before publication fences the exact saved outcome.
    $id=drawingEnqueue($state,'источник меняется перед публикацией');drawingFinish(drawingStart('tick'));$row=$queue->drawingRow($id);
    (new Domain\ChatManager())->editMessage((int)$row['data']['message_id'],$state['owner'],'передумал');
    $pictures=count(drawingInspect($state)['pictures']);drawingFinish(drawingStart('deliver',$id));
    check($queue->drawingRow($id)['status']==='failed' && count(drawingInspect($state)['pictures'])===$pictures,'edited source prevents saved drawing publication');

    // Execute the separate safety-rewrite path, including reference preservation and stage renewals.
    $source=(new Domain\ChatManager())->addMessage($state['owner'],'Рисующий','/нарисуй исправь деталь синтетического рисунка',[drawingInspect($state)['pictures'][0]]);
    $safe=$queue->enqueue('dynamic_command',['command'=>$state['image'],'message'=>'/нарисуй исправь деталь синтетического рисунка','message_id'=>$source,
        'user_id'=>$state['owner'],'username'=>'Рисующий','drawing_request'=>['operation'=>'fixture_safety_edit','reference_url'=>$url]],0);
    $editState(['safety_remaining'=>1,'age_before_safety'=>true]);$images=$traceCount('image');$texts=$traceCount('text');
    drawingFinish(drawingStart('tick'));$safeRow=$queue->drawingRow($safe);
    check($traceCount('image')===$images+2&&$traceCount('text')===$texts+1,'native safety pipeline stops original transport, rewrites once and generates safe image '.json_encode(['images'=>$traceCount('image')-$images,'texts'=>$traceCount('text')-$texts,'status'=>$safeRow['status'],'outcome'=>$safeRow['data']['drawing_v1']['terminal_code']??$safeRow['data']['drawing_v1']['phase']??'none']));
    check(str_starts_with($safeRow['data']['drawing_v1']['safe_prompt']??'','Edit the provided image.')&&($safeRow['data']['drawing_v1']['reference']??null)===$url,'safe retry preserves the accepted reference edit contract');
    $trace=array_map(static fn($line)=>json_decode($line,true),file($dir.'/trace.jsonl',FILE_IGNORE_NEW_LINES));$stages=array_values(array_filter($trace,static fn($r)=>($r['job']??0)===$safe));
    $phases=array_column($stages,'phase');$versions=array_column($stages,'version');
    check($phases===['image','safe_rewrite','safe_image']&&$versions[0]<$versions[1]&&$versions[1]<$versions[2],'real pipeline records separate fenced stage versions');
    check(count($stages)>=3&&time()-strtotime($stages[1]['claimed_at'].' UTC')<10&&time()-strtotime($stages[2]['claimed_at'].' UTC')<10,'stage transition refreshes a deliberately 599-second-old claimed_at before the next provider call');
    drawingFinish(drawingStart('deliver',$safe));$editState(['age_before_safety'=>false]);

    // Saved outcomes still require current sender rights and an enabled command at delivery.
    foreach(['ban','command_disable']as$mutation){
        $id=drawingEnqueue($state,'проверка прав '.$mutation);drawingFinish(drawingStart('tick'));
        $pictures=count(drawingInspect($state)['pictures']);
        try{
            if($mutation==='ban')(new Domain\UserManager())->banUser($state['owner'],'Синтетическая проверка');
            else$db->query('UPDATE bot_commands SET is_active=0 WHERE id='.(int)$state['image']['id']);
            drawingFinish(drawingStart('deliver',$id));
            check($queue->drawingRow($id)['status']==='failed'&&count(drawingInspect($state)['pictures'])===$pictures,'current '.$mutation.' prevents publication of an already saved drawing');
        }finally{
            if($mutation==='ban')(new Domain\UserManager())->unbanUser($state['owner']);
            else$db->query('UPDATE bot_commands SET is_active=1 WHERE id='.(int)$state['image']['id']);
        }
    }

    // Context snapshot races pause only after the real parent buildContext returned.
    foreach(['edit','delete','withdraw']as$mutation){
        $cfg->setOption('bot_last_manual_draw','0');
        for($i=0;$i<12;$i++)(new Domain\ChatManager())->addMessage($state['owner'],'Рисующий','Синтетический контекст '.$mutation.' '.$i);
        $source=(new Domain\ChatManager())->addMessage($state['other'],'Другой','Синтетическая тема контекста '.$mutation);
        $auto=$queue->enqueue('dynamic_command',['command'=>$state['image_chat'],'message'=>'/нарисуйчат','auto'=>true],0);
        @unlink($dir.'/context_entered');@unlink($dir.'/context_release');
        $calls=$traceCount('text')+$traceCount('image');$a=drawingStart('context-probe');$children[]=$a;drawingWait($dir.'/context_entered');
        if($mutation==='edit')(new Domain\ChatManager())->editMessage($source,$state['other'],'Изменённая синтетическая тема');
        elseif($mutation==='delete')$db->query('UPDATE chat_messages SET is_deleted=1,edited_at=UTC_TIMESTAMP() WHERE id='.$source);
        else {Domain\Privacy::setAccountConsent($state['other'],false);Domain\Privacy::unlockAccount($state['other']);}
        file_put_contents($dir.'/context_release','1');drawingFinish($a);array_pop($children);drawingFinish(drawingStart('deliver',$auto));
        check($traceCount('text')+$traceCount('image')===$calls && empty($queue->drawingRow($auto)['data']['drawing_v1']['image_message_id']),'context '.$mutation.' between snapshot and binding sends no stale text to director');
        if($mutation==='withdraw')Domain\Privacy::setAccountConsent($state['other'],true);
    }

    // A manual request arriving during auto caption generation wins at the final INSERT guard.
    $cfg->setOption('bot_last_manual_draw','0');$cfg->setOption('ai_image_llm_caption','1');
    for($i=0;$i<12;$i++)(new Domain\ChatManager())->addMessage($state['owner'],'Рисующий','Тема перед проверкой подписи '.$i);
    $auto=$queue->enqueue('dynamic_command',['command'=>$state['image_chat'],'message'=>'/нарисуйчат','auto'=>true],0);
    drawingFinish(drawingStart('tick'));$pictures=count(drawingInspect($state)['pictures']);
    @unlink($dir.'/caption_entered');@unlink($dir.'/caption_release');$editState(['block_caption'=>true]);
    $a=drawingStart('deliver',$auto);$children[]=$a;drawingWait($dir.'/caption_entered');
    $manual=drawingEnqueue($state,'ручной приоритет во время подписи');file_put_contents($dir.'/caption_release','1');
    drawingFinish($a);array_pop($children);$editState(['block_caption'=>false]);
    check(empty($queue->drawingRow($auto)['data']['drawing_v1']['image_message_id'])&&count(drawingInspect($state)['pictures'])===$pictures,'manual arrival during caption suppresses auto at the actual publication transaction');
    check($queue->drawingRow($manual)['status']==='pending','manual priority request remains available after suppressed auto');
    $db->query("UPDATE llm_jobs SET status='failed' WHERE id=".$manual);$cfg->setOption('ai_image_llm_caption','0');

    // Wishes are a separate input stream, including authors outside the main context/hints.
    foreach(['delete','withdraw']as$mutation){
        drawingEnqueue($state,'сбор пожеланий '.$mutation,'image_chat');drawingFinish(drawingStart('tick'));
        $child=(int)$db->query("SELECT MAX(id) FROM llm_jobs WHERE JSON_EXTRACT(payload,'$.drawing_chat_window') IS NOT NULL")->fetch_row()[0];
        $childRow=$queue->drawingRow($child);$window=$childRow['data']['drawing_chat_window'];
        $wish=(new Domain\ChatManager())->addMessage($state['other'],'Другой','Особое синтетическое пожелание '.$mutation,[(int)$window['message_id']]);
        for($i=0;$i<16;$i++)(new Domain\ChatManager())->addMessage($state['owner'],'Рисующий','Новое сообщение после пожелания '.$mutation.' '.$i);
        $db->query('UPDATE llm_jobs SET run_after=UTC_TIMESTAMP() WHERE id='.$child);drawingFixture('block');
        $a=drawingStart('tick-virtual');$children[]=$a;drawingWait($dir.'/entered');$row=$queue->drawingRow($child);
        check(in_array($wish,array_column($row['data']['drawing_v1']['bindings'],'id'),true),'wish outside the main ten-message context has its own snapshot binding '.$mutation);
        $pictures=count(drawingInspect($state)['pictures']);
        if($mutation==='delete')$db->query('UPDATE chat_messages SET is_deleted=1,edited_at=UTC_TIMESTAMP(),conversation_revision=conversation_revision+1 WHERE id='.$wish);
        else{Domain\Privacy::setAccountConsent($state['other'],false);Domain\Privacy::unlockAccount($state['other']);}
        drawingFixture('release');drawingFinish($a);array_pop($children);drawingFinish(drawingStart('deliver',$child));
        $row=$queue->drawingRow($child);
        check((!$row||empty($row['data']['drawing_v1']['image_message_id']))&&count(drawingInspect($state)['pictures'])===$pictures,'changed wish outside main context prevents publication '.$mutation);
        if($mutation==='withdraw')Domain\Privacy::setAccountConsent($state['other'],true);
    }

    // Queue-off delivery owns its progress after a temporary worker lock, without future requests.
    $cfg->setOption('ai_use_queue','0');$cfg->setOption('ai_worker_mode','inline');
    foreach(['image','image_chat']as$handler){
        drawingFixture('block');$id=$inlineJob($handler);$a=drawingStart($handler==='image_chat'?'inline-virtual':'inline',$id);$children[]=$a;
        drawingWait($dir.'/entered');
        check((int)$peer->query("SELECT IS_FREE_LOCK('bot_worker') n")->fetch_row()[0]===1,'inline '.$handler.' prepares outside voice lock, including collection continuation');
        check((int)$peer->query("SELECT GET_LOCK('bot_worker',0) n")->fetch_row()[0]===1,'independent connection takes voice lock during inline '.$handler.' generation');
        drawingFixture('release');
        $actualId=$id;
        if($handler==='image_chat')$actualId=(int)$db->query("SELECT MAX(id) FROM llm_jobs WHERE JSON_EXTRACT(payload,'$.drawing_chat_window') IS NOT NULL")->fetch_row()[0];
        drawingWaitPhase($actualId,'deliver');$pictures=count(drawingInspect($state)['pictures']);
        $peer->query("SELECT RELEASE_LOCK('bot_worker')");drawingFinish($a);array_pop($children);
        check($queue->drawingRow($actualId)['status']==='done' && count(drawingInspect($state)['pictures'])===$pictures+1,'original inline '.$handler.' request delivers after lock release without a subsequent request or cron');
    }

    $id=$inlineJob();drawingFinish(drawingStart('prepare-inline-only',$id));
    check((int)$peer->query("SELECT GET_LOCK('bot_worker',0) n")->fetch_row()[0]===1,'timeout fixture holds voice lock');
    drawingFinish(drawingStart('inline-timeout',$id));$peer->query("SELECT RELEASE_LOCK('bot_worker')");
    $row=$queue->drawingRow($id);
    check($row['status']==='failed' && ($row['data']['drawing_v1']['terminal_code']??'')==='delivery_lock_timeout','inline delivery exhaustion is terminal and frees the logical drawing slot '.json_encode(['status'=>$row['status'],'phase'=>$row['data']['drawing_v1']['phase']??null,'code'=>$row['data']['drawing_v1']['terminal_code']??null]));

    // The actual single daemon remains sequential but advances from preparation to delivery.
    $cfg->setOption('ai_use_queue','1');$cfg->setOption('ai_worker_mode','daemon');drawingFixture('block');
    $daemonDrawing=drawingEnqueue($state,'одиночный демон');$a=drawingStart('daemon');$children[]=$a;drawingWait($dir.'/entered');
    $source=(new Domain\ChatManager())->addMessage($state['owner'],'Рисующий','@Лаборатория, синтетический вопрос демону');
    $mention=$queue->enqueue('mention',['message_id'=>$source,'user_id'=>$state['owner'],'username'=>'Рисующий','message'=>'@Лаборатория, синтетический вопрос демону'],0);
    check($queue->drawingRow($mention)['status']==='pending','single daemon does not falsely promise a parallel reply while its preparation is held');
    drawingFixture('release');$deadline=microtime(true)+12;
    do{if($queue->drawingRow($daemonDrawing)['status']==='done'&&$queue->drawingRow($mention)['status']==='done')break;usleep(20000);}while(microtime(true)<$deadline);
    check($queue->drawingRow($daemonDrawing)['status']==='done'&&$queue->drawingRow($mention)['status']==='done','actual daemon loop resumes and delivers both drawing and chat reply on subsequent tick');
    proc_terminate($a[0],9);foreach($a[1]as$pipe)fclose($pipe);proc_close($a[0]);array_pop($children);
    $cfg->setOption('ai_use_queue','0');$cfg->setOption('ai_worker_mode','inline');

    // Real OC generation and its post-picture announcement both finish without a cron consumer.
    $cfg->setOption('ai_memory_enabled','1');$cfg->setOption('ai_online_in_context','1');$cfg->setOption('ai_oc_auto','1');
    (new Domain\OnlineManager())->beat('it_mlp468_oc_'.$state['owner'],$state['owner'],'127.0.0.1','synthetic drawing');
    $id=$inlineJob('image_chat');drawingFinish(drawingStart('inline-virtual',$id));
    $childId=(int)$db->query("SELECT MAX(id) FROM llm_jobs WHERE JSON_EXTRACT(payload,'$.drawing_chat_window') IS NOT NULL")->fetch_row()[0];
    $row=$queue->drawingRow($childId);$ocJobs=$row['data']['drawing_v1']['oc_jobs']??[];
    check(!empty($ocJobs) && !empty($row['data']['drawing_v1']['oc_created']),'native inline chat drawing actually creates and persists an OC');
    $all=true;foreach($ocJobs as$ocId){$oc=$queue->drawingRow((int)$ocId);$all=$all&&$oc['status']==='done'&&(int)($oc['data']['reply_message_id']??0)>(int)$row['data']['drawing_v1']['image_message_id'];}
    check($all && $ocJobs,'inline request drains only its post-picture OC receipts without cron');
    $before=count(drawingInspect($state)['messages']);drawingFinish(drawingStart('inline-deliver',$childId));
    check(count(drawingInspect($state)['messages'])===$before,'inline OC drain replay never duplicates picture or announcements');
    $ownedSource=(new Domain\ChatManager())->addMessage($state['other'],'Другой','Синтетический источник для удаления');
    $bound=$queue->enqueue('dynamic_command',['user_id'=>$state['owner'],'command'=>$state['image'],'drawing_v1'=>['bindings'=>[['id'=>$ownedSource,'user_id'=>$state['other']]],'users'=>[]]],0);
    $hint=$queue->enqueue('dynamic_command',['user_id'=>$state['owner'],'command'=>$state['image'],'drawing_v1'=>['bindings'=>[],'users'=>[$state['other']]]],0);
    $unrelated=$queue->enqueue('dynamic_command',['user_id'=>$state['owner'],'command'=>$state['image'],'message'=>'Независимая синтетическая команда'],0);
    $queue->eraseUserData($state['other'],[$ownedSource]);
    check($queue->drawingRow($bound)===null && $queue->drawingRow($hint)===null && $queue->drawingRow($unrelated)!==null,'account erasure removes new source/hint-bound drawing payloads while preserving unrelated actor jobs');
} finally {
    $peer->query("SELECT RELEASE_LOCK('bot_worker')");$peer->close();drawingFixture('release');file_put_contents($dir.'/context_release','1');
    file_put_contents($dir.'/caption_release','1');
    foreach($children as$child){proc_terminate($child[0],9);foreach($child[1]as$pipe)if(is_resource($pipe))fclose($pipe);proc_close($child[0]);}
    drawingFixture('cleanup');
}
ob_end_flush();it_done();
