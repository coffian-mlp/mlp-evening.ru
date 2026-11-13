<?php
namespace LLM {
    /** Observe actual image reads; realpath/is_file metadata checks remain available to the resolver. */
    function getimagesize($path, & $info = null) {
        $GLOBALS['vision_path_reads'][]=$path;
        return \getimagesize($path,$info);
    }
    function file_get_contents($path, ...$args) {
        $GLOBALS['vision_path_reads'][]=$path;
        return \file_get_contents($path,...$args);
    }
}
namespace {
require_once __DIR__.'/../autoload.php';
require_once __DIR__.'/helpers/gif_fixture.php';
use LLM\VisionFormatter;

$pass=0;$fail=0;
function path_check(bool $ok,string $label):void {
    global $pass,$fail;
    $ok?$pass++:$fail++;
    echo ($ok?'[OK] ':'[FAIL] ').$label."\n";
}
$area=sys_get_temp_dir().'/mlp465_path_'.bin2hex(random_bytes(5));
$root=$area.'/site';$files=[];$dirs=[];
$write=function(string $path,string $bytes)use(&$files,&$dirs):void {
    $dir=dirname($path);$missing=[];
    while(!is_dir($dir)){$missing[]=$dir;$dir=dirname($dir);}
    foreach(array_reverse($missing)as$dir){mkdir($dir);$dirs[]=$dir;}
    file_put_contents($path,$bytes);$files[]=$path;
};
$im=imagecreatetruecolor(48,48);imagefill($im,0,0,0xff2200);ob_start();imagepng($im);$png=ob_get_clean();
$gif=gif_fixture(array_map(fn($i)=>['color'=>$i],range(1,12)));
$blocked=['tests','migrations','database','scripts','docs','dev_knowledge','logs','cache','tools','deploy','node_modules'];
try {
    $write($area.'/outside.png',$png);
    $write($area.'/outside.gif',$gif);
    $write($area.'/site-sibling/outside.png',$png);
    $write($root.'/upload/chat/good.png',$png);
    $write($root.'/upload/chat/good.gif',$gif);
    $write($root.'/assets/good.png',$png);
    $write($root.'/assets/space name.png',$png);
    $write($root.'/.private/hidden.png',$png);
    $write($root.'/upload/.private/hidden.png',$png);
    foreach($blocked as$dir)$write($root.'/'.$dir.'/hidden.png',$png);
    // A second decode must not turn an apparently harmless literal directory into traversal.
    $write($root.'/upload/%2e%2e/literal.png',$png);
    $expand=fn($url)=>VisionFormatter::expand([['role'=>'user','content'=>'![]('.$url.')']],'https://unused.invalid',$root)[0]['content'];
    $outsideRelative=str_repeat('../',count(explode('/',trim($root,'/')))).ltrim($area.'/outside.png','/');
    $invalid=[
        '/upload/../../outside.png',
        '/upload/%2e%2e%2f%2e%2e%2foutside.png',
        '/upload/%2E%2E/%2E%2E/outside.png',
        '/upload/%252e%252e/literal.png',
        '/'.$outsideRelative,
        '/upload/../../site-sibling/outside.png',
        '/upload/../docs/hidden.png',
        '/%64ocs/hidden.png',
        '/upload/%252e%252e%252fdocs/hidden.png',
        '/.private/hidden.png',
        '/upload/.private/hidden.png',
        '/upload/chat/good%00.png',
        '/upload/chat/missing.png',
    ];
    foreach($blocked as$dir)$invalid[]='/'.$dir.'/hidden.png';
    if(function_exists('symlink')){
        foreach(['outside'=>$area.'/outside.png','private'=>$root.'/docs/hidden.png']as$name=>$target){
            $link=$root.'/upload/'.$name.'.png';
            if(!symlink($target,$link))throw new RuntimeException('Symlink fixture unavailable');
            $files[]=$link;$invalid[]='/upload/'.$name.'.png';
        }
    }else{throw new RuntimeException('Symlink tests require support');}
    $sourceRoot=dirname(__DIR__);
    $outsideGif='/'.str_repeat('../',count(explode('/',trim($sourceRoot,'/')))).ltrim($area.'/outside.gif','/');
    $GLOBALS['vision_path_reads']=[];
    path_check(!VisionFormatter::isLocalGif($outsideGif),'GIF classifier rejects traversal');
    path_check($GLOBALS['vision_path_reads']===[],'GIF classifier does not read outside file');
    foreach($invalid as$i=>$url){
        $GLOBALS['vision_path_reads']=[];
        $out=$expand($url);
        path_check(is_string($out),'rejected URL '.$i.' produces no image or URL fallback');
        path_check($GLOBALS['vision_path_reads']===[],'rejected URL '.$i.' is not read');
    }
    foreach(['/upload/chat/good.png','/assets/good.png','/assets/space%20name.png','/upload/chat/good.png?v=2#x','/upload/chat/good.png#x']as$url){
        $out=$expand($url);
        path_check(is_array($out) && str_starts_with(end($out)['image_url']['url'],'data:image/jpeg;base64,'),'public PNG works '.$url);
    }
    $out=$expand('/upload/chat/good.gif');
    $image=end($out)['image_url']['url']??'';
    $size=@getimagesizefromstring(base64_decode(substr($image,23)));
    path_check(is_array($out)&&count($out)===2&&$size&&$size[0]===144&&$size[1]===144,'public GIF still produces 9-frame storyboard');
    path_check(method_exists(VisionFormatter::class,'localImagePath'),'single localImagePath resolver exists');
    if(method_exists(VisionFormatter::class,'localImagePath')){
        path_check(VisionFormatter::localImagePath('',$root)===null,'empty URL rejected');
        path_check(VisionFormatter::localImagePath('/',$root)===null,'directory rejected');
        path_check(VisionFormatter::localImagePath('/upload/chat/good.png',$root)===realpath($root.'/upload/chat/good.png'),'resolver returns canonical public file');
    }
} finally {
    foreach(array_reverse($files)as$file)unlink($file);
    foreach(array_reverse($dirs)as$dir)rmdir($dir);
}
echo "PASS=$pass FAIL=$fail SKIP=0\n";
if(!$fail)echo "ALL PASS\n";
exit($fail?1:0);
}
