<?php
/** Storage compression policy, isolated from SQL and external providers. */
require_once __DIR__ . '/../autoload.php';

use Infra\ConfigManager;
use LLM\MemoryScribe;

$fail = 0;
function checkStorage(bool $condition, string $label): void {
    global $fail;
    echo ($condition ? '[OK] ' : '[FAIL] ') . $label . "\n";
    if (!$condition) $fail++;
}

$configClass = new ReflectionClass(ConfigManager::class);
$instance = $configClass->getProperty('instance');
$original = $instance->getValue();
$config = $configClass->newInstanceWithoutConstructor();
$configClass->getProperty('cache')->setValue($config, ['ai_memory_user_limit' => '400', 'ai_memory_meme_limit' => '800']);
$instance->setValue(null, $config);

$memory = new class {
    public int $length = 2000;
    public ?string $replacement = null;
    public function autoDossierLength(int $id): int { return $this->length; }
    public function getByUser(int $id): array {
        return [['source' => 'auto', 'text' => str_repeat('я', 2000)], ['source' => 'manual', 'text' => 'manual fact excluded']];
    }
    public function replaceAutoDossier(int $id, string $text): void { $this->replacement = $text; }
    public function autoMemesLength(): int { return 0; }
};
$provider = new class {
    public array $calls = [];
    public function generateUtility(array $context, string $prompt, int $timeout): string {
        $this->calls[] = $context;
        return str_repeat('я', 3993) . ' конец!';
    }
};
$scribeClass = new ReflectionClass(MemoryScribe::class);
$scribe = $scribeClass->newInstanceWithoutConstructor();
$scribeClass->getProperty('memory')->setValue($scribe, $memory);
$scribeClass->getProperty('llm')->setValue($scribe, $provider);
$compress = $scribeClass->getMethod('compressIfNeeded');
try {
    $compress->invoke($scribe, [1], time());
    checkStorage($provider->calls === [] && $memory->replacement === null, '2000 stored characters are not compressed to the 400-character prompt budget');
    $memory->length = 4000;
    $compress->invoke($scribe, [1], time());
    checkStorage($provider->calls === [], 'exact storage threshold does not trigger compression');
    $memory->length = 4001;
    $compress->invoke($scribe, [1], time());
    checkStorage(count($provider->calls) === 1, 'storage overflow triggers one compression call');
    checkStorage(mb_strlen($memory->replacement ?? '') === 4000 && str_ends_with($memory->replacement, 'конец!'), 'compressed storage preserves the tail beyond the old limit');
    checkStorage(str_contains($provider->calls[0][0]['content'], '4000') && !str_contains($provider->calls[0][0]['content'], 'manual fact'), 'provider receives storage target and only auto facts');
    $compress->invoke($scribe, [1], time() - MemoryScribe::JOB_DEADLINE_SEC);
    checkStorage(count($provider->calls) === 1, 'expired job budget cannot start another provider call');
} finally {
    $instance->setValue(null, $original);
}
echo $fail ? "FAILED: $fail\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
