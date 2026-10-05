<?php
namespace Domain;
final class PlaylistSelector {

    private static function reachable(array $stories, int $budget): int {
        $dp = [0 => true];
        foreach ($stories as $s) {
            foreach (array_keys($dp) as $n) if ($n + $s['length'] <= $budget) $dp[$n + $s['length']] = true;
        }
        return max(array_keys($dp));
    }

    public static function select(array $stories, int $budget = 8, ?callable $random = null): array {
        if ($budget <= 0) return [];
        $pool = [];
        $used = [];
        foreach ($stories as $s) {
            $ids = $s['ids'] ?? [];
            $cost = $s['length'] ?? 0;
            $w = $s['weight'] ?? 0;
            if (!$ids  ||  count(array_unique($ids)) !== count($ids)  ||  array_filter($ids, fn($id) => !is_int($id) || $id <= 0)  ||  !is_int($cost)  ||  $cost <= 0  ||  $cost > $budget  ||  !is_finite((float)$w)  ||  $w <= 0  ||  array_intersect($ids, array_keys($used))) continue;
            foreach ($ids as $id) $used[$id] = true;
            $pool[] = $s;
        }
        $left = self::reachable($pool, $budget);
        $out = [];
        while ($left > 0) {
            $candidates = [];
            foreach ($pool as $k => $s) {
                if ($s['length'] > $left) continue;
                $rest = $pool;
                unset($rest[$k]);
                if (self::reachable($rest, $left-$s['length']) === $left-$s['length']) $candidates[$k] = $s;
            }
            if (!$candidates) break;
            $max = max(array_column($candidates, 'weight'));
            $sum = 0;
            foreach ($candidates as $s) $sum += $s['weight'] / $max;
            $r = ($random?$random():random_int(0, PHP_INT_MAX) / PHP_INT_MAX) * $sum;
            $key = array_key_last($candidates);
            foreach ($candidates as $k => $s) {
                $r -= $s['weight'] / $max;
                if ($r <= 0) {
                    $key = $k;
                    break;
                }
            }
            $out[] = $pool[$key];
            $left -= $pool[$key]['length'];
            unset($pool[$key]);
        }
        return $out;
    }
}
