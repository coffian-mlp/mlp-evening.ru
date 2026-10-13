<?php
require_once dirname(__DIR__).'/integration_helpers.php';
if(PHP_SAPI!=='cli'||!is_file('/.dockerenv')||(it_config()['db']['host']??'')!=='db')exit(1);
it_require_db();$db=Infra\Database::getInstance()->getConnection();$config=Infra\ConfigManager::getInstance();
$path=dirname(__DIR__,2).'/docs/private/mlp390-local.json';
if(($argv[1]??'')==='setup'){
 if(is_file($path))throw new RuntimeException('Existing owned fixture');
 $s=['login'=>'it_mlp390_'.bin2hex(random_bytes(6)),'password'=>bin2hex(random_bytes(24)),'options'=>[]];
 $s['id']=(new Domain\UserManager())->createUser($s['login'],$s['password'],'admin','Backlog Test');
 (new Domain\UserManager())->setUserOption($s['id'],'avatar_url','/assets/img/default-avatar.png');
 foreach(['ai_enabled','ai_main_is_vision','ai_fast_is_vision'] as $key)$s['options'][$key]=$config->getOption($key,null);
 $config->setOption('ai_enabled','0');
 $s['prefixes']=[];$r=$db->query("SELECT command_prefix FROM bot_commands WHERE is_active=1 ORDER BY id LIMIT 3");while($row=$r->fetch_assoc())$s['prefixes'][]=$row['command_prefix'];
 $old=umask(0077);file_put_contents($path,json_encode($s,JSON_THROW_ON_ERROR));umask($old);chmod($path,0600);echo "Local fixture ready\n";
}elseif(($argv[1]??'')==='cleanup'){
 $s=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
 $u=(new Domain\UserManager())->getUserById($s['id']);if(!$u||$u['login']!==$s['login']||!str_starts_with($u['login'],'it_mlp390_'))throw new RuntimeException('Unexpected fixture owner');
 foreach($s['options'] as $key=>$value){if($value!==null)$config->setOption($key,(string)$value);else{$stmt=$db->prepare('DELETE FROM site_options WHERE key_name=?');$stmt->bind_param('s',$key);$stmt->execute();}}
 (new Domain\UserManager())->deleteUser($s['id']);unlink($path);echo "Local fixture removed\n";
}
