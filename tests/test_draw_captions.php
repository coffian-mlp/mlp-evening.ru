<?php
/** MLP-467: caption instructions and every fixed drawing caption, without model calls. */
require_once __DIR__ . '/../autoload.php';

use Infra\ConfigManager;
use LLM\LLMManager;
use LLM\LyraArtist;

$fail = 0;
$pass = 0;
function checkCaption(bool $condition, string $label): void {
    global $fail, $pass;
    $condition ? $pass++ : $fail++;
    echo ($condition ? '[OK] ' : '[FAIL] ') . $label . "\n";
}

$llm = new class extends LLMManager {
    public array $captured = [];
    public array $contextArgs = [];
    public function __construct() {}
    public function contextLimit(): int { return 24; }
    public function buildReplyContext(int $limit = 24, $maxAgeHours = null, ?int $beforeId = null, bool $includePinned = true, ?bool $includeMemory = null, ?string $memoryQuery = null): array {
        $this->contextArgs = func_get_args();
        return [['role' => 'assistant', 'content' => 'Synthetic earlier context']];
    }
    public function generateReply(array $context, string $extraInstruction = '', bool $fast = false): ?string {
        $this->captured = $context;
        return 'Synthetic artist caption';
    }
};
$artist = new LyraArtist($llm);
$caption = new ReflectionMethod(LyraArtist::class, 'captionFromDescription');
foreach ([null, 'SyntheticRequester'] as $username) {
    $mode = $username === null ? 'automatic' : 'requested';
    $result = $caption->invoke($artist, 'Synthetic image description', 'Synthetic subject', $username, 'two-colour linocut');
    $task = end($llm->captured)['content'];
    checkCaption($result === 'Synthetic artist caption', "$mode returns model caption");
    checkCaption(!preg_match('/копыт|самоирони/iu', $task), "$mode instruction omits obsolete jokes");
    checkCaption(str_contains($task, 'как художница') && str_contains($task, 'что задумала') && str_contains($task, 'что в рисунке удалось'), "$mode asks for artistic intent and result");
    checkCaption(str_contains($task, 'без самоуничижения') && str_contains($task, 'не извиняйся за качество'), "$mode requires confident commentary");
    checkCaption(str_contains($task, 'Техника рисунка: two-colour linocut'), "$mode receives the selected technique");
    checkCaption(str_contains($task, '1–2 предложения') && str_contains($task, 'НЕ вставляй ссылки и картинки') && str_contains($task, 'не проси черновик'), "$mode preserves caption constraints");
    checkCaption($username === null ? str_contains($task, 'Без адресата и без вопросов') && !str_contains($task, '@') : str_contains($task, '@SyntheticRequester'), "$mode preserves addressing");
    checkCaption(count($llm->captured) === 2 && end($llm->captured)['role'] === 'user' && $llm->contextArgs === [10, null, null, true, false], "$mode instruction stays last with bounded context");
}
$caption->invoke($artist, 'Synthetic description', 'Synthetic subject', null);
checkCaption(!str_contains(end($llm->captured)['content'], 'Техника рисунка:'), 'unknown technique is not invented');

$config = (new ReflectionClass(ConfigManager::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(ConfigManager::class, 'cache'))->setValue($config, ['ai_image_techniques' => 'synthetic linocut']);
$technique = null;
$style = LyraArtist::applyTechnique('Style: {technique}; subject:', $config, $technique);
checkCaption($style === 'Style: synthetic linocut; subject:' && $technique === 'synthetic linocut', 'reported technique is the one used by the generator');
$style = LyraArtist::applyTechnique('Fixed oil painting style', $config, $technique);
checkCaption($style === 'Fixed oil painting style' && $technique === null, 'fixed style stays intact and clears previous selection');

// Inspect all literal fallback variants, rather than depending on a random sample.
$literals = [];
foreach (token_get_all(file_get_contents(__DIR__ . '/../src/LLM/LyraArtist.php')) as $token) {
    if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING && str_contains($token[1], '![рисунок](%s)')) $literals[] = $token[1];
}
checkCaption(count($literals) === 7, 'all four requested and three automatic fallbacks are covered');
foreach ($literals as $i => $literal) {
    checkCaption(!preg_match('/копыт|ртом|во рту|не суди строго|намазюкала|простите|вроде похоже|Это комплимент/iu', $literal), 'fallback ' . ($i + 1) . ' has no self-deprecation');
    checkCaption(substr_count($literal, '%s') === (str_contains($literal, '@%s') ? 2 : 1), 'fallback ' . ($i + 1) . ' retains formatting placeholders');
}
$source = file_get_contents(__DIR__ . '/../src/LLM/LyraArtist.php');
preg_match('/\$this->llm->botSay\("([^\n]*краски на сегодня закончились[^\n]*)"\);/u', $source, $limit);
checkCaption(isset($limit[1]) && !preg_match('/копыт|ртом|во рту|не суди строго|намазюкала|простите/iu', $limit[1]), 'daily limit message has no self-deprecation');
checkCaption(isset($limit[1]) && str_contains($limit[1], '@$username') && str_contains($limit[1], '$limit рисунков в день'), 'daily limit retains recipient and numeric limit');
echo "PASS: $pass, FAIL: $fail\n";
if (!$fail) echo "ALL PASS\n";
exit($fail ? 1 : 0);
