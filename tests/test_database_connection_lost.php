<?php
require_once __DIR__.'/../autoload.php';
$fail=0;
$check=static function($ok,$label)use(&$fail){echo($ok?'[OK] ':'[FAIL] ').$label."\n";if(!$ok)$fail++;};
$check(method_exists(Infra\Database::class,'connectionLost'),'database connection loss classifier exists');
if(method_exists(Infra\Database::class,'connectionLost')){
    foreach([2002,2003,2006,2013,2055,4031,1053] as $code){
        $check(Infra\Database::connectionLost(new mysqli_sql_exception('synthetic',$code)),"connection error $code requires a fresh process");
    }
    foreach([0,1045,1062,1064,1146,1205,1213] as $code){
        $check(!Infra\Database::connectionLost(new mysqli_sql_exception('synthetic',$code)),"SQL error $code keeps the existing retry policy");
    }
    $check(!Infra\Database::connectionLost(new RuntimeException('synthetic',2006)),'numeric code alone does not classify a non-MySQL error');
    $check(!Infra\Database::connectionLost(new Error('synthetic')),'unrelated Throwable is not connection loss');
}
echo $fail?"FAIL: $fail\n":"ALL PASS\n";exit($fail?1:0);
