<?php
/**
 * Юнит-тест Infra\Thumbnailer (MLP-258).
 *
 * Проверяем: уменьшение с сохранением пропорций (webp-вывод), отказ для
 * маленьких изображений (превью не нужно), отказ для анимированного GIF
 * (анимация потерялась бы), отказ для битого файла и путей вне
 * /upload/stickers/ (анти-traversal). Все отказы = null (деградация на оригинал).
 *
 * Требует GD (в Docker добавлен этим же тикетом); без GD тест SKIP.
 *
 * Запуск: php tests/test_thumbnailer.php
 */

require_once __DIR__ . '/../autoload.php';

use Infra\Thumbnailer;

if (!function_exists('imagewebp')) {
    echo "SKIP: GD/webp недоступен\n";
    exit(0);
}

$fail = 0;
function ok($cond, $label) {
    global $fail;
    echo ($cond ? "  [OK] " : "  [FAIL] ") . $label . "\n";
    if (!$cond) $fail++;
}

$root = dirname(__DIR__);
$dir = $root . '/upload/stickers';
if (!is_dir($dir)) mkdir($dir, 0775, true);

// Фикстуры — свои файлы в своей папке (владелец под тестом), уборка в конце.
$made = [];
function makePng(string $path, int $w, int $h): void {
    $im = imagecreatetruecolor($w, $h);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 120, 40, 160, 40));
    imagepng($im, $path);
    imagedestroy($im);
}

echo "== Большая картинка уменьшается до 128 по большей стороне ==\n";
$big = '/upload/stickers/test_thumb_big.png';
makePng($root . $big, 512, 256);
$made[] = $root . $big;
$thumb = Thumbnailer::createFor($big);
ok($thumb !== null, 'превью создано');
if ($thumb !== null) {
    $made[] = $root . $thumb;
    ok(str_ends_with($thumb, '_thumb.webp'), 'формат webp, суффикс _thumb');
    ok(strpos($thumb, '/upload/stickers/thumbs/') === 0, 'лежит в thumbs/');
    [$tw, $th] = getimagesize($root . $thumb);
    ok($tw === 128 && $th === 64, "пропорции сохранены (ожидание 128x64, получено {$tw}x{$th})");
}

echo "\n== Повторная генерация идемпотентна ==\n";
$thumb2 = Thumbnailer::createFor($big);
ok($thumb2 === $thumb, 'тот же путь превью');

echo "\n== Маленькая картинка — без превью (оригинал лёгкий) ==\n";
$small = '/upload/stickers/test_thumb_small.png';
makePng($root . $small, 64, 64);
$made[] = $root . $small;
ok(Thumbnailer::createFor($small) === null, '64x64 → null');

echo "\n== Анимированный GIF не ресайзится ==\n";
// Минимальный 2-кадровый GIF (2 Graphic Control Extension)
$gif = '/upload/stickers/test_thumb_anim.gif';
$gifData = "GIF89a" . str_repeat("\x00", 7)
    . "\x00\x21\xF9\x04" . str_repeat("\x00", 5)
    . "\x00\x21\xF9\x04" . str_repeat("\x00", 5) . "\x3B";
file_put_contents($root . $gif, $gifData);
$made[] = $root . $gif;
ok(Thumbnailer::isAnimatedGif($gifData) === true, 'эвристика видит 2 кадра');
ok(Thumbnailer::createFor($gif) === null, 'анимированный GIF → null');
ok(Thumbnailer::isAnimatedGif(file_get_contents($root . $big)) === false, 'PNG — не gif');

echo "\n== Битый файл и чужие пути → null ==\n";
$broken = '/upload/stickers/test_thumb_broken.png';
file_put_contents($root . $broken, 'это не картинка, это письмо Селестии');
$made[] = $root . $broken;
ok(Thumbnailer::createFor($broken) === null, 'битый файл → null');
ok(Thumbnailer::createFor('/upload/avatars/x.png') === null, 'чужая папка → null');
ok(Thumbnailer::createFor('/upload/stickers/../../config.php') === null, 'traversal → null');
ok(Thumbnailer::createFor('/upload/stickers/no_such_file.png') === null, 'несуществующий файл → null');

// Уборка фикстур
foreach ($made as $f) { if (is_file($f)) unlink($f); }

echo "\n" . ($fail === 0 ? "ALL PASS" : "FAILED: $fail") . "\n";
exit($fail === 0 ? 0 : 1);
