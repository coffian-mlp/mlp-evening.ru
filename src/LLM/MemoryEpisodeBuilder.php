<?php
namespace LLM;

/** Deterministic source references; offsets count Unicode characters in decoded source text. */
final class MemoryEpisodeBuilder
{
    public const VERSION = 'episodes-v1-600s-20-4000-3';

    public function __construct(private int $botId) {}

    public static function text(array $row, string $kind): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode((string)($row[$kind === 'chat' ? 'message' : 'text'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    public static function signature(array $row, string $kind): string
    {
        $fields = $kind === 'chat'
            ? ['id','user_id','username','message','created_at','edited_at','is_deleted','quoted_msg_ids']
            : ['id','user_id','created_by','text','kind','source','created_at','updated_at'];
        $values = [];
        foreach ($fields as $field) $values[$field] = isset($row[$field]) ? (string)$row[$field] : null;
        return hash('sha256', json_encode($values, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function chat(array $rows, string $profile, ?callable $check = null): array
    {
        $fragments = [];
        foreach ($rows as $row) {
            if ($check !== null) $check();
            if (!empty($row['is_deleted']) || (int)($row['user_id'] ?? 0) <= 0 || self::text($row, 'chat') === '') continue;
            $name = mb_substr(preg_replace('/\s+/u', ' ', (string)$row['username']), 0, 80);
            $role = (int)$row['user_id'] === $this->botId ? 'bot' : 'user';
            $prefix = '[' . $row['created_at'] . ' UTC] [' . $role . '] @' . $name . ': ';
            foreach ($this->fragments($row, 'chat', $prefix, $check) as $fragment) $fragments[] = $fragment;
        }
        $episodes = [];
        $start = 0;
        while ($start < count($fragments)) {
            if ($check !== null) $check();
            $window = []; $length = 0; $end = $start;
            while ($end < count($fragments) && count($window) < 20) {
                $next = $fragments[$end];
                $previous = $window ? $window[count($window)-1] : null;
                if ($previous && strtotime($next['date'] . ' UTC') - strtotime($previous['date'] . ' UTC') > 600) break;
                if ($length + mb_strlen($next['line']) + 1 > 4000) break;
                $window[] = $next;
                $length += mb_strlen($next['line']) + 1;
                $end++;
            }
            $episodes[] = $this->episode($window, 'chat', $profile);
            if ($end === count($fragments)) break;
            $gap = strtotime($fragments[$end]['date'] . ' UTC') - strtotime($fragments[$end-1]['date'] . ' UTC') > 600;
            $overlap = $gap ? 0 : min(3, count($window)-1);
            while ($overlap > 0) {
                $overlapLength = array_sum(array_map(fn($item) => mb_strlen($item['line']) + 1, array_slice($window, -$overlap)));
                if ($overlapLength + mb_strlen($fragments[$end]['line']) + 1 <= 4000) break;
                $overlap--;
            }
            $start = $end - $overlap;
        }
        return $episodes;
    }

    public function memory(array $rows, string $profile, ?callable $check = null): array
    {
        $episodes = [];
        foreach ($rows as $row) {
            if ($check !== null) $check();
            if (self::text($row, 'memory') === '') continue;
            foreach ($this->fragments($row, 'memory', '[Память ' . $row['kind'] . ', ' . $row['source'] . '] ', $check) as $fragment) {
                $episodes[] = $this->episode([$fragment], 'memory', $profile);
            }
        }
        return $episodes;
    }

    private function fragments(array $row, string $kind, string $prefix, ?callable $check): array
    {
        $text = self::text($row, $kind);
        $capacity = 3999 - mb_strlen($prefix);
        if ($capacity < 1) throw new \RuntimeException('Memory source prefix is too long');
        $fragments = [];
        for ($offset = 0; $offset < mb_strlen($text); $offset += $capacity) {
            if ($check !== null) $check();
            $part = mb_substr($text, $offset, $capacity);
            $fragments[] = [
                'source_id'=>(int)$row['id'], 'user_id'=>(int)($row['user_id'] ?? 0),
                'related_user_id'=>(int)($row['created_by'] ?? 0),
                'start_offset'=>$offset, 'end_offset'=>$offset+mb_strlen($part),
                'signature'=>self::signature($row, $kind), 'date'=>(string)$row['created_at'], 'line'=>$prefix.$part,
            ];
        }
        return $fragments;
    }

    private function episode(array $fragments, string $kind, string $profile): array
    {
        $first = $fragments[0];
        $authors = [];
        foreach ($fragments as $fragment) {
            foreach ([$fragment['user_id'], $fragment['related_user_id']] as $id) if ($id > 0) $authors[$id] = $id;
        }
        sort($authors);
        $refs = [];
        foreach ($fragments as $fragment) { unset($fragment['line'], $fragment['date']); $refs[] = $fragment; }
        return [
            'key'=>hash('sha256', self::VERSION.'|'.$kind.'|'.$first['source_id'].'|'.$first['start_offset']),
            'hash'=>hash('sha256', json_encode([self::VERSION, $this->botId, $profile, $refs], JSON_THROW_ON_ERROR)),
            'profile'=>$profile, 'kind'=>$kind, 'sources'=>$refs, 'authors'=>$authors,
            'first_id'=>min(array_column($refs,'source_id')), 'last_id'=>max(array_column($refs,'source_id')),
            'started_at'=>$first['date'], 'ended_at'=>$fragments[count($fragments)-1]['date'],
            'text'=>implode("\n", array_column($fragments,'line')),
        ];
    }
}
