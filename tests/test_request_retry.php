<?php
require_once __DIR__ . '/../autoload.php';

use LLM\RequestRetry;

$fail = 0;
function checkRetry(bool $condition, string $label): void {
    global $fail;
    echo ($condition ? '[OK] ' : '[FAIL] ') . $label . "\n";
    if (!$condition) $fail++;
}
foreach ([408, 425, 429, 500, 502, 503, 504] as $status) {
    checkRetry(RequestRetry::isTransient(new Exception("RouterAI HTTP Error $status: fixture")), "temporary HTTP $status");
}
foreach (['RouterAI HTTP Error 401: unauthorized', 'OpenAI HTTP Error 400: policy', 'RouterAI Invalid Response: fixture', 'Fixture transport failure', 'RouterAI cURL Error: SSL certificate problem', 'RouterAI API key is missing'] as $message) {
    checkRetry(!RequestRetry::isTransient(new Exception($message)), 'permanent or unknown error is not retried');
}
checkRetry(!RequestRetry::isTransient(new LLM\TruncatedResponseException('RouterAI', 'partial')), 'token truncation is not a transient transport failure');
$now = 0.0; $attempts = 0; $timeouts = []; $pauses = 0;
$clock = static function () use (&$now) { return $now; };
$pause = static function () use (&$now, &$pauses) { $now += .25; $pauses++; };
$out = RequestRetry::run(static function ($timeout) use (&$now, &$attempts, &$timeouts) {
    $timeouts[] = $timeout;
    if (++$attempts === 1) { $now += 60; throw new Exception('RouterAI cURL Error: Operation timed out after 60002 milliseconds'); }
    return 'recovered';
}, 75, 60, $clock, $pause);
checkRetry($out === 'recovered' && $attempts === 2 && $timeouts === [60, 14] && $pauses === 1, 'one recovery request uses remaining budget after full timeout');
foreach ([true, false] as $temporary) {
    $now = 0; $attempts = 0;
    try {
        RequestRetry::run(static function () use (&$attempts, $temporary) { $attempts++; throw new Exception($temporary ? 'OpenAI HTTP Error 503: fixture' : 'OpenAI HTTP Error 403: fixture'); }, 20, 10, $clock, $pause);
        checkRetry(false, 'failure must propagate');
    } catch (Exception $e) {
        checkRetry($attempts === ($temporary ? 2 : 1), 'retry ceiling and permanent failure propagation');
    }
}
$now = 0; $attempts = 0;
try {
    RequestRetry::run(static function () use (&$now, &$attempts) { $attempts++; $now = 6; throw new Exception('RouterAI HTTP Error 503: fixture'); }, 10, 8, $clock, $pause);
} catch (Exception $e) { checkRetry($attempts === 1, 'insufficient remaining budget prevents retry'); }
$now = 0; $attempts = 0;
checkRetry(RequestRetry::run(static function () use (&$attempts) { $attempts++; return null; }, 10, 8, $clock, $pause) === null && $attempts === 1, 'intentional silence is not retried');
$now = 0;
checkRetry(RequestRetry::run(static fn($timeout) => $timeout, 120, 120, $clock, $pause) === 120, 'explicit director timeout retained');
$now = 10; $attempts = 0;
try {
    RequestRetry::run(static function () use (&$attempts) { $attempts++; }, 10, 8, $clock, $pause);
} catch (RuntimeException $e) { checkRetry($attempts === 0, 'expired deadline performs no request'); }
echo $fail ? "FAIL: $fail\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
