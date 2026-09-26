<?php
use LLM\TokenBudget;
use LLM\TruncatedResponseException;
/**
 * Бюджет ответа OpenAI-совместимых провайдеров (MLP-347/348): потолок из настройки
 * ai_max_tokens и страховка от обрыва по finish_reason = "length".
 *
 * У reasoning-моделей рассуждения расходуют тот же max_tokens, что и текст ответа.
 * При лимите 2000 GLM 5.3 тратил на рассуждения до ~1950 токенов, и ответ режиссёра
 * сцены обрывался на полуслове («…a mint-green unicorn with a mint-white mane, golden»),
 * а художнице уходила обрезанная сцена. Живой RouterAI при упоре в потолок отвечает
 * finish_reason = "length" и нередко пустым content (весь бюджет ушёл на рассуждения).
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
/** Ответ API в формате choices[0]. */
function reply(?string $content, ?string $finish): array {
    $choice = ['message' => ['role' => 'assistant', 'content' => $content]];
    if ($finish !== null) {
        $choice['finish_reason'] = $finish;
    }
    return ['choices' => [$choice]];
}

echo "== Потолок по умолчанию ==\n";
ok(TokenBudget::DEFAULT >= 4000, 'DEFAULT = ' . TokenBudget::DEFAULT . ' (>= 4000: двойной запас над рассуждениями ~1950)');
ok(TokenBudget::MIN <= TokenBudget::DEFAULT && TokenBudget::DEFAULT <= TokenBudget::MAX, 'DEFAULT внутри [MIN, MAX]');

echo "\n== Настройка ai_max_tokens → потолок ==\n";
ok(TokenBudget::resolve(null) === TokenBudget::DEFAULT, 'нет настройки → по умолчанию');
ok(TokenBudget::resolve('') === TokenBudget::DEFAULT, 'пустое поле → по умолчанию');
ok(TokenBudget::resolve('0') === TokenBudget::DEFAULT, '0 → по умолчанию');
ok(TokenBudget::resolve(-5) === TokenBudget::DEFAULT, 'отрицательное → по умолчанию');
ok(TokenBudget::resolve('abc') === TokenBudget::DEFAULT, 'мусор → по умолчанию');
ok(TokenBudget::resolve('7000') === 7000, 'строка из формы 7000 → 7000');
ok(TokenBudget::resolve(8000) === 8000, 'число 8000 → 8000');
ok(TokenBudget::resolve(500) === TokenBudget::MIN, '500 → поднято до MIN ' . TokenBudget::MIN);
ok(TokenBudget::resolve(999999) === TokenBudget::MAX, '999999 → срезано до MAX ' . TokenBudget::MAX);

echo "\n== Разбор ответа: обычный ==\n";
ok(TokenBudget::contentOf(reply("  Сцена готова.  ", 'stop'), 'T') === 'Сцена готова.', 'stop → текст с обрезкой пробелов');
ok(TokenBudget::contentOf(reply('Текст', null), 'T') === 'Текст', 'без finish_reason → текст (прежнее поведение)');
ok(TokenBudget::contentOf(reply('', 'stop'), 'T') === '', 'stop с пустым текстом → пустая строка (молчание, как раньше)');
ok(TokenBudget::contentOf(reply(null, 'stop'), 'T') === null, 'content = null → null (провайдер бросит Invalid Response)');
ok(TokenBudget::contentOf([], 'T') === null, 'нет choices → null');
ok(TokenBudget::contentOf(['choices' => ['x']], 'T') === null, 'битый choices → null');

echo "\n== Разбор ответа: обрыв по потолку ==\n";
$cases = [
    'обрубок сцены' => ['In a cozy hangout, TotallyNotAPony, a mint-green unicorn with a mint-white mane, golden', 'In a cozy hangout, TotallyNotAPony, a mint-green unicorn with a mint-white mane, golden'],
    'пустой текст (всё ушло в рассуждения)' => ['', ''],
    'content = null' => [null, ''],
];
foreach ($cases as $label => [$content, $partial]) {
    $caught = null;
    try {
        TokenBudget::contentOf(reply($content, 'length'), 'RouterAI');
    } catch (TruncatedResponseException $e) {
        $caught = $e;
    }
    ok($caught !== null, "length, $label → TruncatedResponseException");
    ok($caught !== null && $caught->partial === $partial, "length, $label → обрубок сохранён для журнала");
    ok($caught !== null && strpos($caught->getMessage(), 'RouterAI') === 0 && strpos($caught->getMessage(), 'finish_reason=length') !== false, "length, $label → сообщение с провайдером и причиной");
}
ok(is_subclass_of(TruncatedResponseException::class, \Exception::class), 'TruncatedResponseException — Exception: прочие обработчики сбоев его ловят');

echo "\n";
if ($fail > 0) { echo "FAIL: $fail\n"; exit(1); }
echo "ALL PASS\n";
