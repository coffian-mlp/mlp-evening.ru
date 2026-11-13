<?php
require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/helpers/gif_fixture.php';

use LLM\GifStoryboard;
use LLM\VisionFormatter;
use LLM\VisionDescriber;
use Core\FileCache;

$fail = 0; $pass = 0;
function gif_check(bool $ok, string $label): void {
    global $fail, $pass;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . "\n";
}
function gif_color($image, int $x, int $y, array $expected): bool {
    if (!$image) return false;
    $v = imagecolorsforindex($image, imagecolorat($image, $x, $y));
    return max(abs($v['red']-$expected[0]), abs($v['green']-$expected[1]), abs($v['blue']-$expected[2])) < 30;
}
function gif_preview(string $bytes): array {
    $r = GifStoryboard::render($bytes);
    return [$r, $r ? imagecreatefromstring($r['jpeg']) : null];
}
if (!class_exists(GifStoryboard::class)) {
    gif_check(false, 'GifStoryboard exists (RED before implementation)');
    echo "PASS=$pass FAIL=$fail SKIP=0\n"; exit(1);
}
if (!extension_loaded('gd')) { echo "GD required\n"; exit(1); }
$palette = [[255,255,255],[240,20,20],[20,220,20],[20,20,240],[240,220,20],[20,220,220],
    [220,20,220],[120,30,20],[20,120,30],[30,20,120],[240,120,20],[120,240,20],[20,120,240]];
$frames = array_map(fn($n) => ['color'=>$n], range(1,12));
[$r, $im] = gif_preview(gif_fixture($frames));
gif_check($r !== null && $r['frames'] === 9 && imagesx($im) === 144 && imagesy($im) === 144, '12 frames → 9 cells, 3×3');
// Uniform sample times across [0, total duration - 1 ms], including long holds.
foreach ([1,2,3,5,6,8,9,11,12] as $i=>$n) {
    gif_check(gif_color($im, ($i%3)*48+30, intdiv($i,3)*48+30, $palette[$n]), 'time sample '.$i.' color '.$n);
}
[$r4,$im4] = gif_preview(gif_fixture(array_slice($frames,0,4)));
gif_check($r4 && $r4['frames'] === 4 && imagesx($im4) === 96 && imagesy($im4) === 96, '4 frames → 2×2');
[$r2,$im2] = gif_preview(gif_fixture(array_slice($frames,0,2)));
gif_check($r2 && gif_color($im2,78,78,[255,255,255]), 'empty cells have background');
[$r5,$im5] = gif_preview(gif_fixture(array_slice($frames,0,5)));
gif_check($r5 && imagesx($im5) === 144 && gif_color($im5,126,126,[255,255,255]), '5 frames → 3×3 with empty cells');
gif_check(GifStoryboard::render(gif_fixture([['color'=>1]])) === null, 'single frame uses legacy path');
$variable = array_fill(0,12,['color'=>1]);
foreach ($variable as $i=>&$f) { $f['color']=$i+1; $f['delay']=$i===0 ? 100 : 10; } unset($f);
[$rv,$iv] = gif_preview(gif_fixture($variable));
gif_check($rv && $rv['frames'] === 9 && gif_color($iv,78,30,$palette[1]), 'variable delays preserve long hold in time samples');
// Long first frame occupies the first four time samples.
foreach ([1,1,1,1,2,5,7,10,12] as $i=>$n) gif_check(gif_color($iv,($i%3)*48+30,intdiv($i,3)*48+30,$palette[$n]), 'variable delay sample '.$i);
$zero = $frames; foreach ($zero as &$f) $f['delay']=0; unset($f);
[$rz,$iz] = gif_preview(gif_fixture($zero));
gif_check($rz && $rz['jpeg'] === $r['jpeg'], '0-delay normalized to 100ms');
$one = $frames; foreach ($one as &$f) $f['delay']=1; unset($f);
gif_check(GifStoryboard::render(gif_fixture($one))['jpeg'] === $r['jpeg'], '1-delay normalized to 100ms');

// Restore background (2) and previous (3), with partial transparent frames at nonzero offsets.
foreach ([2,3] as $disposal) {
    $f = [['color'=>1],['color'=>2,'w'=>20,'h'=>20,'x'=>24,'y'=>24,'disposal'=>$disposal],
        ['color'=>0,'w'=>20,'h'=>20,'x'=>24,'y'=>24,'transparent'=>0],['color'=>3,'w'=>4,'h'=>4,'x'=>40,'y'=>40]];
    [$rd,$id] = gif_preview(gif_fixture($f));
    gif_check($rd && gif_color($id,78,30,$palette[2]), 'offset applied disposal '.$disposal);
    gif_check($rd && gif_color($id,30,78,$palette[$disposal===2 ? 0 : 1]), 'restore applied disposal '.$disposal);
    gif_check($rd && gif_color($id,10,78,$palette[1]), 'unaffected canvas preserved disposal '.$disposal);
}
// Transparency preserves previously drawn pixels, both with keep and unspecified disposal.
foreach ([0,1] as $disposal) {
    $pixels=array_fill(0,48*48,0);
    for ($y=24;$y<48;$y++) for ($x=24;$x<48;$x++) $pixels[$y*48+$x]=2;
    [$rt,$it]=gif_preview(gif_fixture([['color'=>1,'disposal'=>$disposal],['pixels'=>$pixels,'transparent'=>0]]));
    gif_check($rt && gif_color($it,78,30,$palette[2]) && gif_color($it,58,30,$palette[1]),'transparent overlay with disposal '.$disposal);
}
$localPalette=$palette;while(count($localPalette)<16)$localPalette[]=[0,0,0];$localPalette[1]=[20,20,240];
[$rp,$ip]=gif_preview(gif_fixture([['color'=>1],['color'=>1,'palette'=>$localPalette]]));
gif_check($rp && gif_color($ip,30,30,$palette[1]) && gif_color($ip,78,30,$palette[3]),'local frame palette overrides global palette');
$localOnly=gif_fixture([['color'=>1,'palette'=>$localPalette],['color'=>2,'palette'=>$localPalette]]);
$localOnly=substr_replace($localOnly,chr(0x73),10,1);$localOnly=substr($localOnly,0,13).substr($localOnly,61);
[$rp,$ip]=gif_preview($localOnly);
gif_check($rp && gif_color($ip,30,30,$palette[3]) && gif_color($ip,78,30,$palette[2]),'GIF without global palette uses local palettes');
$pixels=[];for($y=0;$y<48;$y++)for($x=0;$x<48;$x++)$pixels[]=$y<24?1:3;
[$ri,$ii]=gif_preview(gif_fixture([['pixels'=>$pixels,'interlace'=>true],['color'=>2]]));
gif_check($ri && gif_color($ii,30,18,$palette[1]) && gif_color($ii,30,35,$palette[3]),'interlaced rows decoded in display order');
$corrupt = gif_fixture($frames);
gif_check(GifStoryboard::render(substr($corrupt,0,-12)) === null, 'truncated GIF rejected');
gif_check(GifStoryboard::render(str_replace("\x21\xf9\x04", "\x21\xf9\x05", $corrupt)) === null, 'bad extension rejected');
gif_check(GifStoryboard::render(gif_fixture(array_fill(0,1001,['color'=>1]),1,1)) === null, '1001 frames rejected');
$huge = substr_replace(gif_fixture($frames),pack('vv',65535,65535),6,4);
gif_check(GifStoryboard::render($huge) === null, 'huge logical canvas rejected before allocation');
$outside = gif_fixture([['color'=>1],['color'=>2,'x'=>47,'w'=>4]]);
gif_check(GifStoryboard::render($outside) === null, 'frame outside canvas rejected');
gif_check(GifStoryboard::render(str_repeat('x',GifStoryboard::MAX_BYTES+1)) === null,'input byte budget');
// Small compressed data with large declared rectangles must be refused before GD decode.
$tiny=gif_fixture([['color'=>1]],1,1);
$block=substr($tiny,61,-1);$block=substr_replace($block,pack('vv',1000,1000),13,4);
$overPixels=substr_replace(substr($tiny,0,61),pack('vv',1000,1000),6,4).str_repeat($block,301).';';
$parse=new ReflectionMethod(GifStoryboard::class,'parse');
$reason='';try{$parse->invoke(null,$overPixels,hrtime(true)+2000000000);}catch(Throwable $e){$reason=$e->getMessage();}
gif_check(str_contains($reason,'pixel budget'),'300 megapixel budget checked before decode');
$reason='';try{$parse->invoke(null,gif_fixture($frames),hrtime(true)-1);}catch(Throwable $e){$reason=$e->getMessage();}
gif_check(str_contains($reason,'time budget'),'parser observes expired monotonic deadline');
$large = gif_fixture([['color'=>1],['color'=>2]],800,100);
[$rl,$il] = gif_preview($large);
gif_check($rl && imagesx($il) === 1024 && imagesy($il) === 128, 'cell <=512, dimensions preserve aspect');
[$rl,$il] = gif_preview(gif_fixture(array_fill(0,5,['color'=>1]),800,100));
gif_check($rl && imagesx($il) === 1536 && imagesy($il) === 192, '3×3 long side <=1536');
[$rs,$is] = gif_preview(gif_fixture([['color'=>1],['color'=>2]],1,1));
gif_check($rs && imagesx($is)>=64 && gif_color($is,30,30,$palette[1]) && gif_color($is,62,30,$palette[2]), 'tiny GIF cells leave room for frame numbers');

$root = sys_get_temp_dir().'/mlp465_'.bin2hex(random_bytes(4));
mkdir($root.'/upload/chat',0777,true);
try {
    $path=$root.'/upload/chat/test.gif';
    file_put_contents($path,gif_fixture($frames));
    $expand=fn($name)=>VisionFormatter::expand([['role'=>'user','content'=>'![x](/upload/chat/'.$name.')']],'https://unused.invalid',$root)[0]['content'];
    $parts=$expand('test.gif');
    gif_check(is_array($parts) && count($parts)===2 && $parts[0]['type']==='text' && str_contains($parts[0]['text'],'9 кадров'), 'multimodal animation hint adjacent to image');
    file_put_contents($path,gif_fixture([['color'=>1]]));
    $single=$expand('test.gif');
    gif_check(count($single)===1 && $single[0]['type']==='image_url', 'single GIF has no hint');
    $expected=imagecreatefromstring(gif_fixture([['color'=>1]]));
    $legacy=function($src):string {
        $dst=imagecreatetruecolor(imagesx($src),imagesy($src));imagefill($dst,0,0,0xffffff);
        imagecopyresampled($dst,$src,0,0,0,0,imagesx($src),imagesy($src),imagesx($src),imagesy($src));
        ob_start();imagejpeg($dst,null,80);return 'data:image/jpeg;base64,'.base64_encode(ob_get_clean());
    };
    gif_check($single[0]['image_url']['url']===$legacy($expected), 'single GIF JPEG byte-for-byte legacy');
    foreach (['png','jpeg','webp'] as $format) {
        $file=$root.'/upload/chat/still.'.$format;imagepalettetotruecolor($expected);('image'.$format)($expected,$file);
        $p=$expand('still.'.$format);
        gif_check(count($p)===1 && $p[0]['image_url']['url']===$legacy(imagecreatefromstring(file_get_contents($file))), $format.' byte-for-byte legacy, no hint');
    }
    file_put_contents($path,substr($corrupt,0,-12));
    $p=$expand('test.gif');
    gif_check(count($p)===1 && $p[0]['image_url']['url']===$legacy(imagecreatefromstring($corrupt)), 'truncated animation falls back to first-frame JPEG');
    file_put_contents($path,gif_fixture(array_fill(0,1001,['color'=>1]),1,1));
    $p=$expand('test.gif');
    gif_check(count($p)===1 && str_starts_with($p[0]['image_url']['url'],'data:image/jpeg;'), 'frame limit falls back to first-frame JPEG');
    file_put_contents($path,$huge);
    $p=$expand('test.gif');
    gif_check(count($p)===1 && $p[0]['image_url']['url']==='https://unused.invalid/upload/chat/test.gif', 'unsafe canvas keeps legacy URL fallback without hint');
    // Huge compressed blocks at tiny dimensions must not exhaust a 64 MiB worker.
    $probe=$root.'/upload/chat/memory.bin'; $handle=fopen($probe,'wb');
    fwrite($handle,substr(gif_fixture([['color'=>1],['color'=>2]],1,1),0,-6).chr(4));
    $block=chr(255).str_repeat("\0",255);
    for($i=0;$i<98000;$i++)fwrite($handle,$block);
    fwrite($handle,"\0;");fclose($handle);
    $script='require '.var_export(dirname(__DIR__).'/autoload.php',true).'; $r=LLM\\GifStoryboard::render(file_get_contents($argv[1])); echo $r===null ? "BOUNDED" : "UNEXPECTED";';
    $command=escapeshellarg(PHP_BINARY).' -d memory_limit=64M -r '.escapeshellarg($script).' '.escapeshellarg($probe);
    $process=proc_open($command,[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $result=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    $exit=proc_close($process);
    gif_check($exit===0 && $result==='BOUNDED' && $errors==='', 'encoded frame copies bounded in 64 MiB subprocess (no fatal OOM)');
    $external=VisionFormatter::expand([['role'=>'user','content'=>'![](https://example.invalid/x.gif)']],'https://unused.invalid',$root)[0]['content'];
    gif_check(count($external)===1 && $external[0]['image_url']['url']==='https://example.invalid/x.gif', 'external GIF untouched');
} finally {
    foreach (glob($root.'/upload/chat/*') as $f) unlink($f);
    rmdir($root.'/upload/chat');rmdir($root.'/upload');rmdir($root);
}
echo "PASS=$pass FAIL=$fail SKIP=0\n";
if (!$fail) echo "ALL PASS\n";
exit($fail ? 1 : 0);
