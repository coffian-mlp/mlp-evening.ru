<?php
require dirname(__DIR__).'/autoload.php';
$root=sys_get_temp_dir().'/mlp_image_counter_'.bin2hex(random_bytes(6));$cache=new Core\FileCache('imagegen',$root);$day='2000-01-01';$fail=0;
$check=static function(bool $ok,string $label)use(&$fail):void{echo($ok?'[OK] ':'[FAIL] ').$label."\n";if(!$ok)$fail++;};
try{
    LLM\ImageGenerator::bumpToday($cache,$day,'drawing_a');LLM\ImageGenerator::bumpToday($cache,$day,'drawing_a');
    $check(LLM\ImageGenerator::todayCount($cache,$day)===1,'same drawing receipt increments exactly once');
    LLM\ImageGenerator::bumpToday($cache,$day,'drawing_b');$check(LLM\ImageGenerator::todayCount($cache,$day)===2,'different receipt increments independently');
    touch($root.'/imagegen/'.$day.'.json',time()-3*86400);clearstatcache();
    LLM\ImageGenerator::bumpToday($cache,$day,'drawing_a');
    $check(LLM\ImageGenerator::todayCount($cache,$day)===2,'recovery after read-cache TTL preserves accounting receipts and count');
    LLM\ImageGenerator::bumpToday($cache,'2000-01-02','drawing_a');
    $check(LLM\ImageGenerator::todayCount($cache,'2000-01-02')===1&&LLM\ImageGenerator::todayCount($cache,$day)===2,'explicit UTC generation day does not move the original count');
    $cache->set($day,['count'=>7]);LLM\ImageGenerator::bumpToday($cache,$day,'drawing_c');
    $check(LLM\ImageGenerator::todayCount($cache,$day)===8,'legacy counter format remains compatible');
}finally{foreach(glob($root.'/imagegen/*')?:[]as$file)unlink($file);if(is_dir($root.'/imagegen'))rmdir($root.'/imagegen');if(is_dir($root))rmdir($root);}
echo$fail?"FAIL: $fail\n":"ALL PASS\n";exit((int)($fail>0));
