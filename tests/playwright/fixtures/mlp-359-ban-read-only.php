<?php
/** Local browser fixtures, CLI and Docker db only. Never an HTTP endpoint.
 * Direct SQL: controlled fixture timestamps and exact fixture cleanup only.
 */
require_once dirname(__DIR__, 2) . '/integration_helpers.php';
if (PHP_SAPI !== 'cli' || (it_config()['db']['host'] ?? '') !== 'db') { http_response_code(404); exit(1); }
$db=it_require_db();
$um=new \Domain\UserManager(); $chat=new \Domain\ChatManager();
$action=$argv[1]??''; $login=$argv[2]??'';
if (!preg_match('/^it_mlp359_[a-f0-9]{12}$/', $login)) exit(2);
$user=$um->getUserByLogin($login); $uid=(int)($user['id']??0);
if ($action==='setup') {
    if ($uid) exit(3);
    $uid=$um->createUser($login,'local-test-password','user',$login);
    $mid=$chat->addMessage($uid,$login,'Fixture history '.$login);
    $um->banUser($uid,'MLP359 browser reason',null,15);
    echo json_encode(['login'=>$login,'password'=>'local-test-password','uid'=>$uid,'mid'=>$mid]); exit;
}
if (!$uid) exit(4);
switch ($action) {
    case 'ban': $um->banUser($uid,'MLP359 browser reason',null,15); break;
    case 'mute': $um->unbanUser($uid); $um->muteUser($uid,15,null,'MLP359 mute reason'); break;
    case 'unban': $um->unbanUser($uid); $um->unmuteUser($uid); $db->query("UPDATE chat_messages SET created_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY) WHERE user_id=$uid"); break;
    case 'expired': $um->unbanUser($uid); $um->unmuteUser($uid); $db->query("UPDATE users SET is_banned=1,ban_until=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE id=$uid"); $db->query("UPDATE chat_messages SET created_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY) WHERE user_id=$uid"); break;
    case 'new': $um->unbanUser($uid); $um->unmuteUser($uid); $db->query("UPDATE chat_messages SET created_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY) WHERE user_id=$uid"); $chat->addMessage($uid,$login,'Fixture live '.$login); $um->banUser($uid,'MLP359 browser reason',null,15); break;
    case 'rate': $cfg=\Infra\ConfigManager::getInstance(); $old=(int)$cfg->getOption('chat_rate_limit',0); $cfg->setOption('chat_rate_limit',(int)($argv[3]??0)); echo json_encode(['old'=>$old]); break;
    case 'count': echo json_encode(['count'=>(int)$db->query("SELECT COUNT(*) n FROM chat_messages WHERE user_id=$uid")->fetch_assoc()['n'],'rate'=>(int)\Infra\ConfigManager::getInstance()->getOption('chat_rate_limit',0)]); break;
    case 'cleanup': foreach(['chat_reactions','chat_messages','auth_tokens','user_socials','user_options'] as $table) $db->query("DELETE FROM $table WHERE user_id=$uid"); $um->deleteUser($uid); break;
    default: exit(5);
}
