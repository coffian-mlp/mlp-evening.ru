<?php
namespace LLM;

/** Adapters requiring one initial system message retain context rules in their original order. */
final class ProviderSystemPrompt
{
    /** @return array{0: string, 1: array} */
    public static function merge(string $systemPrompt, array $context): array
    {
        $messages = [];
        foreach ($context as $message) {
            if (($message['role'] ?? null) === 'system') {
                $systemPrompt .= "\n\n" . ($message['content'] ?? '');
            } else {
                $messages[] = $message;
            }
        }
        return [$systemPrompt, $messages];
    }
}
