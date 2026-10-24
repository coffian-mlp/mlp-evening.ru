<?php
require_once __DIR__.'/../autoload.php';
$failed=0;
function verifyAnnLog(bool $ok,string $label): void {global $failed;echo ($ok?'PASS ':'FAIL ').$label."\n";if(!$ok)++$failed;}
$dir=sys_get_temp_dir().'/mlp_ann_log_'.bin2hex(random_bytes(8));
$path=$dir.'/announcements.log';$log=new Infra\AnnouncementLog($path);
try {
    $secret='private-token-or-caption';
    $error=new Social\TelegramBotException($secret,true,['reason'=>'network','http_status'=>503,'curl_errno'=>28,'body'=>$secret]);
    verifyAnnLog($log->record('delivery_failed',['id'=>12,'post_id'=>7,'stream_number'=>180,'version'=>3,'kind'=>'reminder','caption'=>$secret,'nonce'=>$secret],$error,['stage'=>'sending','next_state'=>'uncertain','attempts'=>1,'retry_at'=>'2026-10-24 13:05:00','token'=>$secret]),'failure persisted');
    $first=file_get_contents($path);$entry=json_decode(trim($first),true,512,JSON_THROW_ON_ERROR);
    verifyAnnLog($entry['revision_id']===12 && $entry['post_id']===7 && $entry['stream_number']===180 && $entry['kind']==='reminder','failure identifies exact post revision');
    verifyAnnLog($entry['http_status']===503 && $entry['curl_errno']===28 && $entry['uncertain']===true && $entry['attempts']===1,'safe transport and retry metadata retained');
    verifyAnnLog(!str_contains($first,$secret),'exception message, caption and arbitrary fields omitted');
    verifyAnnLog($log->record('channel_sent',['id'=>12],null,['message_id'=>289]),'later success persisted');
    verifyAnnLog(str_starts_with(file_get_contents($path),$first) && count(file($path))===2,'success never erases earlier failure');
    verifyAnnLog((fileperms($path)&0777)===0640,'journal created without world read permission');
    verifyAnnLog(count($log->recent())===1 && $log->recent()[0]['event']==='delivery_failed','default read shows failures after later success');
    verifyAnnLog(array_column($log->recent(100,false),'event')===['channel_sent','delivery_failed'],'all events newest first');
    file_put_contents($path.'.1',$first);
    file_put_contents($path,"malformed\n".$first.'{"unfinished":');
    verifyAnnLog(count($log->recent())===2,'rotation included, malformed and partial records skipped');
    verifyAnnLog(count($log->recent(1))===1,'result limit enforced');
    file_put_contents($path,str_repeat($first,150));
    verifyAnnLog(count($log->recent(999))===100,'public read bounded to 100 records');
    file_put_contents($path,str_repeat('x',1048576)."\n".$first);
    verifyAnnLog(count($log->recent())===2,'oversized file tail read is bounded and starts on complete line');
    $timezone=new DateTimeZone('Europe/Moscow');
    $entry['at']='2026-10-24T13:00:00Z';$entry['caption']=$secret;$entry['token']=$secret;
    $view=Domain\AnnouncementJournal::describe($entry,$timezone);
    verifyAnnLog($view['at']==='24.10.2026 16:00:00' && $view['post']==='#180 · Напоминание','journal formats timezone and post');
    verifyAnnLog(str_contains($view['reason'],'Истекло время') && str_contains($view['outcome'],'Автоматический повтор отключён'),'timeout and uncertain delivery explained');
    verifyAnnLog(!str_contains(json_encode($view),$secret) && str_contains($view['details'],'cURL 28'),'dashboard projects only safe details');
    $view=Domain\AnnouncementJournal::describe(['event'=>'delivery_failed','reason'=>'rate_limit','next_state'=>'approved','retry_at'=>'2026-10-24 13:05:00'],$timezone);
    verifyAnnLog(str_contains($view['reason'],'частоту') && str_contains($view['outcome'],'На момент записи') && str_contains($view['outcome'],'16:05:00'),'historical retry not presented as current status');
    $view=Domain\AnnouncementJournal::describe(['event'=>'poll_failed','at'=>'2026-02-31T13:00:00Z'],$timezone);
    verifyAnnLog($view['at']==='Время не указано' && str_contains($view['outcome'],'продолжается'),'poll failure and invalid time handled');
    $view=Domain\AnnouncementJournal::describe(['event'=>$secret,'reason'=>$secret,'stage'=>[$secret],'revision_id'=>$secret,'error_type'=>'worker'],$timezone);
    verifyAnnLog(!str_contains(json_encode($view),$secret),'unknown diagnostic strings and malformed values never exposed');
    rename($path,$path.'.real');symlink($path.'.real',$path);
    $rejected=false;try {$log->recent();} catch(RuntimeException $e) {$rejected=true;}
    verifyAnnLog($rejected,'symlink journal rejected');unlink($path);rename($path.'.real',$path);
    verifyAnnLog((new Infra\AnnouncementLog($dir.'/missing.log'))->recent()===[],'no history is an empty journal');
    file_put_contents($path,str_repeat('x',1048576));
    verifyAnnLog($log->record('poll_failed',[],$error) && filesize($path.'.1')===1048576 && filesize($path)<2048,'rotation retains previous bounded journal');
    $bad=new Infra\AnnouncementLog($path.'/not-a-directory.log');
    verifyAnnLog(!$bad->record('worker_failed',[],new RuntimeException($secret)),'journal failure is nonfatal and falls back to syslog');
} finally {
    foreach ([$path,$path.'.1',$path.'.lock'] as $file) if(is_file($file))unlink($file);
    if(is_dir($dir))rmdir($dir);
}
echo $failed?"FAILURES: $failed\n":"ALL PASS\n";exit($failed?1:0);
