<?php
namespace LLM;

/** Deterministic topic retrieval; output budgets remain owned by LyraMemory. */
final class MemoryRelevance {
    private static function terms(string $text): array {
        $text = mb_strtolower(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        preg_match_all('/[\p{L}\p{N}]{3,}/u', $text, $matches);
        $stop = array_flip(explode(' ', 'что это как где когда почему зачем кто тебе тебя меня мне она они его еще ещё уже было были будет есть если или для про при чтобы только очень просто давай помнишь вспомни расскажи лира'));
        $terms = [];
        foreach ($matches[0] as $word) {
            if (isset($stop[$word])) continue;
            // Light normalization covers Russian inflections without requiring an external index.
            if (preg_match('/^[а-яё]+$/u', $word) && mb_strlen($word) > 5) {
                $word = preg_replace('/(?:ами|ями|ого|ему|ому|ыми|ими|ов|ев|ах|ях|ам|ям|ой|ей|ая|яя|ое|ее|ые|ие|ую|юю|ом|ем|а|я|ы|и|у|ю|е)$/u', '', $word);
            }
            $terms[$word] = true;
        }
        return $terms;
    }

    public static function rank(array $rows, string $query): array {
        $queryTerms = self::terms(mb_substr($query, 0, 2000));
        if (!$queryTerms || !$rows) return $rows;
        $terms = []; $frequency = [];
        foreach ($rows as $i => $row) {
            $terms[$i] = self::terms((string)$row['text']);
            foreach (array_intersect_key($terms[$i], $queryTerms) as $term => $_) $frequency[$term] = ($frequency[$term] ?? 0) + 1;
        }
        $scores = [];
        foreach ($rows as $i => $_) {
            $score = 0;
            foreach (array_intersect_key($terms[$i], $queryTerms) as $term => $_) $score += 1 + log(1 + count($rows) / $frequency[$term]);
            $scores[$i] = $score;
        }
        $indices = array_keys($rows);
        usort($indices, fn($a, $b) => ($scores[$b] <=> $scores[$a]) ?: ($a <=> $b));
        return array_map(fn($i) => $rows[$i], $indices);
    }
}
