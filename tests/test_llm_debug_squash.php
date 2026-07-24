<?php
/**
 * Юнит-тест MLP-298: LlmDebugLog::squash — подготовка запроса/ответа нейронки
 * к записи в debug-журнал: массив → JSON, base64-картинки → маркер с размером
 * (и data-URI, и b64_json ответа images API), потолок длины.
 *
 * БД не нужна. Запуск: php tests/test_llm_debug_squash.php
 */

require_once __DIR__ . '/../autoload.php';

use LLM\LlmDebugLog;

$fail = 0;
function ok($cond, $label) {
    global $fail;
    echo ($cond ? "  [OK] " : "  [FAIL] ") . $label . "\n";
    if (!$cond) $fail++;
}

echo "== массив → читаемый JSON ==\n";
$s = LlmDebugLog::squash(['system' => 'промпт', 'messages' => [['role' => 'user', 'content' => 'привет']]]);
ok(str_contains($s, 'промпт') && str_contains($s, 'привет'), 'кириллица без \u-эскейпов');
ok(LlmDebugLog::squash('уже строка') === 'уже строка', 'строка не трогается');

echo "\n== base64-картинки вырезаются ==\n";
$b64 = str_repeat('AbCd0123+/', 20);
$s = LlmDebugLog::squash(['content' => [['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . $b64]]]]);
ok(!str_contains($s, $b64), 'data-URI не попал в журнал');
ok(str_contains($s, '[b64-картинка'), 'вместо картинки — маркер с размером');

$s = LlmDebugLog::squash('{"data":[{"b64_json":"' . $b64 . '"}]}');
ok(!str_contains($s, $b64), 'b64_json из ответа images API вырезан');
ok(str_contains($s, 'картинка'), 'маркер на месте');

echo "\n== короткий base64 не трогаем (это не картинка) ==\n";
$s = LlmDebugLog::squash('подпись data:image/png;base64,AAAA конец');
ok(str_contains($s, 'base64,AAAA'), 'короткая строка (<50) остаётся как есть');

echo "\n== потолок длины ==\n";
$s = LlmDebugLog::squash(str_repeat('щ', LlmDebugLog::MAX_FIELD + 100));
ok(mb_strlen($s) <= LlmDebugLog::MAX_FIELD + 20, 'длина ограничена MAX_FIELD');
ok(str_contains($s, '[обрезано]'), 'обрезка помечена');

echo "\n" . ($fail === 0 ? "ALL PASS\n" : "FAILURES: $fail\n");
exit($fail === 0 ? 0 : 1);
