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
    $long = function () { return str_repeat('а', 2000); };
    $d = VisionDescriber::describe('https://example.com/long.png', $long, $cache);
    ok(mb_strlen($d) <= 600, 'описание обрезано до лимита (' . mb_strlen($d) . ')');

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

} finally {
    foreach (glob("$root/*.json") ?: [] as $f) @unlink($f);
    @rmdir($root);
}

echo "\n";
if ($fail > 0) { echo "FAIL: $fail\n"; exit(1); }
echo "ALL PASS\n";
