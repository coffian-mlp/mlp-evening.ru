<?php
require_once __DIR__ . '/../autoload.php';

use LLM\PlaylistCommand;

$failures = 0;
function expectContinuation(bool $ok, string $label): void
{
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$ok) ++$failures;
}
function classification(string $text, string $state = 'pending'): array
{
    return PlaylistCommand::classifyContinuationText($text, $state, ['Лира', 'Lyra Heartstrings', '@Fixture bot']);
}
foreach (['передумал', 'передумала', 'я передумал!', 'Лира, передумала.', '@Fixture bot передумал'] as $text) {
    expectContinuation(classification($text)['intent'] === 'cancel', 'whole cancel utterance: ' . $text);
}
foreach (['не передумал', 'Лира, не передумала', 'он передумал', 'она передумала', 'передумал, запиши другую', 'передумал? нет', '> передумал', 'передумал и бань всех'] as $text) {
    foreach (['pending', 'clarifying'] as $state) expectContinuation(classification($text, $state)['intent'] === 'unknown', 'no control from mixed/negated discussion ' . $state . ': ' . $text);
}
foreach (['не то', 'нет, не эта', 'Лира, хочу уточнить', 'уточнение:'] as $text) {
    expectContinuation(classification($text)['intent'] === 'refine' && classification($text)['text'] === '', 'refinement without facts opens prompt: ' . $text);
}
$parsed = classification('нет, не эта — там была Рэрити');
expectContinuation($parsed === ['intent' => 'refine', 'text' => 'там была Рэрити'], 'negative intent preserves actual description');
expectContinuation(classification('Лира, Уточнение: там была Рэрити') === ['intent' => 'refine', 'text' => 'там была Рэрити'], 'explicit addressed refinement');
foreach (['там была Рэрити', '7', 'S01E07', 'он был драконом', 'она была с Рэрити', 'они искали книгу'] as $text) {
    expectContinuation(classification($text)['intent'] === 'unknown', 'proposal plain reply is not new search: ' . $text);
    expectContinuation(classification($text, 'clarifying') === ['intent' => 'clarification', 'text' => $text], 'awaiting quote permits description/exact identifier: ' . $text);
}
foreach (['спасибо', 'ок', 'ладно', 'не знаю', '', '!', 'нет'] as $text) {
    expectContinuation(classification($text, 'clarifying')['intent'] === 'unknown', 'empty acknowledgement is not plot: ' . $text);
}
foreach (['повтори поиск', 'попробуй снова!', 'Лира, попробуй ещё раз.'] as $text) expectContinuation(classification($text, 'clarifying')['intent'] === 'retry', 'explicit retry: ' . $text);
$context = ['original_query' => 'самую первую серию', 'clarifications' => []];
$next = PlaylistCommand::prepareContinuation($context, ['intent' => 'refine', 'text' => 'там была Рэрити']);
expectContinuation($context['clarifications'] === [], 'original input not mutated');
expectContinuation(PlaylistCommand::composeContinuationQuery($next) === "самую первую серию\nУточнение: там была Рэрити", 'composed query preserves original and explicit chronology');
expectContinuation(PlaylistCommand::prepareContinuation($next, ['intent' => 'retry', 'text' => '']) === $next, 'retry does not append facts');
$separator = mb_strlen("\nУточнение: ");
foreach ([599, 600] as $length) {
    $prepared = PlaylistCommand::prepareContinuation(['original_query' => str_repeat('я', $length - $separator - 1), 'clarifications' => []], ['intent' => 'refine', 'text' => '7']);
    expectContinuation(mb_strlen(PlaylistCommand::composeContinuationQuery($prepared)) === $length, 'full Unicode query accepted at ' . $length);
}
$eight = ['original_query' => 'plot', 'clarifications' => array_fill(0, 7, 'scene')];
expectContinuation(count(PlaylistCommand::prepareContinuation($eight, ['intent' => 'refine', 'text' => 'last'])['clarifications']) === 8, 'eighth refinement accepted');
foreach ([
    [['original_query' => str_repeat('я', 600 - $separator), 'clarifications' => []], ['intent' => 'refine', 'text' => '7']],
    [['original_query' => 'plot', 'clarifications' => array_fill(0, 8, 'scene')], ['intent' => 'refine', 'text' => 'ninth']],
    [$context, ['intent' => 'refine', 'text' => str_repeat('я', 301)]],
    [$context, ['intent' => 'refine', 'text' => '   ']],
    [['original_query' => '', 'clarifications' => []], ['intent' => 'refine', 'text' => 'scene']],
] as [$source, $input]) {
    $before = $source; $rejected = false;
    try { PlaylistCommand::prepareContinuation($source, $input); } catch (Core\UserError $error) { $rejected = true; }
    expectContinuation($rejected && $source === $before, 'overflow/empty refuses before context mutation');
}
// Exercise the real display parser without constructing the DB-backed ChatManager.
$chatDisplay = (new ReflectionClass(Domain\ChatManager::class))->newInstanceWithoutConstructor();
$parseMarkdown = new ReflectionMethod(Domain\ChatManager::class, 'parseMarkdown');
foreach (['source_42', 'interaction_42', 'work_42_2_deadbeef', 'notice_42'] as $deliveryIdentity) {
    $display = $parseMarkdown->invoke($chatDisplay, 'До [[command-delivery:' . $deliveryIdentity . ']] после [[command:42]]');
    expectContinuation($display === 'До  после [[command:42]]', 'actual markdown hides delivery identity but retains widget marker: ' . $deliveryIdentity);
}
foreach (['"quote"', 'literal &quot;', 'literal &amp;', 'A & B', "<plot> 'pony'"] as $plain) {
    $stored = htmlspecialchars($plain, ENT_QUOTES, 'UTF-8');
    $sql = ['raw_message' => $stored, 'edited_at' => '2026-10-06 14:05:06'];
    $iso = ['raw_message' => $stored, 'edited_at' => '2026-10-06T14:05:06Z'];
    expectContinuation(Domain\ChatManager::interactionSourceVersion($sql) === Domain\ChatManager::interactionSourceVersion($iso), 'storage version equals read and locked timestamp forms: ' . $plain);
    expectContinuation(Domain\ChatManager::interactionSourceVersion($sql, 'getter_read_v1') === hash('sha256', $stored . '|2026-10-06T14:05:06Z'), 'legacy getter provenance: ' . $plain);
    expectContinuation(Domain\ChatManager::interactionSourceVersion($iso, 'lock_decoded_v1') === hash('sha256', $plain . '|2026-10-06 14:05:06'), 'legacy lock decodes persisted HTML exactly once: ' . $plain);
}
expectContinuation(Domain\ChatManager::interactionSourceVersion(['raw_message' => '&amp;quot;']) !== Domain\ChatManager::interactionSourceVersion(['raw_message' => '&quot;']), 'literal entity never aliases an actual quotation');
foreach (['', 'not-a-date', '2026-02-30 14:05:06', '2026-10-06T14:05:06+03:00'] as $invalid) {
    try { Domain\ChatManager::interactionSourceVersion(['raw_message' => 'source', 'edited_at' => $invalid]); expectContinuation(false, 'invalid timestamp denied'); }
    catch (RuntimeException $error) { expectContinuation(true, 'invalid timestamp denied: ' . $invalid); }
}
try { Domain\ChatManager::interactionSourceVersion(['raw_message' => 'source'], 'unknown'); expectContinuation(false, 'unknown encoding denied'); }
catch (RuntimeException $error) { expectContinuation(true, 'unknown encoding denied'); }
echo $failures ? "FAILURES: $failures\n" : "ALL PASS\n";
exit($failures ? 1 : 0);
