<?php
require_once __DIR__.'/integration_helpers.php';
use Domain\EventManager;
use Domain\AnnouncementSchedule;

$now=strtotime('2030-01-10 00:00 UTC');
$event=['id'=>471,'title'=>'Fixture','start_time'=>'2030-01-10 22:30:00','duration_minutes'=>60,'is_recurring'=>1,'recurrence_rule'=>'weekly','use_playlist'=>1];
$keys=static fn($rows)=>array_column($rows,'run_id');
$legacy=EventManager::expandOccurrences([$event],30,$now);
check($keys($legacy)===$keys(EventManager::expandOccurrences([$event+['recurrence_until'=>null,'recurrence_skip_dates'=>null]],30,$now)),'NULL preserves every legacy occurrence');
$limited=$event+['recurrence_until'=>'2030-01-25','recurrence_skip_dates'=>'["2030-01-11","2030-01-18"]'];
$rows=EventManager::expandOccurrences([$limited],30,$now);
check($keys($rows)===['471_'.strtotime('2030-01-24 22:30 UTC')],'MSK dates skip base and middle, until inclusive');
check(!array_diff($keys($rows),$keys($legacy)),'remaining run IDs unchanged');
$daily=array_replace($limited,['recurrence_rule'=>'daily','recurrence_until'=>'2030-01-14','recurrence_skip_dates'=>'["2030-01-12"]']);
$rows=EventManager::expandOccurrences([$daily],30,$now);
check(array_column($rows,'real_start_time')===array_map(static fn($d)=>strtotime('2030-01-'.$d.' 22:30 UTC'),['10','12','13']),'daily includes end date in MSK and skips middle');
check(count(EventManager::expandOccurrences([$daily],2,$now))===1,'horizon stays exclusive with skips');
$occurrence=$limited+['real_start_time'=>strtotime('2030-01-17 22:30 UTC')];
check(AnnouncementSchedule::calculate($occurrence)===[],'Telegram schedule rejects skipped occurrence');
check(AnnouncementSchedule::calculate(array_replace($occurrence,['real_start_time'=>strtotime('2030-01-31 22:30 UTC')]))===[],'Telegram schedule rejects occurrence after until');
$valid=array_replace($occurrence,['real_start_time'=>strtotime('2030-01-24 22:30 UTC')]);
check(AnnouncementSchedule::calculate($valid)===AnnouncementSchedule::calculate(array_diff_key($valid,array_flip(['recurrence_until','recurrence_skip_dates']))),'remaining announcement times unchanged');
$snap=LLM\ScheduleData::snapshot(EventManager::expandOccurrences([$limited],30,$now),$now);
check(($snap['next']['run_id']??'')==='471_'.strtotime('2030-01-24 22:30 UTC'),'Lyra selects nearest actual occurrence');
it_done();
