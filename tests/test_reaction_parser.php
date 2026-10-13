<?php
use LLM\ReactionParser;
/**
 * Юнит-тест ReactionParser::extract() — извлечение маркера реакции из ответа бота.
 * Запуск: php tests/test_reaction_parser.php
 */

require_once __DIR__ . '/../src/LLM/ReactionParser.php';

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
check(ReactionParser::target(ReactionParser::extract('[REACT: pie 124]'), $context, 123) === null, 'bot message cannot be reaction target');
check(ReactionParser::target(ReactionParser::extract('[REACT: pie]'), $context, 123) === 123, 'legacy marker preserves default target');
check(ReactionParser::extract('[REACT: pie -123] text')['reaction'] === null, 'malformed ID rejected');
check(ReactionParser::extract('Ответ [РЕАКЦИЯ: pie 123')['text'] === 'Ответ', 'truncated marker does not leak into chat');

echo "\n== null ==\n";
$r = ReactionParser::extract(null);
check($r['reaction'] === null && $r['text'] === '', 'null -> пусто');

echo "\n" . ($fail === 0 ? "ALL PASS\n" : "FAILURES: $fail\n");
exit($fail === 0 ? 0 : 1);
