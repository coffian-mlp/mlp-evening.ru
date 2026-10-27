<?php
require_once __DIR__.'/../autoload.php';
use Domain\UserRoles as R;
$fail=0;$check=static function($v,$m)use(&$fail){echo($v?'[OK] ':'[FAIL] ').$m."\n";if(!$v)$fail++;};
foreach([[],['admin'],['moderator'],['streamer'],['admin','moderator','streamer']] as $roles)$check(in_array('user',R::normalize($roles),true),'baseline exists for '.json_encode($roles));
$check(R::normalize(['streamer','moderator','streamer'])===['user','moderator','streamer'],'membership is deterministic and duplicate-free');
$check(R::primary(['user','streamer'])==='user','streamer does not become staff');
$check(R::primary(['user','admin','moderator','streamer'])==='admin','legacy projection preserves maximum old authority');
$check(!R::has(['roles'=>['user','admin']],'streamer'),'administrator identity is independent of streamer membership');
$check(R::fromUser(['role'=>'admin','roles_json'=>'[]'])===['user'],'canonical empty membership cannot inherit stale scalar admin');
foreach(['bad','null','"admin"','{"0":"admin"}','{"admin":true}'] as $json)$check(R::fromUser(['role'=>'admin','roles_json'=>$json])===['user'],'malformed stored membership fails closed but retains baseline: '.$json);
$check(R::fromUser(['role'=>'moderator','roles_json'=>null])===['user','moderator'],'legacy nullable row preserves existing membership');
foreach([['root'],['streamer','root'],['admin'=>true],5,null] as $input){try{R::normalize($input);$check(false,'invalid role input rejected');}catch(Core\UserError $e){$check(true,'invalid role input rejected');}}
echo $fail?"FAIL: $fail\n":"ALL PASS\n";exit($fail?1:0);
