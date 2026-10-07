<?php
namespace LLM;

use InvalidArgumentException;

/** Deterministic checks of two independent source-grounded extractions; no external effects. */
final class RatingSearchProof
{
    public static function reconcile(array $intent, array $first, array $second, array $catalog, callable $mapCanonical, int $retrievedAt): array
    {
        try {
            self::intent($intent);
            $a = self::pass($intent, $first, $catalog, $mapCanonical, $retrievedAt);
            $b = self::pass($intent, $second, $catalog, $mapCanonical, $retrievedAt);
            self::require($a['comparison'] === $b['comparison'] && $a['source_asof'] === $b['source_asof'], 'inconsistent_evidence');
            $chosen = self::select($intent, $a);
            self::require($chosen !== [], 'missing_distribution');
            $snapshot = ['version' => 1, 'intent' => $intent, 'status' => 'found', 'retrieved_at' => $retrievedAt,
                'source_asof' => $a['source_asof'], 'universe' => $a['universe'], 'comparison' => $a['comparison'],
                'sources' => [$a['sources'], $b['sources']], 'evidence' => [$a['evidence'], $b['evidence']],
                'candidates' => self::candidates($intent, $chosen, $a, $retrievedAt)];
            self::require(strlen(json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) <= 8192, 'oversized_proof');
            return ['status' => 'found', 'candidates' => $snapshot['candidates'], 'resolution_snapshot' => $snapshot];
        } catch (InvalidArgumentException $e) {
            return self::failure($intent, $e->getMessage(), $retrievedAt);
        } catch (\TypeError | \JsonException $e) {
            return self::failure($intent, 'inconsistent_evidence', $retrievedAt);
        }
    }

    public static function failure(array $intent, string $reason, int $retrievedAt): array
    {
        try { $serialized = json_encode($intent, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE); }
        catch (\JsonException $ignored) { $serialized = null; $reason = 'invalid_intent'; }
        $oversized = $serialized === null || strlen($serialized) > 7000;
        if ($serialized !== null && $oversized) $reason = 'oversized_proof';
        $safe = self::failureIntent($intent, $oversized);
        $snapshot = ['version' => 1, 'status' => 'need_clarification', 'reason' => $reason, 'retrieved_at' => $retrievedAt, 'candidates' => []];
        if ($safe !== null) $snapshot['intent'] = $safe;
        self::require(strlen(json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) <= 8192, 'oversized_proof');
        return ['status' => 'need_clarification', 'candidates' => [], 'resolution_snapshot' => $snapshot];
    }

    private static function failureIntent(array $intent, bool $oversized): ?array
    {
        $source = $intent['requested_source'] ?? null;
        if ($source !== null && (!is_string($source) || mb_strlen($source) > 100)) return null;
        $header = array_intersect_key($intent, array_flip(['version', 'intent', 'direction', 'metric', 'selection']));
        try { self::intent($header + ['requested_source' => null, 'scope_constraints' => []]); }
        catch (InvalidArgumentException | \TypeError $ignored) { return null; }
        $header['requested_source'] = $source;
        if ($oversized) return $header;
        return array_intersect_key($intent, array_flip(['version', 'intent', 'direction', 'metric', 'selection', 'requested_source', 'scope_constraints', 'search_query']));
    }

    private static function require(bool $ok, string $reason): void
    {
        if (!$ok) throw new InvalidArgumentException($reason);
    }

    public static function intent(array $intent): void
    {
        self::require(($intent['version'] ?? null) === 1 && ($intent['intent'] ?? null) === 'rating', 'invalid_intent');
        self::require(in_array($intent['selection'] ?? '', ['extreme', 'leading_group', 'qualifying'], true), 'invalid_intent');
        $metrics = ['best' => 'mean_score', 'worst' => 'mean_score', 'negative_reception' => 'negative_share', 'polarized' => 'polarization'];
        self::require(isset($metrics[$intent['direction'] ?? '']) && $metrics[$intent['direction']] === ($intent['metric'] ?? null), 'invalid_intent');
        self::require(($intent['requested_source'] ?? null) === null || (is_string($intent['requested_source']) && mb_strtolower($intent['requested_source']) === 'imdb'), 'unsupported_source');
        self::require(is_array($intent['scope_constraints'] ?? null) && count($intent['scope_constraints']) <= 8, 'invalid_intent');
        foreach ($intent['scope_constraints'] as $constraint) self::require(is_string($constraint) && mb_strlen($constraint) <= 600, 'invalid_intent');
        if ($intent['selection'] === 'leading_group') self::require($intent['direction'] === 'best', 'invalid_intent');
        if ($intent['selection'] === 'qualifying') self::require($intent['direction'] === 'polarized', 'invalid_intent');
    }

    private static function pass(array $intent, array $envelope, array $catalog, callable $map, int $now): array
    {
        $raw = $envelope['content'] ?? null;
        self::require(is_string($raw) && strlen($raw) <= 131072, 'oversized_proof');
        $proof = self::decodeProof($raw);
        self::require(is_array($proof), 'inconsistent_evidence');
        $sources = self::sources($envelope['sources'] ?? []);
        self::headers($proof, $intent);
        $date = self::date($proof['source_asof'] ?? null, $now);
        $universe = self::universe($proof['universe'] ?? [], $intent);
        $evidence = self::evidence($proof['evidence'] ?? [], $sources);
        $rows = self::rows($proof, $sources, $evidence, $catalog, $map);
        $comparison = self::comparison($proof, $universe, $intent, $rows);
        return ['comparison' => $comparison, 'rows' => $rows, 'universe' => $universe, 'source_asof' => $date,
            'sources' => $sources, 'evidence' => $evidence];
    }

    private static function decodeProof(string $raw): ?array
    {
        $text = trim($raw);
        if (str_starts_with($text, '```')) {
            self::require(substr_count($text, '```') === 2 && preg_match('/^```json\s*(\{[\s\S]*?\})\s*```(?:[\s\S]*)$/', $text, $match) === 1, 'inconsistent_evidence');
            $text = $match[1];
        }
        $value = json_decode($text, true);
        return is_array($value) ? $value : null;
    }

    private static function headers(array $proof, array $intent): void
    {
        foreach (['metric', 'direction', 'selection'] as $key) self::require(($proof[$key] ?? null) === $intent[$key], 'inconsistent_evidence');
        self::require(($proof['platform'] ?? null) === 'imdb', 'unsupported_source');
        self::require(($proof['scale'] ?? null) === ['min' => 1, 'max' => 10], 'inconsistent_evidence');
    }

    private static function sources(array $sources): array
    {
        self::require(count($sources) > 0 && count($sources) <= 3, 'source_unavailable');
        $result = [];
        foreach ($sources as $source) {
            $url = $source['url'] ?? '';
            self::require(is_string($url) && strlen($url) <= 2000, 'oversized_proof');
            self::require(is_string($source['title'] ?? '') && mb_strlen($source['title'] ?? '') <= 1000, 'oversized_proof');
            try { self::sourceUrl($url); }
            catch (InvalidArgumentException $ignored) { continue; }
            $result[$url] = ['url' => $url, 'title' => $source['title'] ?? ''];
        }
        self::require($result !== [], 'source_unavailable');
        return $result;
    }

    private static function sourceUrl(mixed $url): void
    {
        self::require(is_string($url) && strlen($url) <= 2000 && filter_var($url, FILTER_VALIDATE_URL) !== false, 'unsupported_source');
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        self::require(($parts['scheme'] ?? '') === 'https' && !isset($parts['user']) && !isset($parts['pass']), 'unsupported_source');
        self::require($host === 'imdb.com' || str_ends_with($host, '.imdb.com'), 'unsupported_source');
    }

    private static function date(mixed $date, int $now): ?string
    {
        if ($date === null) return null;
        self::require(is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) === 1, 'stale_source');
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
        self::require($parsed !== false && $parsed->format('Y-m-d') === $date, 'stale_source');
        self::require($parsed->getTimestamp() >= $now - 30 * 86400 && $parsed->getTimestamp() <= $now + 86400, 'stale_source');
        return $date;
    }

    private static function universe(array $universe, array $intent): array
    {
        self::require(($universe['series'] ?? null) === 'My Little Pony: Friendship Is Magic', 'incomplete_comparison');
        self::require(($universe['constraints'] ?? null) === $intent['scope_constraints'], 'incomplete_comparison');
        self::require(in_array($universe['coverage'] ?? '', ['complete_rows', 'source_ranked_boundary', 'distribution_only'], true), 'incomplete_comparison');
        if ($universe['coverage'] === 'complete_rows') self::require(is_int($universe['population_count'] ?? null) && $universe['population_count'] > 0, 'incomplete_comparison');
        return array_intersect_key($universe, array_flip(['series', 'constraints', 'coverage', 'population_count']));
    }

    private static function evidence(array $evidence, array $sources): array
    {
        self::require(count($evidence) > 0 && count($evidence) <= 3, 'inconsistent_evidence');
        $result = [];
        foreach ($evidence as $item) {
            self::require(isset($sources[$item['source_url'] ?? '']), 'inconsistent_evidence');
            self::require(is_string($item['excerpt'] ?? null) && trim($item['excerpt']) !== '' && mb_strlen($item['excerpt']) <= 1000, 'oversized_proof');
            $result[$item['source_url']] = $item['excerpt'];
        }
        return $result;
    }

    private static function rows(array $proof, array $sources, array $evidence, array $catalog, callable $map): array
    {
        $input = $proof['comparison']['rows'] ?? [];
        self::require(is_array($input) && count($input) > 0 && count($input) <= 300, 'incomplete_comparison');
        $distributions = self::distributions($proof['distribution'] ?? [], $sources, $evidence, $catalog, $map);
        $rows = [];
        foreach ($input as $row) {
            $id = self::identity($row, $sources, $evidence, $catalog, $map);
            self::require(!isset($rows[$id]), 'inconsistent_evidence');
            $value = self::rowValue($proof['metric'], $row, $distributions[$id] ?? null);
            $rank = $row['rank'] ?? null;
            self::require($rank === null || (is_int($rank) && $rank > 0), 'inconsistent_evidence');
            $title = array_values(array_filter($catalog, static fn($c) => (int)$c['ID'] === $id))[0]['TITLE'];
            $rows[$id] = ['episode_id' => $id, 'title' => $title, 'value' => $value, 'rank' => $rank, 'source_url' => $row['source_url'], 'distribution' => $distributions[$id] ?? null];
        }
        if (($proof['universe']['coverage'] ?? '') === 'complete_rows') $rows = self::rankCompleteRows($rows, $proof['direction']);
        ksort($rows);
        return $rows;
    }

    private static function rankCompleteRows(array $rows, string $direction): array
    {
        foreach ($rows as $id => $row) {
            $better = array_filter($rows, static fn($other) => $direction === 'worst' ? $other['value'] < $row['value'] : $other['value'] > $row['value']);
            $rank = count($better) + 1;
            self::require($row['rank'] === null || $row['rank'] === $rank, 'inconsistent_evidence');
            $rows[$id]['rank'] = $rank;
        }
        return $rows;
    }

    private static function identity(array $row, array $sources, array $evidence, array $catalog, callable $map): int
    {
        self::require(isset($sources[$row['source_url'] ?? '']) && isset($evidence[$row['source_url'] ?? '']), 'inconsistent_evidence');
        $id = $map($row, $catalog);
        self::require(is_int($id) && $id > 0, 'inconsistent_evidence');
        return $id;
    }

    private static function rowValue(string $metric, array $row, ?array $distribution): float
    {
        if ($metric !== 'mean_score') {
            self::require($distribution !== null, 'missing_distribution');
            $value = $metric === 'negative_share' ? $distribution['low_share'] : $distribution['polarization'];
            if (isset($row['value'])) self::require(self::number($row['value'], 0, 1) === $value, 'inconsistent_evidence');
            return $value;
        }
        return self::number($row['value'] ?? null, 1, 10);
    }

    private static function number(mixed $value, float $min, float $max): float
    {
        self::require((is_int($value) || is_float($value)) && is_finite((float)$value), 'inconsistent_evidence');
        self::require($value >= $min && $value <= $max, 'inconsistent_evidence');
        return (float)$value;
    }

    private static function distributions(array $input, array $sources, array $evidence, array $catalog, callable $map): array
    {
        self::require(count($input) <= 300, 'oversized_proof');
        $result = [];
        foreach ($input as $item) {
            $id = self::identity($item, $sources, $evidence, $catalog, $map);
            self::require(!isset($result[$id]), 'inconsistent_evidence');
            $result[$id] = self::histogram($item);
        }
        return $result;
    }

    private static function histogram(array $item): array
    {
        $total = $item['total'] ?? null;
        self::require(is_int($total) && $total >= 100, 'missing_distribution');
        $bins = $item['bins'] ?? [];
        self::require(is_array($bins) && count($bins) > 0 && count($bins) <= 20, 'missing_distribution');
        $sum = $low = $high = 0; $previous = 0;
        foreach ($bins as $bin) {
            [$min, $max, $count] = self::bin($bin, $previous);
            $previous = $max;
            $sum += $count;
            if (($max - 1) / 9 <= .3) $low += $count;
            if (($min - 1) / 9 >= .7) $high += $count;
        }
        self::require($sum === $total, 'missing_distribution');
        return ['total' => $total, 'bins' => $bins, 'low_count' => $low, 'high_count' => $high,
            'low_share' => $low / $total, 'high_share' => $high / $total, 'polarization' => 2 * min($low / $total, $high / $total)];
    }

    private static function bin(array $bin, float $previous): array
    {
        $min = self::number($bin['min'] ?? null, 1, 10); $max = self::number($bin['max'] ?? null, 1, 10);
        self::require($min <= $max && $min > $previous, 'missing_distribution');
        $count = $bin['count'] ?? null;
        self::require(is_int($count) && $count >= 0, 'missing_distribution');
        self::require(!($min <= 3.7 && $max > 3.7) && !($min < 7.3 && $max >= 7.3), 'missing_distribution');
        return [$min, $max, $count];
    }

    private static function comparison(array $proof, array $universe, array $intent, array $rows): array
    {
        $coverage = $universe['coverage'];
        if ($coverage === 'complete_rows') self::require($universe['population_count'] === count($rows), 'incomplete_comparison');
        if ($coverage === 'distribution_only') self::require($intent['selection'] === 'qualifying', 'incomplete_comparison');
        $boundary = $proof['comparison']['boundary'] ?? null;
        if ($coverage === 'source_ranked_boundary') self::boundary($intent, $rows, $boundary);
        $numeric = [];
        foreach ($rows as $id => $row) $numeric[$id] = ['value' => $row['value'], 'rank' => $row['rank'], 'distribution' => $row['distribution']];
        return ['universe' => $universe, 'rows' => $numeric, 'boundary' => $boundary];
    }

    private static function boundary(array $intent, array $rows, mixed $boundary): void
    {
        self::require($intent['metric'] === 'mean_score', 'incomplete_comparison');
        if ($intent['selection'] === 'leading_group') {
            foreach ($rows as $row) self::require(is_int($row['rank']) && $row['rank'] <= 10, 'incomplete_comparison');
            return;
        }
        self::require(is_array($boundary) && ($boundary['rank'] ?? null) === 1 && ($boundary['tied_count'] ?? null) === count($rows), 'incomplete_comparison');
        self::require(($boundary['position'] ?? null) === ($intent['direction'] === 'worst' ? 'bottom' : 'top'), 'incomplete_comparison');
        foreach ($rows as $row) self::require($row['rank'] === 1, 'incomplete_comparison');
        self::require(count(array_unique(array_column($rows, 'value'))) === 1, 'inconsistent_evidence');
    }

    private static function select(array $intent, array $pass): array
    {
        $rows = array_values($pass['rows']);
        if ($intent['selection'] === 'qualifying') return array_values(array_filter($rows, static fn($r) => self::polarized($r['distribution'])));
        if ($intent['selection'] === 'leading_group') return array_values(array_filter($rows, static fn($r) => is_int($r['rank']) && $r['rank'] <= 10));
        $values = array_column($rows, 'value');
        $best = $intent['direction'] === 'worst' ? min($values) : max($values);
        $chosen = array_values(array_filter($rows, static fn($r) => $r['value'] === $best));
        if ($intent['metric'] === 'polarization') $chosen = array_values(array_filter($chosen, static fn($r) => self::polarized($r['distribution'])));
        return $chosen;
    }

    private static function polarized(?array $d): bool
    {
        return $d !== null && $d['low_count'] >= 20 && $d['high_count'] >= 20 && $d['low_share'] >= .2 && $d['high_share'] >= .2;
    }

    private static function episodeOrder(array $row): int
    {
        $metadata = \Domain\EpisodeCatalog::metadata($row['title']);
        return $metadata === null ? PHP_INT_MAX : $metadata['season'] * 1000 + $metadata['episode'];
    }

    private static function candidates(array $intent, array $chosen, array $pass, int $now): array
    {
        usort($chosen, static fn($a, $b) => self::episodeOrder($a) <=> self::episodeOrder($b) ?: ($a['episode_id'] <=> $b['episode_id']));
        $result = [];
        foreach (array_slice($chosen, 0, 3) as $row) {
            $ties = count(array_filter($chosen, static fn($candidate) => $candidate['value'] === $row['value']));
            $result[] = ['episode_id' => $row['episode_id'], 'title' => $row['title'], 'source_url' => $row['source_url'],
                'evidence' => mb_substr($pass['evidence'][$row['source_url']], 0, 700), 'rating' =>
                    ['platform' => 'IMDb', 'metric' => $intent['metric'], 'direction' => $intent['direction'], 'selection' => $intent['selection'],
                        'value' => $row['value'], 'rank' => $row['rank'], 'scale' => ['min' => 1, 'max' => 10], 'universe' => $pass['universe'],
                        'source_asof' => $pass['source_asof'], 'retrieved_at' => $now, 'tie_count' => $ties, 'non_exhaustive_ties' => $ties > 3,
                        'distribution' => $row['distribution']]];
        }
        return $result;
    }
}
