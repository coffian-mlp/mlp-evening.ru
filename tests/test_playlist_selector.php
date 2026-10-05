<?php
require_once __DIR__.'/../autoload.php';
use Domain\PlaylistSelector;
function check($v,$label){if(!$v){echo "FAIL $label\n";exit(1);}echo "OK $label\n";}
$stories=[];foreach([3,3,2,4] as $k=>$c)$stories[]=['ids'=>[$k+1],'titles'=>['test'],'length'=>$c,'weight'=>1.0];
foreach([0.0,0.5,1.0] as $r){$s=PlaylistSelector::select($stories,8,fn()=>$r);check(array_sum(array_column($s,'length'))===8,'reachable eight');check(count(array_unique(array_merge(...array_column($s,'ids'))))===count($s),'unique');}
check(PlaylistSelector::select([['ids'=>[1],'length'=>0,'weight'=>1]],8)===[],'zero excluded');check(PlaylistSelector::select([],8)===[],'empty');

echo "ALL PASS\n";
