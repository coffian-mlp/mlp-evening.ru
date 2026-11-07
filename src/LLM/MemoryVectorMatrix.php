<?php
namespace LLM;

/** One immutable vector-only snapshot per retriever/process; source text is never cached. */
final class MemoryVectorMatrix
{
    private ?array $vectors = null;
    private array $versions = [];
    private array $scores = [];

    public function top(array $query, callable $load, callable $check, int $limit = 20): array
    {
        $query = self::unit($query);
        if ($this->vectors === null) {
            $vectors = [];
            foreach ($load() as $row) {
                $check();
                if ((int)$row['dimensions'] !== 512 || strlen($row['embedding'] ?? '') !== 2048) continue;
                try { $vector = self::unit(array_values(unpack('g*', $row['embedding']))); }
                catch (\InvalidArgumentException $error) { continue; }
                $vectors[(int)$row['id']] = $vector;
                if(isset($row['revision'],$row['source_hash']))$this->versions[(int)$row['id']]=['revision'=>(int)$row['revision'],'source_hash'=>$row['source_hash']];
            }
            $this->vectors = $vectors;
        }
        $scores = []; $this->scores=[];
        foreach ($this->vectors as $id => $vector) {
            $check(); $score = 0.0;
            foreach ($vector as $i => $value) $score += $value * $query[$i];
            $this->scores[$id]=$score;
            if ($score > 0.15) $scores[$id] = $score;
        }
        uksort($scores, static fn($a, $b) => ($scores[$b] <=> $scores[$a]) ?: ($a <=> $b));
        return array_slice($scores, 0, $limit, true);
    }

    public function similarities(): array { return $this->scores; }

    public function version(int $id): ?array { return $this->versions[$id] ?? null; }

    public static function unit(array $vector): array
    {
        if (!array_is_list($vector) || count($vector) !== 512) throw new \InvalidArgumentException('Invalid recall vector');
        $norm = 0.0;
        foreach ($vector as $value) {
            if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value)) throw new \InvalidArgumentException('Invalid recall number');
            $norm += $value * $value;
        }
        if ($norm <= 0 || !is_finite($norm)) throw new \InvalidArgumentException('Invalid recall norm');
        $norm = sqrt($norm);
        return array_map(static fn($v) => $v / $norm, $vector);
    }
}
