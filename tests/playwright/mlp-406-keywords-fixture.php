<?php
require dirname(__DIR__).'/integration_helpers.php';
if(PHP_SAPI!=='cli'||!is_file('/.dockerenv')||(it_config()['db']['host']??'')!=='db')exit(2);
it_require_db();$users=new Domain\UserManager();$path=dirname(__DIR__,2).'/docs/private/mlp406-local.json';
if(($argv[1]??'')==='setup'){
 if(is_file($path))throw new RuntimeException('Fixture exists');
 $s=['login'=>'it_mlp406_'.bin2hex(random_bytes(8)),'password'=>bin2hex(random_bytes(24))];
 $s['id']=$users->createUser($s['login'],$s['password'],'user','Keywords fixture');
 $old=umask(0077);file_put_contents($path,json_encode($s));umask($old);
}elseif(($argv[1]??'')==='cleanup'){
 $s=json_decode(file_get_contents($path),true);if(!str_starts_with($users->getUserById($s['id'])['login']??'','it_mlp406_'))throw new RuntimeException('Invalid user');
 $users->deleteUser($s['id']);unlink($path);
}else throw new RuntimeException('Invalid action');
echo "PASS\n";
