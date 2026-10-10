<?php
require_once __DIR__.'/integration_helpers.php';
if ((it_config()['db']['host'] ?? '') !== 'db') it_skip('requires isolated Docker database');
$db=\Infra\Database::getInstance()->getConnection();
$db->begin_transaction();
try {
    $queue=new \LLM\JobQueue();
    $run='fixture383_'.bin2hex(random_bytes(8));
    $payload=['event_run_id'=>$run,'schedule_announcement'=>'playlist_finished','command'=>['handler_type'=>'schedule']];
    $id=$queue->enqueueAnnouncement($payload);
    check($id===$queue->enqueueAnnouncement($payload),'enqueue/marker interruption does not duplicate job');
    $queue->release([$id],120);
    $row=$db->query('SELECT status,attempts,TIMESTAMPDIFF(SECOND,NOW(),run_after) AS delay FROM llm_jobs WHERE id='.(int)$id)->fetch_assoc();
    check($row['status']==='pending' && (int)$row['attempts']===1 && (int)$row['delay']>=119,'failure persists pending delivery and backoff');
    $db->query("UPDATE llm_jobs SET status='processing',claimed_at=DATE_SUB(NOW(),INTERVAL 15 MINUTE) WHERE id=".(int)$id);
    $queue->recoverStaleAnnouncements();
    $queue->failStaleAny(\LLM\JobQueue::REACTIVE_TYPES,600);
    $row=$db->query('SELECT status,attempts FROM llm_jobs WHERE id='.(int)$id)->fetch_assoc();
    check($row['status']==='pending' && (int)$row['attempts']===2,'interrupted delivery survives ordinary stale reaper');
    $queue->complete([$id]);
    check($id===$queue->enqueueAnnouncement($payload),'delivered announcement not re-enqueued after marker interruption');
    $payload['schedule_announcement']='15m';
    $other=$queue->enqueueAnnouncement($payload);
    check($other!==$id,'different announcement stage has independent job');
} finally { $db->rollback(); }
it_done();
