<?php
require_once dirname(__DIR__,2).'/autoload.php';
if(PHP_SAPI!=='cli'||!is_file('/.dockerenv')||Infra\Env::get('DB_HOST')!=='db'||Infra\Env::get('DB_NAME')!=='mlp_phase3')throw new RuntimeException('Dedicated disposable schema only');
$mode=match($argv[1]??''){'strict'=>'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION','empty'=>'',default=>throw new InvalidArgumentException('strict or empty required')};
$db=new mysqli('db','root',Infra\Env::get('DB_ROOT_PASS'));$stmt=$db->prepare('SET GLOBAL sql_mode=?');$stmt->bind_param('s',$mode);$stmt->execute();echo 'sql_mode='.$mode."\n";
