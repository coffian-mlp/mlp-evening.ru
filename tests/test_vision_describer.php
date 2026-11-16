<?php
/**
 * Юнит-тест LLM\VisionDescriber (MLP-268) — вспомогательная vision-модель.
 * Модель и кеш инжектируются (фейк + FileCache во временном каталоге) —
 * сеть и боевой cache/ не трогаются.
 *
 * Запуск: php tests/test_vision_describer.php
 */
require_once __DIR__ . '/../autoload.php';

use Core\FileCache;
use LLM\VisionDescriber;

$fail = 0;
function ok($cond, $label) {
    global $fail;
    echo ($cond ? "  [OK] " : "  [FAIL] ") . $label . "\n";
    if (!$cond) $fail++;
}

$root = sys_get_temp_dir() . '/mlp_vd_test_' . getmypid();
$cache = new FileCache('', $root);
$calls = 0;
$fake = function ($imagePart) use (&$calls) { $calls++; return "рисунок пони с лирой"; };

try {
    echo "== describe: вызов модели + кеш ==\n";
    $d1 = VisionDescriber::describe('https://example.com/pic.png', $fake, $cache);
    ok($d1 === 'рисунок пони с лирой', 'первый вызов — описание от модели');
    ok($calls === 1, 'модель вызвана один раз');

    $d2 = VisionDescriber::describe('https://example.com/pic.png', $fake, $cache);
    ok($d2 === $d1 && $calls === 1, 'повтор — из кеша, модель НЕ вызвана');

    echo "== сбойные ответы не кешируются ==\n";
    $empty = function () { return "   "; };
    ok(VisionDescriber::describe('https://example.com/e.png', $empty, $cache) === null, 'пустой ответ → null');
    $again = VisionDescriber::describe('https://example.com/e.png', $fake, $cache);
    ok($again === 'рисунок пони с лирой', 'следующая попытка снова зовёт модель (пустое не закешировано)');

    $boom = function () { throw new RuntimeException('provider down'); };
    ok(VisionDescriber::describe('https://example.com/b.png', $boom, $cache) === null, 'исключение модели → null (не наружу)');

    echo "== не-картинка ==\n";
    ok(VisionDescriber::describe('https://example.com/doc.pdf', $fake, $cache) === null, 'pdf → null без вызова модели');

    echo "== обрезка длины ==\n";
    $text754 = str_repeat('я', 753) . '✓';
    $full = VisionDescriber::describe('https://example.com/754.png', fn() => $text754, $cache);
    ok($full === $text754, '754 символа обычной картинки сохраняются целиком');
    ok(($cache->get(md5('https://example.com/754.png'), 604800)['d'] ?? null) === $text754, 'прежний ключ кеша хранит все 754 символа');
    $text1000 = str_repeat('я', 999) . '✓';
    ok(VisionDescriber::describe('https://example.com/1000.png', fn() => $text1000, $cache) === $text1000, 'ровно 1000 символов сохраняются без многоточия');
    $text1200 = str_repeat('а🙂', 600);
    $d = VisionDescriber::describe('https://example.com/1200.png', fn() => $text1200, $cache);
    ok($d === str_repeat('а🙂', 499) . 'а…', '1200 символов обрезаются до 999 и многоточия');
    ok(mb_strlen($d) === 1000 && mb_check_encoding($d, 'UTF-8'), 'обрезка сохраняет корректный Unicode и предел 1000 символов');

    echo "== maybeDescribe: замена в последних сообщениях ==\n";
    $msgs = [
        ['role' => 'user', 'content' => 'старое ![x](https://example.com/old.png)'],   // за окном RECENT_MSGS
        ['role' => 'user', 'content' => 'без картинок'],
        ['role' => 'assistant', 'content' => 'ответ'],
        ['role' => 'user', 'content' => 'глянь ![мем](https://example.com/pic.png) смешно же'],
    ];
    $out = VisionDescriber::maybeDescribe($msgs, $fake, $cache);
    ok(str_contains($out[3]['content'], '[Картинка: рисунок пони с лирой]'), 'свежая картинка заменена описанием');
    ok(!str_contains($out[3]['content'], '!['), 'markdown-синтаксиса не осталось');
    ok(str_contains($out[0]['content'], '![x]'), 'старое сообщение (вне окна) не тронуто');

    $multi = [['role' => 'user', 'content' => ['уже', 'массив']]];
    ok(VisionDescriber::maybeDescribe($multi, $fake, $cache) === $multi, 'мультимодальный content пропускается (анти-рекурсия)');

    echo "== ошибка модели в maybeDescribe — сообщение нетронуто ==\n";
    $msgs2 = [['role' => 'user', 'content' => 'вот ![b](https://example.com/b2.png)']];
    $out2 = VisionDescriber::maybeDescribe($msgs2, $boom, $cache);
    ok($out2[0]['content'] === $msgs2[0]['content'], 'markdown остаётся при сбое помощника');

    echo "== метка [Стикер: …] по alt из expandStickers (MLP-292) ==\n";
    $stickerModel = function () { return 'мятная пони танцует польку'; };
    $msgs3 = [['role' => 'user', 'content' => '![стикер :ponypolkadance:](https://example.com/polka.webp)']];
    $out3 = VisionDescriber::maybeDescribe($msgs3, $stickerModel, $cache);
    ok(str_contains($out3[0]['content'], '[Стикер: мятная пони танцует польку]'), 'alt «стикер …» -> метка [Стикер: …]');
    $msgs4 = [['role' => 'user', 'content' => '![просто фото](https://example.com/photo4.png)']];
    $out4 = VisionDescriber::maybeDescribe($msgs4, $stickerModel, $cache);
    ok(str_contains($out4[0]['content'], '[Картинка: '), 'обычный alt -> метка [Картинка: …] как раньше');

    echo "\n== Кеш неудач (MLP-357) ==\n";
    $real1210 = 'RouterAI Invalid Response: {"error":"{\\"error\\":{\\"message\\":\\"Provider returned error\\",\\"code\\":400,\\"metadata\\":{\\"raw\\":\\"{\\\\\\"error\\\\\\":{\\\\\\"code\\\\\\":\\\\\\"1210\\\\\\"}}\\"}}}"}';
    ok(VisionDescriber::isPermanentFailure($real1210), 'ответ провайдера с кодом 1210 — постоянная ошибка');
    ok(VisionDescriber::isPermanentFailure('RouterAI HTTP Error 404: not found'), 'HTTP 404 — постоянная');
    ok(VisionDescriber::isPermanentFailure('Provider returned error, "code": 415'), '«code»: 415 — постоянная');
    ok(!VisionDescriber::isPermanentFailure('RouterAI HTTP Error 429: rate limit'), '429 — временная (лимит)');
    ok(!VisionDescriber::isPermanentFailure('RouterAI HTTP Error 408: timeout'), '408 — временная');
    ok(!VisionDescriber::isPermanentFailure('RouterAI HTTP Error 502: bad gateway'), '5xx — временная');
    ok(!VisionDescriber::isPermanentFailure('RouterAI cURL Error: Operation timed out after 60002 milliseconds'), 'таймаут — временный');

    $rejected = function () use ($real1210) { throw new RuntimeException($real1210); };
    ok(VisionDescriber::describe('https://example.com/broken.png', $rejected, $cache) === null, 'отвергнутая картинка → null');
    $before = $calls;
    ok(VisionDescriber::describe('https://example.com/broken.png', $fake, $cache) === null && $calls === $before, 'в течение часа — без повторного вызова модели');
    $transient = function () { throw new RuntimeException('RouterAI cURL Error: Operation timed out after 60002 milliseconds'); };
    VisionDescriber::describe('https://example.com/slow.png', $transient, $cache);
    ok(VisionDescriber::describe('https://example.com/slow.png', $fake, $cache) === 'рисунок пони с лирой', 'после таймаута — пробуем снова и получаем описание');

} finally {
    foreach (glob("$root/*.json") ?: [] as $f) @unlink($f);
    @rmdir($root);
}

echo "\n";
if ($fail > 0) { echo "FAIL: $fail\n"; exit(1); }
echo "ALL PASS\n";
