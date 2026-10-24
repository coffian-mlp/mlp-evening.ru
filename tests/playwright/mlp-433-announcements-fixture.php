<?php
require_once dirname(__DIR__).'/integration_helpers.php';
if (PHP_SAPI!=='cli' || !is_file('/.dockerenv') || (it_config()['db']['host']??'')!=='db') throw new RuntimeException('Isolated Docker database required');
$root=dirname(__DIR__,2);$db=it_require_db();$mode=$argv[1]??'';
$path=$root.'/docs/private/mlp433-local.json';$journal=$root.'/logs/announcements.log';
if ($mode==='setup') {
    if (is_file($path)) throw new RuntimeException('Cleanup previous fixture first');
    $campaign=json_decode(file_get_contents($root.'/docs/private/mlp426-local.json'),true,512,JSON_THROW_ON_ERROR)['campaign'];
    $state=['campaign'=>(int)$campaign,'image'=>'/upload/lyra/mlp433_'.bin2hex(random_bytes(6)).'.jpg','backups'=>[]];
    foreach ([$journal,$journal.'.1',$journal.'.lock'] as $file) {
        if (file_exists($file.'.mlp433-backup')) throw new RuntimeException('Unexpected backup file');
        $state['backups'][$file]=file_exists($file);
    }
    file_put_contents($path,json_encode($state,JSON_THROW_ON_ERROR));chmod($path,0600);
    foreach ($state['backups'] as $file=>$exists) if ($exists) rename($file,$file.'.mlp433-backup');
    if (!is_dir($root.'/upload/lyra')) mkdir($root.'/upload/lyra',0755,true);
    copy($root.'/assets/img/default-avatar.png',$root.$state['image']);
    foreach ($db->query('SELECT r.*,p.kind,c.stream_number,c.start_at FROM telegram_announcement_revisions r JOIN telegram_announcement_posts p ON p.current_revision=r.id JOIN telegram_announcement_campaigns c ON c.id=p.campaign_id WHERE c.id='.(int)$campaign)->fetch_all(MYSQLI_ASSOC) as $r) {
        $start=strtotime($r['start_at'].' UTC');
        $date=(new DateTimeImmutable('@'.$start))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('d.m.Y H:i');
        $caption=LLM\AnnouncementContent::caption("Смотрим пони! $date МСК\nhttps://mlp-evening.ru/",$r['kind'],(int)$r['stream_number'],$start);
        if (!$caption) throw new RuntimeException('Invalid fixture caption');
        $status=$r['kind']==='main'?'approved':'pending';
        $approved=$r['kind']==='main'?gmdate('Y-m-d H:i:s'):null;
        $q=$db->prepare('UPDATE telegram_announcement_revisions SET caption=?,image_path=?,state=?,approved_at=?,last_error=NULL,retry_at=NULL,attempts=0 WHERE id=?');
        $q->bind_param('ssssi',$caption,$state['image'],$status,$approved,$r['id']);$q->execute();
        if ($r['kind']==='main') $main=$r;
    }
    $log=new Infra\AnnouncementLog();
    $log->record('poll_failed',[],new Social\TelegramBotException('private-fixture-secret',false,['reason'=>'rate_limit','http_status'=>429]));
    rename($journal,$journal.'.1');
    $log->record('delivery_failed',$main,new Social\TelegramBotException('private-fixture-secret',true,['reason'=>'network','curl_errno'=>28]),['stage'=>'sending','next_state'=>'uncertain','attempts'=>1]);
    $log->record('channel_sent',$main,null,['message_id'=>123]);
    file_put_contents($journal,json_encode(['at'=>gmdate('Y-m-d\TH:i:s\Z'),'event'=>'unknown','caption'=>'private-fixture-secret','reason'=>'<img src=x onerror=alert(1)>'])."\n",FILE_APPEND);
    echo "Fixture ready\n";
} elseif ($mode==='inspect') {
    $state=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    $r=$db->query('SELECT r.id,r.version,r.state,r.caption,r.image_path,r.approved_at FROM telegram_announcement_revisions r JOIN telegram_announcement_posts p ON p.current_revision=r.id WHERE p.campaign_id='.(int)$state['campaign'].' AND p.kind="main"')->fetch_assoc();
    $r['requests']=(int)$db->query('SELECT COUNT(*) FROM llm_usage')->fetch_row()[0];
    echo json_encode($r,JSON_THROW_ON_ERROR);
} elseif ($mode==='cleanup') {
    $state=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    if (is_file($root.$state['image'])) unlink($root.$state['image']);
    foreach ($state['backups'] as $file=>$exists) {
        if (is_file($file)) unlink($file);
        if ($exists && file_exists($file.'.mlp433-backup')) rename($file.'.mlp433-backup',$file);
    }
    unlink($path);echo "Fixture removed\n";
} else throw new RuntimeException('Use setup, inspect or cleanup');
