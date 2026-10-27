<?php
namespace Domain;

/** Deterministic health debounce; all timestamps use UTC. */
final class StreamHealthPolicy
{
    public static function stamp(?string $value): ?int { return $value === null ? null : strtotime($value.' UTC'); }
    public static function date(int $value): string { return gmdate('Y-m-d H:i:s',$value); }

    public static function observe(array $run, array $sample, int $now): array
    {
        $gap = $run['last_checked'] !== null && $now - self::stamp($run['last_checked']) > 35;
        if ($gap) { $run['healthy_since']=null; $run['recovered_since']=null; }
        $run['last_checked']=self::date($now);
        $health=$sample['state'];
        if ($health==='live') {
            $key=(string)($sample['progress']??'');
            if ($gap || $run['progress_key'] !== $key || $run['progress_at'] === null) {
                $run['progress_key']=$key; $run['progress_at']=self::date($now);
            } elseif ($now-self::stamp($run['progress_at'])>=30) $health='down';
        }
        $run['health']=$health;
        if ($health==='live') {
            $run['healthy_since']??=self::date($now);
            if ($now-self::stamp($run['healthy_since'])>=60) $run['armed']=1;
            if ($run['lost_at'] !== null) $run['recovered_since']??=self::date($now);
        } elseif ($health==='down') {
            $run['healthy_since']=null; $run['recovered_since']=null;
            if ($run['armed']) $run['lost_at']??=self::date($now);
        } else {
            $run['healthy_since']=null; $run['recovered_since']=null;
        }
        return $run;
    }

    public static function stages(array $run, ?array $incident, int $now): array
    {
        if (!$incident || $run['health']!=='down' || $run['ended']) return [];
        $age=$now-self::stamp($incident['lost_at']);
        if ($incident['state']==='open') {
            $out=$age>=45?['site_warning']:[];
            if ($age>=180) $out[]='private_warning';
            if ($age>=600) array_push($out,'site_pause','channel_pause');
            return $out;
        }
        if ($incident['state']==='acknowledged' && $now-self::stamp($incident['acknowledged_at'])>=600) return ['private_reminder'];
        return [];
    }
}
