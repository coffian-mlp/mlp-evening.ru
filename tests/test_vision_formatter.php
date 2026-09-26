<?php
use LLM\VisionFormatter;
/**
 * Юнит-тест VisionFormatter::expand() — разворачивание картинок чата в мультимодальный формат.
 * Запуск: php tests/test_vision_formatter.php
 */

require_once __DIR__ . '/../src/LLM/VisionFormatter.php';

$fail = 0;
function check($cond, $label) {
    global $fail;
    echo ($cond ? "  [OK] " : "  [FAIL] ") . $label . "\n";
    if (!$cond) $fail++;
}

$base = 'https://mlp-evening.ru';

echo "== Сообщение с картинкой -> мультимодальный content ==\n";
$in = [['role' => 'user', 'content' => '[15:00] CoFFian: @Lyra ![изображение.png](/upload/chat/chat_abc.png) - что тут?']];
$out = VisionFormatter::expand($in, $base);
$c = $out[0]['content'];
check(is_array($c), 'content стал массивом (мультимодальный)');
check($c[0]['type'] === 'text' && strpos($c[0]['text'], 'что тут?') !== false, 'текст сохранён (без markdown-картинки)');
check(strpos($c[0]['text'], '![') === false, 'markdown-картинка убрана из текста');
$img = end($c);
check($img['type'] === 'image_url', 'есть часть image_url');
check($img['image_url']['url'] === 'https://mlp-evening.ru/upload/chat/chat_abc.png', 'относительный путь -> абсолютный URL');

echo "\n== Абсолютный URL картинки не трогаем (кроме оборачивания) ==\n";
$out = VisionFormatter::expand([['role'=>'user','content'=>'![x](https://example.com/pic.jpg)']], $base);
check(end($out[0]['content'])['image_url']['url'] === 'https://example.com/pic.jpg', 'абсолютный URL сохранён как есть');

echo "\n== Без картинок — не меняем ==\n";
$in = [['role' => 'assistant', 'content' => '[15:01] Lyra: привет!']];
$out = VisionFormatter::expand($in, $base);
check($out[0]['content'] === '[15:01] Lyra: привет!', 'обычный текст остаётся строкой');

echo "\n== Не-картиночная ссылка игнорируется ==\n";
$out = VisionFormatter::expand([['role'=>'user','content'=>'смотри [док](/upload/chat/file.pdf)']], $base);
check($out[0]['content'] === 'смотри [док](/upload/chat/file.pdf)', 'обычная ссылка (не ![], не картинка) не трогается');

echo "\n== Только картинка, без текста ==\n";
$out = VisionFormatter::expand([['role'=>'user','content'=>'![](/upload/chat/x.gif)']], $base);
$c = $out[0]['content'];
check(is_array($c) && count($c) === 1 && $c[0]['type'] === 'image_url', 'только image_url, без пустого текста');

echo "\n== Только свежие сообщения: старую картинку из скроллбэка НЕ шлём ==\n";
$ctx = [
    ['role' => 'user', 'content' => 'старое ![old](/upload/chat/old.png)'], // вне окна RECENT_MSGS
    ['role' => 'user', 'content' => 'бла'],
    ['role' => 'user', 'content' => 'бла бла'],
    ['role' => 'user', 'content' => 'свежее ![new](/upload/chat/new.png)'],  // свежее — в окне
];
$out = VisionFormatter::expand($ctx, $base); // webroot=null -> URL-режим
check(is_string($out[0]['content']), 'старая картинка (вне окна) НЕ развёрнута');
check(is_array($out[3]['content']), 'свежая картинка развёрнута');

echo "\n== Локальный файл -> base64-превью с ресайзом (нужен GD) ==\n";
if (extension_loaded('gd')) {
    $root = sys_get_temp_dir() . '/vf_' . getmypid();
    @mkdir($root . '/upload/chat', 0777, true);
    $im = imagecreatetruecolor(2000, 1500); // намеренно крупная — проверим уменьшение
    imagefilledrectangle($im, 0, 0, 2000, 1500, imagecolorallocate($im, 10, 120, 200));
    imagepng($im, $root . '/upload/chat/t.png');

    $out = VisionFormatter::expand([['role' => 'user', 'content' => 'вот ![p](/upload/chat/t.png)']], 'https://x', $root);
    $img = end($out[0]['content']);
    check(strpos($img['image_url']['url'], 'data:image/jpeg;base64,') === 0, 'локальный файл -> data:image/jpeg;base64 (не URL)');
    $rawImg = base64_decode(substr($img['image_url']['url'], strlen('data:image/jpeg;base64,')));
    $sz = @getimagesizefromstring($rawImg);
    check($sz && max($sz[0], $sz[1]) <= 1024, 'превью ужато до <=1024px (было 2000)');
    check(strlen($rawImg) < 300000, 'превью весит < 300 КБ');

    @unlink($root . '/upload/chat/t.png'); @rmdir($root . '/upload/chat'); @rmdir($root . '/upload'); @rmdir($root);
} else {
    echo "  (GD недоступен локально — кейс пропущен, проверим на проде)\n";
}

echo "\n== expandStickers: коды стикеров -> markdown (MLP-292) ==\n";
$map = ['ponypolkadance' => '/upload/stickers/polka_thumb.webp', 'lyra_sit' => '/upload/stickers/lyra.png'];
$msgs = [
    ['role' => 'user', 'content' => 'старое сообщение :ponypolkadance:'],           // вне окна
    ['role' => 'user', 'content' => 'смотри :ponypolkadance: и ещё :lyra_sit:'],
    ['role' => 'user', 'content' => 'а это :neizvestnyj: код и просто 10:30 время'],
    ['role' => 'user', 'content' => ['уже', 'мультимодальное']],
];
$out = VisionFormatter::expandStickers($msgs, $map, 3);
check($out[0]['content'] === 'старое сообщение :ponypolkadance:', 'вне окна — код остаётся текстом');
check($out[1]['content'] === 'смотри ![стикер :ponypolkadance:](/upload/stickers/polka_thumb.webp) и ещё ![стикер :lyra_sit:](/upload/stickers/lyra.png)',
    'известные коды -> markdown с alt «стикер», два в одном сообщении');
check($out[2]['content'] === 'а это :neizvestnyj: код и просто 10:30 время', 'неизвестный код и время не трогаются');
check(is_array($out[3]['content']), 'мультимодальный content не тронут');
check(VisionFormatter::expandStickers([['role' => 'user', 'content' => ':a:']], [], 3)[0]['content'] === ':a:', 'пустая карта — вход нетронут');

echo "\n== Локальный файл, которого нет (MLP-357) ==\n";
$webroot = sys_get_temp_dir() . '/mlp_vf_test_' . getmypid();
@mkdir($webroot . '/upload/lyra', 0777, true);
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
file_put_contents($webroot . '/upload/lyra/real.png', $png);
$missing = [['role' => 'user', 'content' => '[21:34] TotallyNotAPony: лови! ![рисунок](/upload/lyra/lyra_9d2f1c7e40aa3_8c11b2f7.jpg)']];
$out = VisionFormatter::expand($missing, $base, $webroot);
check($out[0]['content'] === $missing[0]['content'], 'прецедент 26.09: файла нет — картинка не уходит модели (раньше — URL с 404 и код 1210)');
$present = [['role' => 'user', 'content' => '![рисунок](/upload/lyra/real.png)']];
$out = VisionFormatter::expand($present, $base, $webroot);
$img = is_array($out[0]['content']) ? end($out[0]['content']) : null;
check($img !== null && $img['type'] === 'image_url', 'файл есть — картинка уходит модели');
check($img !== null && (str_starts_with($img['image_url']['url'], 'data:image/') || $img['image_url']['url'] === $base . '/upload/lyra/real.png'), 'превью data-URI или абсолютный URL');
$q = [['role' => 'user', 'content' => '![рисунок](/upload/lyra/real.png?v=2)']];
check(is_array(VisionFormatter::expand($q, $base, $webroot)[0]['content']), 'параметры в адресе не мешают найти файл');
@unlink($webroot . '/upload/lyra/real.png'); @rmdir($webroot . '/upload/lyra'); @rmdir($webroot . '/upload'); @rmdir($webroot);

echo "\n" . ($fail === 0 ? "ALL PASS\n" : "FAILURES: $fail\n");
exit($fail === 0 ? 0 : 1);
