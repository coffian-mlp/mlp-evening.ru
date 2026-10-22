<?php
require_once __DIR__.'/../autoload.php';
use Domain\PlaylistSelector;
function check($v,$label){if(!$v){echo "FAIL $label\n";exit(1);}echo "OK $label\n";}
$stories=[];foreach([3,3,2,4] as $k=>$c)$stories[]=['ids'=>[$k+1],'titles'=>['test'],'length'=>$c,'weight'=>1.0];
foreach([0.0,0.5,1.0] as $r){$s=PlaylistSelector::select($stories,8,fn()=>$r);check(array_sum(array_column($s,'length'))===8,'reachable eight');check(count(array_unique(array_merge(...array_column($s,'ids'))))===count($s),'unique');}
check(PlaylistSelector::select([['ids'=>[1],'length'=>0,'weight'=>1]],8)===[],'zero excluded');check(PlaylistSelector::select([],8)===[],'empty');

$pool=[
    ['ids'=>[10],'length'=>1,'weight'=>1e12],
    ['ids'=>[11,12],'length'=>2,'weight'=>1e12],
    ['ids'=>[13],'length'=>1,'weight'=>0.5],
    ['ids'=>[14],'length'=>4,'weight'=>2.0],
];
foreach ([0.0,0.5,1.0] as $r) {
    $selected=PlaylistSelector::select($pool,8,fn()=>$r,[10,12]);
    $ids=array_merge(...array_column($selected,'ids'));
    sort($ids);
    check($ids===[13,14],'hard exclusion overrides wishes and excludes entire pair');
    check(array_sum(array_column($selected,'length'))===5,'short pool stays shorter without restoring excluded stories');
    check(array_column($selected,'weight','length')===[1=>0.5,4=>2.0] || array_column($selected,'weight','length')===[4=>2.0,1=>0.5],'eligible weights and movie duration retained');
}
check(PlaylistSelector::select($pool,8,null,[10,11,12,13,14])===[],'fully excluded catalogue has no repeat fallback');

echo "ALL PASS\n";
