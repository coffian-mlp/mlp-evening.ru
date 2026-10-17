<?php
require_once dirname(__DIR__).'/integration_helpers.php';
if (PHP_SAPI!=='cli' || !is_file('/.dockerenv') || (it_config()['db']['host']??'')!=='db') throw new RuntimeException('Isolated Docker database required');
$db=it_require_db();
$path=dirname(__DIR__,2).'/docs/private/mlp425-local.json';
$mode=$argv[1]??'';
$users=new Domain\UserManager();
if ($mode==='setup') {
    if (is_file($path)) throw new RuntimeException('Cleanup previous fixture first');
    $state=['users'=>[], 'options'=>$db->query('SELECT key_name,value FROM site_options')->fetch_all(MYSQLI_ASSOC)];
    $save=static function () use (&$state,$path) { $old=umask(0077);try{file_put_contents($path,json_encode($state,JSON_THROW_ON_ERROR));chmod($path,0600);}finally{umask($old);} };
    $save();
    foreach (['admin','user'] as $role) {
        $login='it_mlp425_'.bin2hex(random_bytes(5));$password=bin2hex(random_bytes(24));
        $id=$users->createUser($login,$password,$role,'MLP425 '.$role);
        $state['users'][$role]=compact('id','login','password');$save();
        Domain\Privacy::setAccountConsent($id,true);
    }
    Infra\ConfigManager::getInstance()->setOption('ai_enabled','0');
    Infra\ConfigManager::getInstance()->setOption('ai_primary_provider','routerai');
    Infra\ConfigManager::getInstance()->setOption('ai_routerai_reasoning_effort','auto');
    echo "Fixture ready\n";
} elseif ($mode==='cleanup') {
    $state=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    foreach ($state['users'] as $user) {
        $actual=$users->getUserById((int)$user['id']);
        if (!$actual) continue;
        if ($actual['login']!==$user['login'] || !str_starts_with($actual['login'],'it_mlp425_')) throw new RuntimeException('Fixture identity mismatch');
        Domain\Privacy::stageErasure((int)$user['id'],[]);
        $users->deleteUser((int)$user['id']);
    }
    $db->query('DELETE FROM site_options');
    foreach ($state['options'] as $row) Infra\ConfigManager::getInstance()->setOption($row['key_name'],$row['value']);
    unlink($path);
    echo "Fixture removed\n";
} else throw new RuntimeException('Use setup or cleanup');
