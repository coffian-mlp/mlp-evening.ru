<?php
namespace Domain;
/** Pure catalogue parsing and canonical story construction. */
final class EpisodeCatalog {

    public static function metadata(string $title): ?array {
        if (!preg_match('/^My Little Pony Friendship is Magic - Season (\d+) Episode (\d+) - (.+)$/u', $title, $m)) return null;
        return ['season' => (int)$m[1], 'episode' => (int)$m[2], 'name' => $m[3]];
    }

    public static function normalize(array $rows): array {
        $map = [];
        foreach ($rows as $r) if ((int)($r['ID'] ?? 0) > 0) $map[(int)$r['ID']] = $r;
        $stories = [];
        $seen = [];
        foreach ($map as $id => $row) {
            if (isset($seen[$id])) continue;
            $link = (int)($row['TWOPART_ID'] ?? 0);
            $parts = [$row];
            if ($link) {
                if ($link === $id  ||  !isset($map[$link])  ||  (int)$map[$link]['TWOPART_ID'] !== $id) continue;
                $parts[] = $map[$link];
                $a = self::metadata($parts[0]['TITLE']);
                $b = self::metadata($parts[1]['TITLE']);
                if (!$a  ||  !$b  ||  $a['season'] !== $b['season']  ||  $a['episode'] === $b['episode']) continue;
                usort($parts, fn($x, $y) => self::metadata($x['TITLE'])['episode'] <=> self::metadata($y['TITLE'])['episode']);
            }
            if (array_filter($parts, fn($p) => (int)$p['LENGTH'] <= 0)) continue;
            $ids = array_map(fn($p) => (int)$p['ID'], $parts);
            foreach ($ids as $i) $seen[$i] = true;
            $v = array_sum(array_map(fn($p) => max(0, (float)($p['WANNA_WATCH'] ?? 0)), $parts));
            $p = max(array_map(fn($p) => max(0, (float)($p['TIMES_WATCHED'] ?? 0)), $parts));
            $stories[] = ['story_id' => implode('-', $ids), 'ids' => $ids, 'titles' => array_column($parts, 'TITLE'), 'length' => count($parts) > 1?2:(int)$row['LENGTH'], 'weight' => (1 + 0.5 * $v) / (1 + $p), 'views' => $p];
        }
        return $stories;
    }

    public static function resolveExact(string $query, array $rows): array {
        $q = mb_strtolower(trim($query));
        $matches = [];
        $id = preg_match('/^(?:серию?\s*)?(\d+)$/u', $q, $m)?(int)$m[1]:null;
        $code = preg_match('/^(?:s|с)(\d+)(?:e|э)(\d+)$/u', $q, $m)?[(int)$m[1], (int)$m[2]]:null;
        foreach ($rows as $r) {
            $meta = self::metadata($r['TITLE']);
            if (($id !== null  &&  (int)$r['ID'] === $id)  ||  ($code  &&  $meta  &&  $code === [$meta['season'], $meta['episode']])  ||  ($id === null  &&  !$code  &&  ($q === mb_strtolower($r['TITLE'])  ||  ($meta  &&  $q === mb_strtolower($meta['name']))))) $matches[] = $r;
        }
        return ['status' => count($matches) === 1?'found':(count($matches) > 1?'ambiguous':'missing'), 'episodes' => $matches];
    }
}
