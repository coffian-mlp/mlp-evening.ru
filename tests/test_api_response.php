<?php
/**
 * Юнит-тест pure-ядра Api\Response (MLP-262, AR6-4).
 *
 * payload() — единый формат JSON-ответа {success, message, type, data};
 * classify() — разделение пользовательских (Core\UserError) и системных
 * текстов исключений (перенос политики MLP-261 в класс).
 *
 * БД не нужна: методы — чистые статики (echo/exit живут в обёртках json/ok/fail/caught).
 *
 * Запуск: php tests/test_api_response.php
 */

require_once __DIR__ . '/../autoload.php';

use Api\Response;
use Core\UserError;

$fail = 0;
function ok($cond, $label) {
    global $fail;
    echo ($cond ? "  [OK] " : "  [FAIL] ") . $label . "\n";
    if (!$cond) $fail++;
}

echo "== payload(): формат идентичен sendResponse ==\n";
$p = Response::payload(true, "Готово", 'success', ['id' => 7]);
ok($p === ['success' => true, 'message' => 'Готово', 'type' => 'success', 'data' => ['id' => 7]], 'полный набор полей в правильном порядке');

$p = Response::payload(false, "Ошибка");
ok($p['success'] === false && $p['type'] === 'success' && $p['data'] === [], 'дефолты как у sendResponse (type=success, data=[])');

// Эталон — точное выражение из исторической sendResponse() (без JSON_UNESCAPED_UNICODE).
$legacy = json_encode(['success' => true, 'message' => 'Пак создан! 🎉', 'type' => 'success', 'data' => []]);
ok(json_encode(Response::payload(true, 'Пак создан! 🎉')) === $legacy, 'json_encode даёт байт-в-байт формат sendResponse (включая \u-эскейпы кириллицы)');

echo "== classify(): UserError — текст наружу, лога нет ==\n";
$c = Response::classify(new UserError("Файл слишком большой."), "Аватар: ");
ok($c['message'] === "Аватар: Файл слишком большой.", 'текст UserError с префиксом');
ok($c['log'] === null, 'UserError не логируется');

$c = Response::classify(new UserError("Только ZIP архивы!"));
ok($c['message'] === "Только ZIP архивы!", 'без префикса — текст как есть');

echo "== classify(): системное — общий текст, лог с деталями ==\n";
$e = new RuntimeException("SQLSTATE[42S22]: Column not found");
$c = Response::classify($e, "Импорт: ");
ok($c['message'] === "Импорт: Что-то пошло не так. Попробуй ещё раз позже.", 'общий текст с префиксом');
ok(is_string($c['log']) && str_contains($c['log'], 'RuntimeException') && str_contains($c['log'], 'SQLSTATE[42S22]'), 'лог содержит класс и оригинальный текст');
ok(str_contains($c['log'], basename(__FILE__)), 'лог содержит file:line источника');
ok(!str_contains($c['message'], 'SQLSTATE'), 'системный текст НЕ попадает в message');

$c = Response::classify(new TypeError("Argument #1 must be int"));
ok($c['message'] === "Что-то пошло не так. Попробуй ещё раз позже.", 'TypeError (не Exception) тоже классифицируется');

echo "\n";
if ($fail > 0) {
    echo "FAIL: $fail\n";
    exit(1);
}
echo "ALL PASS\n";
