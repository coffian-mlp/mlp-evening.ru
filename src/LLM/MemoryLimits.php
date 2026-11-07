<?php
namespace LLM;

/** Shared effective prompt caps; stored source text has its own independent limit. */
final class MemoryLimits
{
    public const BLOCK = 8000;
    public const ENTRY = 2000;
    public static function setting(string $key, mixed $value): int
    {
        return min($key === 'ai_memory_block_limit' ? self::BLOCK : self::ENTRY, max($key === 'ai_memory_block_limit' ? 0 : 100, (int)$value));
    }
}
