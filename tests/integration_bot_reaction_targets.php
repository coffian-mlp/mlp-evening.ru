<?php
require_once __DIR__.'/integration_helpers.php';
if((it_config()['db']['host']??'')!=='db')it_skip('requires isolated Docker database');
it_require_db();$db=Infra\Database::getInstance()->getConnection();$db->begin_transaction();
try {
    $users=new Domain\UserManager();$chat=new Domain\ChatManager();
    $actor=$users->createUser('it_reactor_'.bin2hex(random_bytes(6)),'fixture password','user','Reactor');
    $bot=$users->createUser('it_reactionbot_'.bin2hex(random_bytes(6)),'fixture password','user','Bot');
    $text=htmlspecialchars('Target fixture',ENT_QUOTES,'UTF-8');$name='Reactor';
    $stmt=$db->prepare('INSERT INTO chat_messages(user_id,username,message,created_at) VALUES (?,?,?,UTC_TIMESTAMP())');$stmt->bind_param('iss',$actor,$name,$text);$stmt->execute();$target=(int)$stmt->insert_id;
    check($chat->setBotReaction($target,$bot,'poop')['added'],'model can react to selected real message');
    check(!$chat->setBotReaction($target,$bot,'poop')['added'],'repeated reaction leaves existing reaction intact');
    check((int)$db->query('SELECT COUNT(*) AS n FROM chat_reactions WHERE message_id='.$target)->fetch_assoc()['n']===1,'duplicate reaction is not inserted');
    foreach(['eggplant','hand','anchor','pie'] as $type)check($chat->toggleReaction($target,$actor,$type)['success'],'new reaction '.$type.' accepted by endpoint manager');
    $db->query('UPDATE chat_messages SET is_deleted=1 WHERE id='.$target);
    check(!$chat->setBotReaction($target,$bot,'heart')['added'],'deleted target cannot receive model reaction');
    check(!$chat->setBotReaction($target,$bot,'unknown')['success'],'invalid type rejected');
    check(!$chat->setBotReaction(2147483647,$bot,'heart')['added'],'missing target ignored');
}finally{$db->rollback();}
it_done();
