<?php
use LLM\ReactionParser;
/**
 * Юнит-тест ReactionParser::extract() — извлечение маркера реакции из ответа бота.
 * Запуск: php tests/test_reaction_parser.php
 */

require_once __DIR__ . '/../autoload.php';

$fail = 0;
function check($cond, $label) {
    global $fail;
    echo ($cond ? "  [OK] " : "  [FAIL] ") . $label . "\n";
    if (!$cond) $fail++;
}

echo "== Маркер + текст ==\n";
$r = ReactionParser::extract('[РЕАКЦИЯ: laugh] Ну ты даёшь!');
check($r['reaction'] === 'laugh', 'реакция laugh распознана');
check($r['text'] === 'Ну ты даёшь!', 'текст без маркера');

echo "\n== Только маркер (реакция без слов) ==\n";
$r = ReactionParser::extract('[РЕАКЦИЯ: heart]');
check($r['reaction'] === 'heart' && $r['text'] === '', 'только реакция, текст пуст');

echo "\n== Без маркера ==\n";
$r = ReactionParser::extract('просто ответ без реакции');
check($r['reaction'] === null && $r['text'] === 'просто ответ без реакции', 'нет реакции, текст не тронут');

echo "\n== Невалидная реакция — вырезаем маркер, реакции нет ==\n";
$r = ReactionParser::extract('[РЕАКЦИЯ: banana] привет!');
check($r['reaction'] === null, 'неизвестная реакция -> null');
check($r['text'] === 'привет!', 'маркер всё равно убран из текста');

echo "\n== Регистр и английские варианты ==\n";
check(ReactionParser::extract('[реакция: FIRE] жара')['reaction'] === 'fire', 'нижний регистр слова + верхний тип');
check(ReactionParser::extract('[REACT: wow] ого')['reaction'] === 'wow', 'англ. REACT');
check(ReactionParser::extract('[REACTION: cool]')['reaction'] === 'cool', 'англ. REACTION');

echo "\n== Новые реакции ==\n";
foreach (['heart', 'fire', 'wow', 'think', 'party', 'cool', 'eyes', 'skull', 'clown', 'hundred', 'poop', 'eggplant', 'hand', 'anchor', 'pie'] as $t) {
    check(ReactionParser::extract("[РЕАКЦИЯ: $t] x")['reaction'] === $t, "реакция $t");
    $only = ReactionParser::extract("[REACTION: $t]");
    check($only['reaction'] === $t && $only['text'] === '', "реакция $t без текста");
}

$context = [['role'=>'user','chat_message_id'=>123,'content'=>'[19:00] User: text'], ['role'=>'assistant','chat_message_id'=>124,'content'=>'bot']];
$r = ReactionParser::extract('[РЕАКЦИЯ: pie 123] Привет');
check($r['target'] === 123 && $r['text'] === 'Привет', 'explicit target parsed and marker stripped');
check(ReactionParser::target($r, $context, 999) === 123, 'valid real target takes precedence');
check(ReactionParser::target(ReactionParser::extract('[REACT: pie 999]'), $context, 123) === null, 'hallucinated target does not react to another message');
check(ReactionParser::target(ReactionParser::extract('[REACT: pie 124]'), $context, 123) === 124, 'visible bot message can be an explicit reaction target');
check(ReactionParser::target(ReactionParser::extract('[REACT: pie]'), $context, 123) === 123, 'legacy marker preserves default target');
check(ReactionParser::extract('[REACT: pie -123] text')['reaction'] === null, 'malformed ID rejected');
check(ReactionParser::extract('Ответ [РЕАКЦИЯ: pie 123')['text'] === 'Ответ', 'truncated marker does not leak into chat');

$batchContext = [];
$batchRaw = '[№34213] @CoFFian, держи! ';
for ($id = 34210; $id < 34222; $id++) {
    $batchContext[] = ['role'=>($id % 2 ? 'assistant':'user'),'chat_message_id'=>$id,'content'=>'Message '.$id];
    $batchRaw .= "[РЕАКЦИЯ: poop $id]\n";
}
$actions = ReactionParser::actions($batchRaw, $batchContext, 34210);
check(count($actions) === 10, 'multiple valid markers produce ten actions with a hard limit');
check(array_column($actions, 'target') === range(34210,34219), 'whole batch includes both human and bot messages');
check(ReactionParser::extract($batchRaw)['text'] === '@CoFFian, держи!', 'all reaction markers and source annotation removed');
$duplicateRaw = str_repeat('[REACT: poop 34210]', 20).'[REACT: poop 34211]';
check(count(ReactionParser::actions($duplicateRaw,$batchContext,34210)) === 2, 'duplicate markers do not use up the batch limit');
check(count(ReactionParser::actions('[REACT: poop 999999][REACT: heart 34210]', $batchContext,34211)) === 1, 'invalid target skipped independently of valid markers');
check(ReactionParser::actions('[REACT: poop]',$batchContext,34210) === [['target'=>34210,'reaction'=>'poop']], 'legacy single-marker action remains supported');
check(ReactionParser::actions('[REACT: poop]',$batchContext,999999) === [['target'=>999999,'reaction'=>'poop']], 'trusted legacy quote target may be outside current context; storage checks existence');
check(str_contains(ReactionParser::instruction($batchContext),'"id":34210'), 'IDs provided as separate server catalogue');
check(!str_contains(ReactionParser::instruction($batchContext),'Метки [№ID] перед текстом'), 'instruction no longer teaches inline numbering');

echo "\n== null ==\n";
$r = ReactionParser::extract(null);
check($r['reaction'] === null && $r['text'] === '', 'null -> пусто');

echo "\n" . ($fail === 0 ? "ALL PASS\n" : "FAILURES: $fail\n");
exit($fail === 0 ? 0 : 1);
