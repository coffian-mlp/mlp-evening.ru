<?php
require __DIR__.'/../autoload.php';
$fail=0;
function check($ok,$label){global $fail;echo ($ok?'[OK] ':'[FAIL] ').$label."\n";if(!$ok)$fail++;}
$manager=(new ReflectionClass(Domain\EpisodeManager::class))->newInstanceWithoutConstructor();
$method=new ReflectionMethod(Domain\EpisodeManager::class,'wishDayBounds');
foreach([
 ['2026-10-10T21:59:59Z','2026-10-10','2026-10-09 22:00:00','2026-10-10 22:00:00'],
 ['2026-10-10T22:00:00Z','2026-10-11','2026-10-10 22:00:00','2026-10-11 22:00:00'],
 ['2010-03-28T10:00:00Z','2010-03-28','2010-03-27 22:00:00','2010-03-28 21:00:00'],
 ['2010-10-31T10:00:00Z','2010-10-31','2010-10-30 21:00:00','2010-10-31 22:00:00'],
] as [$now,$day,$start,$end]){
 $value=$method->invoke($manager,strtotime($now));
 check($value===['day'=>$day,'start'=>$start,'end'=>$end],$day.' local calendar bounds, not fixed86400');
}
echo $fail?"FAIL: $fail\n":"ALL PASS\n";exit($fail?1:0);
