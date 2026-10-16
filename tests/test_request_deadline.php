<?php
require_once __DIR__ . '/../autoload.php';

use LLM\RequestDeadline as Budget;
use LLM\RequestRetry;

$failures = 0;
function checkBudget(bool $value, string $name): void {
    global $failures;
    echo ($value ? '[OK] ' : '[FAIL] ') . $name . "\n";
    if (!$value) $failures++;
}
$now = 100.0;
$clock = static function () use (&$now): float { return $now; };
Budget::within(110, function () use (&$now) {
    checkBudget(Budget::milliseconds(60) === 10000, 'outer absolute deadline limits transport');
    Budget::within(200, function () use (&$now) {
        $now = 106.25;
        checkBudget(Budget::milliseconds(60) === 3750, 'nested vision budget cannot extend parent');
    });
    checkBudget(Budget::at() === 110.0, 'parent restored after nested call');
    try { Budget::within(108, static function () { throw new RuntimeException('fixture'); }); }
    catch (RuntimeException $e) {}
    checkBudget(Budget::at() === 110.0, 'exception restores parent scope');
    $now = 110;
    checkBudget(Budget::expired(), 'expired budget visible to vision preparation');
    try { Budget::milliseconds(60); checkBudget(false, 'expired transport rejected'); }
    catch (RuntimeException $e) { checkBudget(true, 'expired transport rejected'); }
}, $clock);
checkBudget(Budget::at() === null && !Budget::expired(), 'scope and fake clock do not leak into next job');

$now = 100.0;
$provider = new class implements LLM\LLMProviderInterface {
    public array $timeouts = [];
    public function askChat(array $context, string $prompt): ?string {
        $this->timeouts[] = Budget::milliseconds(60);
        return 'ok';
    }
};
Budget::within(106, function () use (&$provider) {
    $out = RequestRetry::ask($provider, [], 'nested', 200, 60);
    checkBudget($out === 'ok' && $provider->timeouts === [6000], 'actual nested RequestRetry inherits shorter parent');
}, $clock);
Budget::within(103, function () use (&$provider) {
    try { RequestRetry::ask($provider, [], 'no budget', 200, 60); }
    catch (RuntimeException $e) {}
    checkBudget(count($provider->timeouts) === 1, 'insufficient inherited budget starts no provider');
}, $clock);

// A nested utility must consume the same attempt as the main provider, not another 75 seconds.
$now = 100.0;
$outer = new class($now) implements LLM\LLMProviderInterface {
    public float $now;
    public array $seen = [];
    public function __construct(float &$now) { $this->now =& $now; }
    public function withTimeout(int $timeout): self { return $this; }
    public function askChat(array $context, string $prompt): ?string {
        $nested = new class($this->now) implements LLM\LLMProviderInterface {
            public float $now;
            public function __construct(float &$now) { $this->now =& $now; }
            public function askChat(array $context, string $prompt): ?string { $this->now += 7; return 'image description'; }
        };
        RequestRetry::ask($nested, [], 'describe');
        $this->seen[] = Budget::milliseconds(60);
        return 'main reply';
    }
};
Budget::within(115, function () use (&$outer) {
    checkBudget(RequestRetry::ask($outer, [], 'main', 115, 10) === 'main reply', 'nested description completes');
    checkBudget($outer->seen === [3000], 'main HTTP receives only remainder after nested vision');
}, $clock);

echo $failures ? "FAIL: $failures\n" : "ALL PASS\n";
exit($failures ? 1 : 0);
