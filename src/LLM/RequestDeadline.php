<?php
namespace LLM;

/** Synchronous request scope shared by vision, authentication, retries and HTTP calls. */
final class RequestDeadline {
    private static ?float $deadline = null;
    private static $clock = null;

    public static function now(): float {
        return self::$clock !== null ? (self::$clock)() : microtime(true);
    }

    public static function within(float $deadline, callable $request, ?callable $clock = null): mixed {
        $previous = self::$deadline;
        $previousClock = self::$clock;
        if ($clock !== null) self::$clock = $clock;
        self::$deadline = $previous === null ? $deadline : min($previous, $deadline);
        try { return $request(); }
        finally { self::$deadline = $previous; self::$clock = $previousClock; }
    }

    public static function expired(): bool {
        return self::$deadline !== null && self::$deadline <= self::now();
    }

    public static function at(): ?float {
        return self::$deadline;
    }

    public static function milliseconds(int $ceiling): int {
        $remaining = self::$deadline === null ? $ceiling * 1000
            : min($ceiling * 1000, (int)floor((self::$deadline - self::now()) * 1000));
        if ($remaining < 1) throw new \RuntimeException('LLM request deadline exhausted');
        return $remaining;
    }

    public static function applyCurl($handle, int $ceiling): void {
        $remaining = self::milliseconds($ceiling);
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT_MS, min(10000, $remaining));
        curl_setopt($handle, CURLOPT_TIMEOUT_MS, $remaining);
    }
}
