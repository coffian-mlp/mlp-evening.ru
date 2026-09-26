<?php
use LLM\LyraArtist;
use LLM\OpenAIProvider;
use LLM\OpenRouterProvider;
use LLM\RouterAIProvider;
/**
 * Юнит-тест таймаута на отдельный вызов (MLP-358): withTimeout() даёт копию провайдера с другим
 * таймаутом, общий экземпляр остаётся с 60 с; режиссёру сцены — 120 с. Без сети и БД.
 *
 * Запуск: php tests/test_provider_timeout.php
 */
require_once __DIR__ . '/../autoload.php';

$fail = 0;
function ok($cond, $label) {
    global $fail;
    echo ($cond ? "  [OK] " : "  [FAIL] ") . $label . "\n";
    if (!$cond) $fail++;
}
$timeout = function (object $p): int {
    return (int)(new ReflectionProperty($p, 'timeoutSec'))->getValue($p); // с PHP 8.1 доступ к private без setAccessible
};

foreach ([RouterAIProvider::class, OpenRouterProvider::class, OpenAIProvider::class] as $class) {
    echo "== {$class} ==\n";
    $base = new $class('key', 'model');
    $long = $base->withTimeout(120);
    ok($timeout($base) === 60, 'по умолчанию 60 с');
    ok($long instanceof $class && $long !== $base, 'withTimeout — новая копия того же класса');
    ok($timeout($long) === 120, 'у копии 120 с');
    ok($timeout($base) === 60, 'общий экземпляр не изменился');
    ok($timeout($base->withTimeout(1)) === 5, 'меньше 5 с не бывает');
}

echo "\n== Режиссёр сцены ==\n";
ok(LyraArtist::DIRECTOR_TIMEOUT === 120, 'режиссёру — 120 с (решение владельца 26.09)');

echo "\n";
if ($fail > 0) { echo "FAIL: $fail\n"; exit(1); }
echo "ALL PASS\n";
