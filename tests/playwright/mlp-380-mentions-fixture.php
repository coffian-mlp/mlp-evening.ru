<?php
require_once dirname(__DIR__).'/integration_helpers.php';
if (PHP_SAPI !== 'cli' || !is_file('/.dockerenv') || (it_config()['db']['host']??'')!=='db') exit(1);
it_require_db();
$db=\Infra\Database::getInstance()->getConnection();
$path=dirname(__DIR__,2).'/docs/private/mlp380-mentions-local.json';
if (($argv[1]??'')==='setup') {
    if (is_file($path)) throw new RuntimeException('Existing owned fixture');
    $state=['users'=>[],'messages'=>[]];
    foreach (['Твайлайт Спаркл',"\u{200C}Hord-Tyan"] as $nick) {
        $login='it_mlp380_'.bin2hex(random_bytes(6)); $password=bin2hex(random_bytes(20));
        $id=(new \Domain\UserManager())->createUser($login,$password,'user',$nick);
        $state['users'][]=['id'=>$id,'login'=>$login,'password'=>$password,'nickname'=>$nick];
    }
    foreach (['@Твайлайт Спаркл, привет!','@Hord-Tyan, привет!','`@Твайлайт Спаркл` и https://example.org/@Hord-Tyan'] as $text) {
        $text=htmlspecialchars($text,ENT_QUOTES,'UTF-8');$uid=$state['users'][1]['id'];$nick=$state['users'][1]['nickname'];
        $q=$db->prepare('INSERT INTO chat_messages(user_id,username,message,created_at) VALUES (?,?,?,UTC_TIMESTAMP())');$q->bind_param('iss',$uid,$nick,$text);$q->execute();$state['messages'][]=(int)$db->insert_id;
    }
    $old=umask(0077);file_put_contents($path,json_encode($state,JSON_THROW_ON_ERROR));umask($old);chmod($path,0600);echo "Fixture ready\n";
} elseif (($argv[1]??'')==='cleanup') {
    $s=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    foreach ($s['messages'] as $id) $db->query('DELETE FROM chat_messages WHERE id='.(int)$id);
    foreach ($s['users'] as $u) $db->query('DELETE FROM users WHERE id='.(int)$u['id']);
    unlink($path);echo "Owned local fixture removed\n";
}
