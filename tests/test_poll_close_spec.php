<?php
/**
 * Юнит-тест LLMManager::parseCloseSpec (MLP-271, AR5-4) — pure-парсер
 * «закрой через N минут/часов/дней» для срока жизни опроса от бота.
 *
 * Запуск: php tests/test_poll_close_spec.php
 */
require_once __DIR__ . '/../autoload.php';

use LLM\LLMManager;

$fail = 0;
function ok($cond, $label) {
    global $fail;
    echo ($cond ? "  [OK] " : "  [FAIL] ") . $label . "\n";
    if (!$cond) $fail++;
}

$c = LLMManager::parseCloseSpec('/опрос Кто лучшая пони? Варианты: Лира, Бон-Бон, закрой через 10 минут');
ok($c['minutes'] === 10, '«закрой через 10 минут» → 10');
ok(!preg_match('/закрой/iu', $c['text']), 'фраза вырезана из текста');
ok(str_contains($c['text'], 'Бон-Бон'), 'остальной текст цел');

ok(LLMManager::parseCloseSpec('закрыть через 2 часа')['minutes'] === 120, '«закрыть через 2 часа» → 120');
ok(LLMManager::parseCloseSpec('закройся через 1 час')['minutes'] === 60, '«закройся через 1 час» → 60');
ok(LLMManager::parseCloseSpec('закрой через 30 мин')['minutes'] === 30, '«30 мин» → 30');
ok(LLMManager::parseCloseSpec('закрой через 45 м')['minutes'] === 45, '«45 м» → 45');
ok(LLMManager::parseCloseSpec('закрой через 3 ч')['minutes'] === 180, '«3 ч» → 180');
ok(LLMManager::parseCloseSpec('закрой через 2 дня')['minutes'] === 2880, '«2 дня» → 2880');
ok(LLMManager::parseCloseSpec('закрой через 1 день')['minutes'] === 1440, '«1 день» → 1440');

$c = LLMManager::parseCloseSpec('/опрос Просто вопрос? Варианты: да, нет');
ok($c['minutes'] === null, 'без фразы → null');
ok(str_contains($c['text'], 'Просто вопрос'), 'текст не тронут');

$c = LLMManager::parseCloseSpec('обсудим закрытие через час на созвоне');
ok($c['minutes'] === null, '«закрытие через час» (не императив) не матчится');

echo "\n";
if ($fail > 0) { echo "FAIL: $fail\n"; exit(1); }
echo "ALL PASS\n";
