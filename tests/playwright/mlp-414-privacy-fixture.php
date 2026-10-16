<?php
require dirname(__DIR__).'/integration_helpers.php';
if(PHP_SAPI!=='cli' || !is_file('/.dockerenv') || (it_config()['db']['host']??'')!=='db')exit(2);
it_require_db();
$db=Infra\Database::getInstance()->getConnection();$path=dirname(__DIR__,2).'/docs/private/mlp414-local.json';
if(($argv[1]??'')==='setup') {
    if(is_file($path))throw new RuntimeException('Fixture already exists');
    $state=['ids'=>[],'options'=>[],'password'=>'Aa!'.bin2hex(random_bytes(16)),'suffix'=>bin2hex(random_bytes(5))];
    foreach(['ai_enabled','stream_url','chat_driver','telegram_auth_enabled','telegram_bot_username','ai_delay_min','ai_delay_max'] as $key){$stmt=$db->prepare('SELECT value FROM site_options WHERE key_name=?');$stmt->bind_param('s',$key);$stmt->execute();$state['options'][$key]=$stmt->get_result()->fetch_assoc()['value']??null;}
    $save=static function() use (&$state,$path){$mask=umask(0077);file_put_contents($path,json_encode($state));umask($mask);};$save();
    foreach(['admin','reader'] as $role){$state[$role.'Login']='it_privacy_'.$role.'_'.$state['suffix'];$id=(new Domain\UserManager())->createUser($state[$role.'Login'],$state['password'],$role==='admin'?'admin':'user');$state['ids'][$role]=$id;$save();Domain\Privacy::setAccountConsent($id,true);}
    $config=Infra\ConfigManager::getInstance();$config->setOption('ai_enabled','0');$config->setOption('ai_delay_min','0');$config->setOption('ai_delay_max','0');$config->setOption('stream_url','https://www.twitch.tv/mlp_evening');$config->setOption('telegram_auth_enabled','1');$config->setOption('telegram_bot_username','mlp_example_bot');
    $db->query("INSERT INTO chat_messages(user_id,username,message,created_at) VALUES (".$state['ids']['reader'].",'Privacy reader','Fixture message',UTC_TIMESTAMP())");$state['message']=(int)$db->insert_id;$save();
} elseif(($argv[1]??'')==='erase-reader') {
    $state=json_decode(file_get_contents($path),true);$id=(int)$state['ids']['reader'];
    $db->query("UPDATE account_privacy SET delete_after=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE user_id=$id AND state='withdrawn'");
    if(!Domain\AccountErasure::eraseDue($id))throw new RuntimeException('Reader must be withdrawn');
    Domain\AccountErasure::removeFiles();Domain\AccountErasure::removeSessions();
} elseif(($argv[1]??'')==='cleanup') {
    if(!is_file($path))exit;$state=json_decode(file_get_contents($path),true);
    $db->query('DELETE FROM chat_messages WHERE id='.(int)($state['message']??0));
    foreach($state['ids'] as $id){foreach(['account_privacy','privacy_consent_events','online_sessions','auth_tokens','privacy_sessions'] as $table)$db->query("DELETE FROM $table WHERE user_id=".(int)$id);(new Domain\UserManager())->deleteUser($id);}
    foreach($state['options'] as $key=>$value){if($value===null){$stmt=$db->prepare('DELETE FROM site_options WHERE key_name=?');$stmt->bind_param('s',$key);$stmt->execute();}else Infra\ConfigManager::getInstance()->setOption($key,$value);}
    unlink($path);
}
