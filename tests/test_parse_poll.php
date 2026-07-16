<?php
/**
 * Юнит-тест LLMManager::parsePoll() — MLP-240 (бот создаёт опрос).
 * Разбор ответа модели «вопрос + варианты построчно» в структуру опроса.
 *
 * LLMManager тянет ConfigManager→Database→config.php: на чистом клоне мягко SKIP.
 *
 * Запуск: php tests/test_parse_poll.php
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

echo "== Простой опрос ==\n";
$p = LLMManager::parsePoll("Любимая пони?\nТвайлайт\nПинки\nРарити");
ok($p !== null, 'распарсился');
ok($p['question'] === 'Любимая пони?', 'вопрос — первая строка');
ok($p['options'] === ['Твайлайт', 'Пинки', 'Рарити'], '3 варианта');

echo "\n== Нумерация и маркеры чистятся ==\n";
$p = LLMManager::parsePoll("Кто круче?\n1. Твайлайт\n2) Пинки\n- Рарити\n• Эпплджек");
ok($p['options'] === ['Твайлайт', 'Пинки', 'Рарити', 'Эпплджек'], 'префиксы 1. / 2) / - / • убраны');

echo "\n== Пустые строки игнорируются ==\n";
$p = LLMManager::parsePoll("\nВопрос\n\nA\n\nB\n");
ok($p !== null && $p['question'] === 'Вопрос' && $p['options'] === ['A', 'B'], 'пустые строки отброшены');

echo "\n== Недостаточно данных → null ==\n";
ok(LLMManager::parsePoll("Только вопрос\nОдин вариант") === null, 'вопрос + 1 вариант → null');
ok(LLMManager::parsePoll("") === null, 'пусто → null');
ok(LLMManager::parsePoll(null) === null, 'null → null');

echo "\n== Ограничение до 10 вариантов ==\n";
$many = "Вопрос?\n" . implode("\n", array_map(fn($i) => "Вариант $i", range(1, 15)));
$p = LLMManager::parsePoll($many);
ok(count($p['options']) === 10, '15 вариантов обрезаны до 10');

echo "\n" . ($fail === 0 ? "ALL PASS\n" : "FAILURES: $fail\n");
exit($fail === 0 ? 0 : 1);
