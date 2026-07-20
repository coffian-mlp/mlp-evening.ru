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
foreach (['heart', 'fire', 'wow', 'think', 'party', 'cool'] as $t) {
    check(ReactionParser::extract("[РЕАКЦИЯ: $t] x")['reaction'] === $t, "реакция $t");
}

echo "\n== null ==\n";
$r = ReactionParser::extract(null);
check($r['reaction'] === null && $r['text'] === '', 'null -> пусто');

echo "\n" . ($fail === 0 ? "ALL PASS\n" : "FAILURES: $fail\n");
exit($fail === 0 ? 0 : 1);
