<?php
use LLM\RouterAIProvider;
/**
 * Смоук-тест провайдера RouterAI.
 *
 * Запуск (офлайн, без сети — проверка контракта класса):
 *   php tests/test_routerai_provider.php
 *
 * Запуск живого запроса к routerai.ru (нужен ключ):
 *   php tests/test_routerai_provider.php <API_KEY> [model]
 *
 * Ключ НЕ хранится в коде и не коммитится — передаётся аргументом.
 */

require_once __DIR__ . '/../autoload.php'; // MLP-248: классы — автозагрузкой

$failures = 0;
function check($cond, $label) {
    global $failures;
    if ($cond) {
        echo "  [OK] $label\n";
    } else {
        echo "  [FAIL] $label\n";
        $failures++;
    }
}

echo "== Offline: контракт класса ==\n";
check(class_exists(RouterAIProvider::class), 'класс RouterAIProvider загружается');
check(in_array('LLM\\LLMProviderInterface', class_implements(RouterAIProvider::class)), 'реализует LLMProviderInterface');

// Пустой ключ должен выбрасывать исключение до сетевого вызова.
$threw = false;
try {
    (new RouterAIProvider(''))->askChat([['role' => 'user', 'content' => 'ping']], 'sys');
} catch (Exception $e) {
    $threw = (strpos($e->getMessage(), 'RouterAI API key is missing') !== false);
}
check($threw, 'пустой ключ -> исключение "API key is missing"');

// Онлайн-часть: реальный запрос, если передан ключ.
$apiKey = $argv[1] ?? null;
if ($apiKey) {
    $model = $argv[2] ?? 'openai/gpt-4o-mini';
    echo "\n== Online: живой запрос к routerai.ru (model=$model) ==\n";
    try {
        $provider = new RouterAIProvider($apiKey, $model);
        $reply = $provider->askChat(
            [['role' => 'user', 'content' => 'Ответь одним словом: "понятно".']],
            'Ты тестовый ассистент. Отвечай кратко.'
        );
        check(is_string($reply) && $reply !== '', 'получен непустой ответ: ' . mb_substr((string)$reply, 0, 80));
    } catch (Exception $e) {
        check(false, 'запрос упал: ' . $e->getMessage());
    }
} else {
    echo "\n(Online-часть пропущена: ключ не передан)\n";
}

echo "\n" . ($failures === 0 ? "ALL PASS\n" : "FAILURES: $failures\n");
exit($failures === 0 ? 0 : 1);
