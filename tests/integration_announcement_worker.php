<?php
require_once __DIR__ . '/integration_helpers.php';
if ((it_config()['db']['host'] ?? '') !== 'db') it_skip('requires isolated Docker database');
it_require_db()->close();
$db = Infra\Database::getInstance()->getConnection();
if (!$db->query("SHOW TABLES LIKE 'telegram_announcement_campaigns'")->num_rows) it_skip('run announcement store integration migration first');
if ($db->query('SELECT id FROM telegram_announcement_campaigns LIMIT 1')->num_rows) it_skip('requires no other announcement campaigns');

class FixtureAnnouncementContent375 extends LLM\AnnouncementContent {
    public int $variant = 1;
    public bool $throwText = false;
    public array $generations = [];
    public function __construct(public string $path) {}
    public function editorText(string $fallback): string { return $fallback; }
    public function facts(array $occurrence, int $now): ?array {
        return ['occurrence'=>$occurrence, 'snapshot'=>['id'=>$this->variant,'stories'=>[['TITLE'=>'Fixture episode version '.$this->variant]]]];
    }
    public function generateText(array $facts, string $kind, int $number, string $feedback = '', ?string $previous = null): ?string {
        $this->generations[] = [$kind,$feedback,$previous];
        if ($this->throwText) throw new RuntimeException('fixture generation failure');
        return ($kind==='main'?'#'.$number:'Notificatio '.self::roman($number)).' fixture '.$this->variant.' '.$feedback;
    }
    public function generateImage(array $facts, string $kind, string $caption, string $feedback = ''): ?string { return $this->path; }
}

$owner = 123456789; $channel = '@fixture375';
$updates = []; $calls = []; $updateIds = [];
$nextUpdate = random_int(1000000000, 1900000000); $nextMessage = 500;
$channelMode = 'ok';
$offset = $db->query('SELECT value FROM site_options WHERE key_name="announcements_update_offset"')->fetch_assoc();
$counter = $db->query('SELECT value FROM site_options WHERE key_name="announcements_counter"')->fetch_assoc();
$heartbeat = $db->query('SELECT value FROM site_options WHERE key_name="announcements_heartbeat"')->fetch_assoc();
$directory = dirname(__DIR__).'/upload/lyra'; $madeDirectory = !is_dir($directory);
if ($madeDirectory) mkdir($directory,0755,true);
$basename = 'worker375_'.bin2hex(random_bytes(8)).'.jpg';
$file = $directory.'/'.$basename; file_put_contents($file,'fixture image');
$content = new FixtureAnnouncementContent375('/upload/lyra/'.$basename);
$store = new Domain\AnnouncementStore($db);
$telegram = new Social\TelegramBotClient('123456789:'.str_repeat('a',35), static function ($method,$params) use (&$updates,&$calls,&$nextMessage,&$channelMode,$channel) {
    $calls[] = [$method,$params];
    if ($method==='getUpdates') return ['status'=>200,'body'=>json_encode(['ok'=>true,'result'=>$updates])];
    if ($method==='answerCallbackQuery') return ['status'=>200,'body'=>'{"ok":true,"result":true}'];
    if (($params['chat_id'] ?? null)===$channel) {
        if ($channelMode==='429') return ['status'=>429,'body'=>'{"ok":false,"error_code":429}'];
        if ($channelMode==='timeout') return ['status'=>0,'body'=>'','errno'=>28];
    }
    return ['status'=>200,'body'=>json_encode(['ok'=>true,'result'=>['message_id'=>$nextMessage++]])];
});
$start = (new DateTimeImmutable('2030-01-05 19:00:00',new DateTimeZone('Europe/Moscow')))->getTimestamp();
$wed = $start-3*86400; $thurs = $start-2*86400; $satReminder = $start-10800;
$eventId = 0;
$channelCalls = static function () use (&$calls,$channel): int { return count(array_filter($calls,static fn($c)=>$c[0]==='sendPhoto' && ($c[1]['chat_id'] ?? null)===$channel)); };
$rows = static function () use ($db): array {
    return array_column($db->query('SELECT r.* ,p.kind FROM telegram_announcement_revisions r JOIN telegram_announcement_posts p ON p.current_revision=r.id WHERE p.campaign_id=(SELECT MAX(id) FROM telegram_announcement_campaigns) ORDER BY p.kind')->fetch_all(MYSQLI_ASSOC),null,'kind');
};
$callback = static function (array $r, string $action='approve', ?int $sender=null, ?string $nonce=null) use (&$updates,&$updateIds,&$nextUpdate,$owner): array {
    $id = $nextUpdate++; $updateIds[]=$id;
    $update = ['update_id'=>$id,'callback_query'=>['id'=>'cb'.$id,'from'=>['id'=>$sender ?? $owner],'message'=>['message_id'=>(int)$r['preview_photo_id'],'chat'=>['id'=>$owner,'type'=>'private']],'data'=>'ann:'.$r['id'].':'.($nonce ?? $r['nonce']).':'.$action]];
    $updates=[$update]; return $update;
};
try {
    $stmt=$db->prepare('INSERT INTO events(title,description,start_time,duration_minutes,is_recurring,recurrence_rule,use_playlist,generate_new_playlist) VALUES(?,"fixture",?,240,1,"weekly",1,0)');
    $title='Announcement worker fixture '.bin2hex(random_bytes(8)); $date=gmdate('Y-m-d H:i:s',$start);$stmt->bind_param('ss',$title,$date);$stmt->execute();$eventId=(int)$db->insert_id;
    $worker = new LLM\AnnouncementWorker($store,$telegram,$content,$owner,$channel,$eventId,123);
    for ($i=0;$i<6;$i++) $worker->tick($wed);
    $r=$rows();
    check(count($r)===2 && $r['main']['state']==='pending' && $r['reminder']['state']==='pending','Wednesday prepares and privately previews both posts');
    check($channelCalls()===0,'unapproved previews never published to channel');
    $number=(int)$store->revision((int)$r['main']['id'])['stream_number'];
    check(str_starts_with($r['main']['caption'],'#'.$number) && str_starts_with($r['reminder']['caption'],'Notificatio '.LLM\AnnouncementContent::roman($number)),'one stream number has decimal and Roman representations');
    $callback($r['main'],'approve',$owner+1);$worker->tick($wed);
    check($rows()['main']['state']==='pending','outsider callback cannot approve');
    $callback($r['main'],'approve',null,str_repeat('f',16));$worker->tick($wed);
    check($rows()['main']['state']==='pending','forged nonce cannot approve');
    $callback($r['main']);$worker->tick($wed);
    check($rows()['main']['state']==='approved' && $channelCalls()===0,'owner approves for scheduled publication, not immediate send');
    $saved=$rows()['main'];$worker->tick($wed+1);
    check($rows()['main']['approved_at']===$saved['approved_at'],'duplicate Telegram update has no second action');
    $callback($rows()['main'],'ask_text');$worker->tick($wed+2);
    check($rows()['main']['state']==='feedback_text' && $rows()['main']['approved_at']===null,'mobile request for text edit removes approval');
    $updateId=$nextUpdate++;$updateIds[]=$updateId;
    $updates=[['update_id'=>$updateId,'message'=>['message_id'=>999,'from'=>['id'=>$owner],'chat'=>['id'=>$owner,'type'=>'private'],'text'=>'Shorter please','reply_to_message'=>['message_id'=>(int)$saved['preview_photo_id']]]]];
    $worker->tick($wed+3);$updates=[];$worker->tick($wed+4);
    $r=$rows();check($r['main']['state']==='pending' && (int)$r['main']['version']===2 && str_contains($r['main']['caption'],'Shorter please'),'reply feedback generates and previews new version');
    check($r['main']['image_path']===$saved['image_path'],'text-only regeneration retains approved image');
    $callback($saved);$worker->tick($wed+5);check($rows()['main']['state']==='pending','old keyboard cannot approve replacement');
    $callback($rows()['main']);$worker->tick($wed+6);
    $callback($rows()['reminder']);$worker->tick($wed+7);$updates=[];
    $content->variant=2;$worker->tick($thurs);
    check($channelCalls()===0 && $rows()['main']['approved_at']===null && $rows()['reminder']['approved_at']===null,'changed live programme invalidates both approvals before delivery');
    for($i=0;$i<6;$i++)$worker->tick($thurs+1);
    $callback($rows()['main']);$channelMode='429';$worker->tick($thurs+2);$updates=[];
    $rejected=$rows()['main'];$sent=$channelCalls();
    check($rejected['state']==='approved' && (int)$rejected['attempts']===1 && $rejected['retry_at']!==null,'definitive rejection schedules bounded retry');
    $worker->tick($thurs+3);
    check($channelCalls()===$sent,'429 backoff prevents immediate repeat send');
    $channelMode='ok';$worker->tick($thurs+400);
    check($rows()['main']['state']==='published' && $channelCalls()===$sent+1,'approved main publishes once after retry interval');
    $worker->tick($thurs+401);check($channelCalls()===$sent+1,'published main never resent');
    $callback($rows()['reminder']);$worker->tick($thurs+402);$updates=[];
    $channelMode='timeout';$worker->tick($satReminder);$ambiguousCalls=$channelCalls();
    check($rows()['reminder']['state']==='uncertain','ambiguous reminder delivery quarantined');
    $worker->tick($satReminder+1);$worker->tick($satReminder+600);
    check($channelCalls()===$ambiguousCalls,'uncertain delivery never blindly retried');
    $uncertainId=(int)$rows()['reminder']['id'];
    $command=static function (string $text) use (&$updates,&$updateIds,&$nextUpdate,$owner): void {
        $id=$nextUpdate++;$updateIds[]=$id;
        $updates=[['update_id'=>$id,'message'=>['message_id'=>1000,'from'=>['id'=>$owner],'chat'=>['id'=>$owner,'type'=>'private'],'text'=>$text]]];
    };
    $command('/sent '.$uncertainId.' 0');$worker->tick($satReminder+601);
    check($rows()['reminder']['state']==='uncertain','manual reconciliation rejects zero message identifier');
    $command('/sent '.$uncertainId.' 777');$worker->tick($satReminder+602);$updates=[];
    check($rows()['reminder']['state']==='published' && (int)$rows()['reminder']['channel_message_id']===777 && $channelCalls()===$ambiguousCalls,'owner reconciliation marks actual publication without resending');
    $content->throwText=true;
    $nextWed=$wed+7*86400;
    $mainGenerations=static function () use ($content): int { return count(array_filter($content->generations,static fn($g)=>$g[0]==='main')); };
    $beforeGenerations=$mainGenerations();
    $worker->tick($nextWed);
    $failedMain=$rows()['main'];
    check($failedMain['state']==='text_pending' && (int)$failedMain['attempts']===1 && strtotime($failedMain['retry_at'].' UTC')===$nextWed+600,'thrown text generation safely becomes timed retry');
    check($mainGenerations()===$beforeGenerations+1,'generation failure counted as one attempt');
    $worker->tick($nextWed+1);
    check($mainGenerations()===$beforeGenerations+1 && (int)$rows()['main']['attempts']===1,'generation backoff prevents immediate second attempt for same post');
} finally {
    if($eventId) {
        $campaignIds=array_column($db->query('SELECT id FROM telegram_announcement_campaigns WHERE run_id REGEXP "^'.$eventId.'_[0-9]+$"')->fetch_all(MYSQLI_ASSOC),'id');
        foreach($campaignIds as $campaignId) {
            $ownPosts=array_column($db->query('SELECT id FROM telegram_announcement_posts WHERE campaign_id='.(int)$campaignId)->fetch_all(MYSQLI_ASSOC),'id');
            if($ownPosts)$db->query('DELETE FROM telegram_announcement_revisions WHERE post_id IN ('.implode(',',array_map('intval',$ownPosts)).')');
            $db->query('DELETE FROM telegram_announcement_posts WHERE campaign_id='.(int)$campaignId);
            $db->query('DELETE FROM telegram_announcement_campaigns WHERE id='.(int)$campaignId);
        }
        $db->query('DELETE FROM events WHERE id='.$eventId);
    }
    foreach($updateIds as $updateId)$db->query('DELETE FROM telegram_announcement_updates WHERE update_id='.$updateId);
    foreach(['announcements_update_offset'=>$offset,'announcements_counter'=>$counter,'announcements_heartbeat'=>$heartbeat] as $key=>$old) {
        if($old)$store->setOption($key,$old['value']);else $db->query('DELETE FROM site_options WHERE key_name="'.$key.'"');
    }
    unlink($file);if($madeDirectory)rmdir($directory);
}
it_done();
