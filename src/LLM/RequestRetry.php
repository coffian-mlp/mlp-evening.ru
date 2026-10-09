<?php

namespace LLM;

/** Retries model requests only; command effects and publication remain outside this boundary. */
final class RequestRetry {
    public static function isTransient(\Throwable $error): bool {
        if ($error instanceof TruncatedResponseException) return false;
        $message = $error->getMessage();
        if (preg_match('/^(?:RouterAI|OpenAI|OpenRouter|YandexGPT|GigaChat) (?:HTTP|Auth) Error (408|425|429|500|502|503|504):/', $message)) return true;
        return (bool)preg_match('/^(?:RouterAI|OpenAI|OpenRouter|YandexGPT|GigaChat) cURL Error: (?:Operation timed out|Connection timed out|Failed to connect|Could not resolve|Couldn\x27t resolve|Recv failure|Send failure|Empty reply from server|.*Connection reset by peer)/i', $message);
    }

    public static function run(callable $request, float $deadlineAt, int $timeoutSec, ?callable $clock = null, ?callable $pause = null): mixed {
        $clock ??= static fn() => microtime(true);
        $pause ??= static fn() => usleep(250000);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $remaining = (int)floor($deadlineAt - $clock());
            if ($remaining < 5) throw new \RuntimeException('LLM request deadline exhausted');
            try {
                return $request(min(max(5, $timeoutSec), $remaining));
            } catch (\Throwable $error) {
                if ($attempt !== 0 || !self::isTransient($error) || $deadlineAt - $clock() < 5.25) throw $error;
                // Deliberately exclude exception text, credentials and request data from retry logs.
                error_log('LLM transient request failure; retrying once');
                $pause();
            }
        }
        throw new \LogicException('Unreachable retry state');
    }

    /** $provider receives the successful clone, including provider search evidence. */
    public static function ask(LLMProviderInterface &$provider, array $context, string $prompt, ?float $deadlineAt = null, int $timeoutSec = 60): ?string {
        if (!method_exists($provider, 'withTimeout')) return $provider->askChat($context, $prompt);
        $base = $provider;
        return self::run(static function (int $timeout) use ($base, &$provider, $context, $prompt) {
            $provider = $base->withTimeout($timeout);
            return $provider->askChat($context, $prompt);
        }, $deadlineAt ?? microtime(true) + max(75, $timeoutSec), $timeoutSec);
    }
}
