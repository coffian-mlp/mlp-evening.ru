<?php
require __DIR__.'/integration_helpers.php';
it_require_db();
if (!is_file(dirname(__DIR__).'/.mlp468-owned')) it_skip('Requires drawing_environment.sh synthetic laboratory');
require __DIR__.'/helpers/drawing_fixture.php';
require __DIR__.'/helpers/drawing_process.php';
$state=drawingFixture('setup'); $db=Infra\Database::getInstance()->getConnection(); $processes=[];
try {
    if(!class_exists(LLM\DrawingJob::class)) {
        $dir=dirname(__DIR__).'/cache/mlp468';drawingFixture('block');drawingEnqueue($state,'исходная блокирующая генерация');
        $a=drawingStart('tick');$processes[]=$a;drawingWait($dir.'/entered');
        $source=(new Domain\ChatManager())->addMessage($state['owner'],'Рисующий','@Лаборатория, ответь пока рисуешь');
        (new LLM\JobQueue())->enqueue('mention',['message_id'=>$source,'user_id'=>$state['owner'],'username'=>'Рисующий','message'=>'@Лаборатория, ответь пока рисуешь'],0);
        drawingFinish(drawingStart('tick'));
        $replies=array_filter(drawingInspect($state)['messages'],static fn(array $message):bool=>str_contains($message['text'],'отвечаю, пока рисую'));
        check((int)$db->query("SELECT IS_FREE_LOCK('bot_worker') n")->fetch_row()[0]===1 && $replies,'baseline real second process must publish while image barrier remains held');
        drawingFixture('release');drawingFinish($a);array_pop($processes);
    }
    else {
        $dir=dirname(__DIR__).'/cache/mlp468'; $queue=new LLM\JobQueue();
        drawingFixture('block'); $first=drawingEnqueue($state,'замок');
        $a=drawingStart('tick'); $processes[]=$a; drawingWait($dir.'/entered');
        $row=$queue->drawingRow($first);
        check($row['status']==='processing' && $row['data']['drawing_v1']['phase']==='image','drawing is leased in image stage');
        check((int)$db->query("SELECT IS_FREE_LOCK('bot_worker') n")->fetch_assoc()['n']===1,'generator releases the real bot_worker lock');
        $second=drawingEnqueue($state,'воздушный шар');
        $db->query('UPDATE llm_jobs SET attempts=127 WHERE id='.$second);
        $source=(new Domain\ChatManager())->addMessage($state['owner'],'Рисующий','@Лаборатория, ответь пока рисуешь');
        $mention=$queue->enqueue('mention',['message_id'=>$source,'user_id'=>$state['owner'],'username'=>'Рисующий','message'=>'@Лаборатория, ответь пока рисуешь'],0);
        drawingFinish(drawingStart('tick'));
        $inspect=drawingInspect($state);
        $replies=array_filter($inspect['messages'],static fn(array $message): bool => str_contains($message['text'],'отвечаю, пока рисую'));
        check(!$inspect['pictures'] && $replies && $queue->drawingRow($mention)['status']==='done','real second process publishes the actual expected chat reply before image barrier release');
        $notice=$queue->drawingRow($second)['data']['drawing_v1']['busy_notice_message_id'] ?? 0;
        check($notice>0 && $queue->drawingRow($second)['status']==='pending','second drawing has one addressed busy receipt and remains queued');
        check((int)$queue->drawingRow($second)['attempts']===127,'long drawing contention does not consume or overflow tinyint attempt budget');
        $db->query('UPDATE llm_jobs SET run_after=UTC_TIMESTAMP() WHERE id='.$second);
        drawingFinish(drawingStart('tick'));
        check(($queue->drawingRow($second)['data']['drawing_v1']['busy_notice_message_id'] ?? 0)===$notice,'repeated tick does not duplicate busy speech');
        drawingFixture('release'); drawingFinish($a); array_pop($processes);
        check($queue->drawingRow($first)['data']['drawing_v1']['phase']==='deliver','prepared result awaits serialized delivery');
        $legacy=json_decode(drawingFinish(drawingStart('legacy-claim')),true,512,JSON_THROW_ON_ERROR);
        check(!in_array($first,$legacy,true) && $queue->drawingRow($first)['status']==='processing','archived real legacy claimDue cannot take staged drawing');
        drawingFinish(drawingStart('deliver',$first)); drawingFinish(drawingStart('deliver',$first));
        $row=$queue->drawingRow($first); $image=$row['data']['drawing_v1']['image_message_id'] ?? 0;
        check($row['status']==='done' && $image>0 && drawingInspect($state)['pictures']===[$image],'delivery inserts one exact image receipt even on replay');
        check((int)Infra\ConfigManager::getInstance()->getOptionDetails('bot_last_manual_draw')['value']>0,'manual cooldown changes after committed image publication');
        // Finish the ordinary second request, then exercise a real process death.
        $db->query("UPDATE llm_jobs SET status='pending',run_after=UTC_TIMESTAMP() WHERE id=".$second);
        drawingFinish(drawingStart('tick')); drawingFinish(drawingStart('deliver'));
        drawingFixture('block'); $crash=drawingEnqueue($state,'рисунок перед обрывом');
        $a=drawingStart('tick'); $processes[]=$a; drawingWait($dir.'/entered');
        proc_terminate($a[0],9); foreach($a[1] as $pipe)fclose($pipe);proc_close($a[0]);array_pop($processes);
        $db->query('UPDATE llm_jobs SET claimed_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 601 SECOND) WHERE id='.$crash);
        drawingFinish(drawingStart('recover'));
        check($queue->drawingRow($crash)['status']==='failed' && (int)$db->query("SELECT IS_FREE_LOCK('bot_drawing') n")->fetch_assoc()['n']===1,'killed process releases drawing lock; reaper fails unknown upstream outcome');
        drawingFixture('release');
        // Persisted URL survives a crash after generation, including legacy failed recovery.
        $recovery=drawingEnqueue($state,'сохранённый результат'); drawingFinish(drawingStart('tick'));
        $row=$queue->drawingRow($recovery); $saved=$row['data']['drawing_v1']['image_url'];
        $db->query("UPDATE llm_jobs SET status='failed',claimed_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 601 SECOND) WHERE id=".$recovery);
        $before=LLM\ImageGenerator::todayCount(); drawingFinish(drawingStart('recover')); drawingFinish(drawingStart('deliver',$recovery));
        check($queue->drawingRow($recovery)['status']==='done' && $queue->drawingRow($recovery)['data']['drawing_v1']['image_url']===$saved,'legacy reaper failure recovers saved image without generation');
        check(LLM\ImageGenerator::todayCount()===$before,'recovery does not double-count a saved image');
        $count=LLM\ImageGenerator::todayCount(); $a=drawingStart('counter',901);$b=drawingStart('counter',902);drawingFinish($a);drawingFinish($b);drawingFinish(drawingStart('counter',901));
        check(LLM\ImageGenerator::todayCount()===$count+2,'concurrent atomic count updates and idempotency preserve both successes');
        $cfg=Infra\ConfigManager::getInstance(); $cfg->setOption('bot_last_manual_draw','0');
        for($i=0;$i<12;$i++)(new Domain\ChatManager())->addMessage($state['owner'],'Рисующий','Синтетическая тема для авторисунка '.$i);
        $auto=$queue->enqueue('dynamic_command',['command'=>$state['image_chat'],'message'=>'/нарисуйчат','auto'=>true],0);
        drawingFinish(drawingStart('tick')); drawingFinish(drawingStart('deliver',$auto));
        $row=$queue->drawingRow($auto);$autoMessage=(int)($row['data']['drawing_v1']['image_message_id'] ?? 0);
        $actual=$autoMessage ? (new Domain\ChatManager())->getMessageById($autoMessage) : null;
        check($row['status']==='done' && $actual && !str_contains($actual['raw_message'],'@'),'automatic pipeline publishes a real unaddressed image without actor zero rejection');
    }
} finally {
    drawingFixture('release');
    foreach($processes as $child) {proc_terminate($child[0],9);foreach($child[1] as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($child[0]);}
    drawingFixture('cleanup');
}
it_done();
