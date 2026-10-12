<?php
namespace Domain;

/** Relative publication rules for a concrete occurrence of a weekly event. */
final class AnnouncementSchedule
{
    public static function defaults(): array
    {
        return ['announcements_timezone'=>'Europe/Moscow','announcements_prepare_days'=>'3','announcements_prepare_time'=>'19:00',
            'announcements_main_days'=>'2','announcements_main_time'=>'19:00','announcements_reminder_minutes'=>'180'];
    }

    public static function calculate(array $event, array $settings = []): array
    {
        if (empty($event['use_playlist']) || empty($event['is_recurring']) || ($event['recurrence_rule']??'')!=='weekly' || (int)($event['real_start_time']??0)<=0) return [];
        $s=$settings+self::defaults();
        $start=(int)$event['real_start_time'];
        $local=(new \DateTimeImmutable('@'.$start))->setTimezone(new \DateTimeZone($s['announcements_timezone']));
        $at=static function (string $kind) use ($local,$s): int {
            [$hour,$minute]=array_map('intval',explode(':',$s['announcements_'.$kind.'_time']));
            return $local->modify('-'.(int)$s['announcements_'.$kind.'_days'].' days')->setTime($hour,$minute)->getTimestamp();
        };
        $r=['prepare_at'=>$at('prepare'),'main_at'=>$at('main'),'reminder_at'=>$start-60*(int)$s['announcements_reminder_minutes']];
        if ($r['prepare_at'] >= $r['main_at'] || $r['main_at'] >= $r['reminder_at'] || $r['reminder_at'] >= $start) throw new \InvalidArgumentException('Черновики должны быть раньше основного поста, основной пост — раньше напоминания, напоминание — раньше события.');
        return $r;
    }
}
