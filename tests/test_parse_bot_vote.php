<?php
/**
 * Юнит-тест LLMManager::parseBotVote() — MLP-241 (бот голосует).
 * Разбор ответа бота: строка-«только число» = выбор (0-based), остальное = реплика.
 *
 * LLMManager тянет config.php: на чистом клоне мягко SKIP.
 *
 * Запуск: php tests/test_parse_bot_vote.php
 */

if (!file_exists(__DIR__ . '/../config.php')) {
    echo "SKIP: config.php отсутствует (нужен для загрузки LLMManager)\n";
    exit(0);
}

require_once __DIR__ . '/../src/LLM/LLMManager.php';

$fail = 0;
function ok($cond, $label) {
    global $fail;
    echo ($cond ? "  [OK] " : "  [FAIL] ") . $label . "\n";
    if (!$cond) $fail++;
}

echo "== Номер + реплика ==\n";
$r = LLMManager::parseBotVote("2\nО, голосование! Выбор сделан ^_^", 3);
ok($r['index'] === 1, 'выбор 2 → индекс 1 (0-based)');
ok($r['comment'] === 'О, голосование! Выбор сделан ^_^', 'реплика без строки-числа');

echo "\n== Число с точкой/скобкой ==\n";
ok(LLMManager::parseBotVote("3.\nтекст", 3)['index'] === 2, '«3.» распознан');
ok(LLMManager::parseBotVote("1)\nтекст", 3)['index'] === 0, '«1)» распознан');

echo "\n== Вне диапазона / нет числа → index=null, реплика сохранена ==\n";
$r = LLMManager::parseBotVote("Я выбираю Твайлайт, потому что она лучшая!", 3);
ok($r['index'] === null, 'нет строки-числа → null (voteOnPoll выберет сам)');
ok($r['comment'] === 'Я выбираю Твайлайт, потому что она лучшая!', 'вся строка ушла в реплику (не съедена как выбор)');
ok(LLMManager::parseBotVote("9\nтекст", 3)['index'] === null, 'номер вне диапазона → null');

echo "\n== Многострочная реплика ==\n";
$r = LLMManager::parseBotVote("1\nПервая строка.\nВторая строка.", 4);
ok($r['index'] === 0 && $r['comment'] === "Первая строка.\nВторая строка.", 'реплика из нескольких строк склеена');

echo "\n== Пусто ==\n";
$r = LLMManager::parseBotVote("", 3);
ok($r['index'] === null && $r['comment'] === '', 'пусто → null + пустая реплика');
$r = LLMManager::parseBotVote(null, 3);
ok($r['index'] === null, 'null → null');

echo "\n" . ($fail === 0 ? "ALL PASS\n" : "FAILURES: $fail\n");
exit($fail === 0 ? 0 : 1);
