<?php
namespace LLM;

interface EmbeddingProviderInterface
{
    public function profile(): string;
    /** Returns ordered, finite unit vectors. Throws on transport, schema or deadline failure. */
    public function embed(array $texts, float $deadline): array;
}
