<?php
require_once __DIR__.'/../autoload.php';
$fail=0;$check=static function($ok,$label)use(&$fail){echo($ok?'PASS ':'FAIL ').$label."\n";if(!$ok)$fail++;};
$event=['use_playlist'=>1,'is_recurring'=>1,'recurrence_rule'=>'weekly','real_start_time'=>strtotime('2026-10-17 16:00 UTC')];
$s=Domain\AnnouncementSchedule::calculate($event);
$check($s===['prepare_at'=>strtotime('2026-10-14 16:00 UTC'),'main_at'=>strtotime('2026-10-15 16:00 UTC'),'reminder_at'=>strtotime('2026-10-17 13:00 UTC')],'defaults preserve existing Wednesday/Thursday/three-hour workflow');
$custom=['announcements_prepare_days'=>'4','announcements_prepare_time'=>'18:30','announcements_main_days'=>'1','announcements_main_time'=>'12:15','announcements_reminder_minutes'=>'90','announcements_timezone'=>'Europe/Kaliningrad'];
$r=Domain\AnnouncementSchedule::calculate($event,$custom);
$check($r['prepare_at']===strtotime('2026-10-13 16:30 UTC') && $r['main_at']===strtotime('2026-10-16 10:15 UTC') && $r['reminder_at']===strtotime('2026-10-17 14:30 UTC'),'relative dates use actual occurrence and configured timezone');
$event['real_start_time']+=7*86400;$next=Domain\AnnouncementSchedule::calculate($event,$custom);$check($next['main_at']===$r['main_at']+7*86400,'weekly recurrence derives new dates without one-off fields');
foreach ([['announcements_prepare_days'=>'0'],['announcements_main_days'=>'0','announcements_main_time'=>'23:00'],['announcements_reminder_minutes'=>'10080']] as $bad) {
 try {Domain\AnnouncementSchedule::calculate($event,$bad);$check(false,'invalid timing order rejected');}catch(InvalidArgumentException $e){$check(true,'invalid timing order rejected');}
}
$event['real_start_time']=strtotime('2026-03-29 17:00 UTC');$dst=Domain\AnnouncementSchedule::calculate($event,['announcements_timezone'=>'Europe/Berlin']);$check($dst['main_at']===strtotime('2026-03-27 18:00 UTC'),'calendar offsets retain local clock across DST');
echo $fail?'FAIL':'ALL PASS';exit($fail?1:0);
