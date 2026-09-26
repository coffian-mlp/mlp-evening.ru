<?php
use LLM\OpenAIProvider;
use LLM\OpenRouterProvider;
use LLM\RouterAIProvider;
/**
 * Регрессионный гард бюджета ответа OpenAI-совместимых провайдеров (MLP-347).
 *
 * У reasoning-моделей рассуждения расходуют тот же max_tokens, что и текст ответа.
 * При лимите 2000 GLM 5.3 тратил на рассуждения до ~1950 токенов, и ответ режиссёра
 * сцены обрывался на полуслове («…a mint-green unicorn with a mint-white mane, golden»),
 * а художнице уходила обрезанная сцена. Нижняя граница 4000 — двойной запас над
 * наблюдаемым потолком рассуждений.
 *
 * БД и сеть не нужны. Запуск: php tests/test_provider_token_budget.php
 */
require_once __DIR__ . '/../autoload.php';

$fail = 0;
function ok($cond, $label) {
    global $fail;
    echo ($cond ? "  [OK] " : "  [FAIL] ") . $label . "\n";
    if (!$cond) $fail++;
}

echo "== Бюджет ответа с запасом на рассуждения ==\n";
foreach ([RouterAIProvider::class, OpenRouterProvider::class, OpenAIProvider::class] as $class) {
    $budget = (int)$class::MAX_TOKENS;
    ok($budget >= 4000, "$class::MAX_TOKENS = $budget (>= 4000)");
}

echo "\n";
if ($fail > 0) { echo "FAIL: $fail\n"; exit(1); }
echo "ALL PASS\n";
