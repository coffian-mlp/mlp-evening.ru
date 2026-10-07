<?php
require __DIR__.'/../autoload.php';
$fail=0;
function check($ok,$label){global $fail;echo ($ok?'[OK] ':'[FAIL] ').$label."\n";if(!$ok)$fail++;}
use Api\EpisodeCatalogueController as C;
$uuid='12345678-ABCD-0123-4567-123456789ABC';
check(C::operationKey(21,'wish','2',$uuid)==='catalogue:21:wish:2:12345678-abcd-0123-4567-123456789abc','canonical server actor/action/target UUID');
check(C::operationKey(22,'wish',2,$uuid)!==C::operationKey(21,'wish',2,$uuid),'actor namespace');
check(C::operationKey(21,'cancel',2,$uuid)!==C::operationKey(21,'wish',2,$uuid),'action namespace');
check(C::operationKey(21,'wish',3,$uuid)!==C::operationKey(21,'wish',2,$uuid),'target namespace');
foreach([[0,'wish',2,$uuid],[21,'other',2,$uuid],[21,'wish','02',$uuid],[21,'wish',0,$uuid],[21,'wish',[], $uuid],[21,'wish','2147483648',$uuid],[21,'wish',2,'../foreign'],[21,'wish',2,[]]] as $i=>$args){try{C::operationKey(...$args);check(false,'invalid input '.$i);}catch(Core\UserError $e){check(true,'invalid input '.$i);}}
foreach(['accepted','cancelled','cooldown','daily_limit','not_active','missing','other'] as $code)check(C::outcomeMessage(['code'=>$code])!=='','truthful outcome text '.$code);
$routes=require __DIR__.'/../src/Api/routes.php';
foreach(['catalogue_wish','catalogue_cancel_wish'] as $action)check($routes[$action]['role']==='user'&&is_callable($routes[$action]['handler']),'authenticated callable route '.$action);
echo $fail?"FAIL: $fail\n":"ALL PASS\n";exit($fail?1:0);
