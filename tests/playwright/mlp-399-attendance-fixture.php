<?php
require dirname(__DIR__) . '/integration_helpers.php';
if (PHP_SAPI !== 'cli' || !is_file('/.dockerenv') || (it_config()['db']['host'] ?? '') !== 'db') exit(2);
it_require_db();
$db = Infra\Database::getInstance()->getConnection(); $cfg = Infra\ConfigManager::getInstance(); $users = new Domain\UserManager();
$path = dirname(__DIR__, 2) . '/docs/private/mlp399-local.json';
$save = static function (array $s) use ($path): void { $mask=umask(0077); file_put_contents($path,json_encode($s,JSON_THROW_ON_ERROR)); umask($mask); chmod($path,0600); };
$action = $argv[1] ?? '';
if ($action === 'setup') {
    if (is_file($path)) throw new RuntimeException('Fixture already exists');
    $s = ['users'=>[], 'options'=>[], 'poll'=>0, 'password'=>bin2hex(random_bytes(24))];
    foreach (['ai_bot_user_id','ai_enabled','polls_create_role'] as $key) $s['options'][$key]=$cfg->getOption($key,null);
    $save($s);
    foreach (['host'=>'moderator','viewer'=>'user','bot'=>'user'] as $name=>$role) {
        $s[$name.'Login']='it_mlp399_'.$name.'_'.bin2hex(random_bytes(6));
        $s[$name]=$users->createUser($s[$name.'Login'],$s['password'],$role,'Attendance '.ucfirst($name));
        $s['users'][]=$s[$name]; $save($s);
    }
    $cfg->setOption('ai_bot_user_id',(string)$s['bot']); $cfg->setOption('ai_enabled','0'); $cfg->setOption('polls_create_role','moderator');
    $chat=new Domain\ChatManager(); $s['source']=$chat->addMessage($s['host'],'Attendance Host','/перерыв'); $save($s);
    $llm=new class extends LLM\LLMManager {public function commandConfirmationText(string $instruction,?string $mustContain,string $message): ?string {return 'Разомните копыта и возвращайтесь к экрану! Нажмите «Я тут!», когда будете готовы.';}};
    if (!(new LLM\AttendanceCommand($llm))->handle(['message_id'=>$s['source'],'user_id'=>$s['host']])) throw new RuntimeException('Command failed');
    $reply=$chat->findBotReplyTo($s['source']); preg_match('/\[\[poll:(\d+)\]\]/',$reply['raw_message'],$m); $s['poll']=(int)$m[1]; $s['reply']=(int)$reply['id']; $save($s);
} elseif ($action === 'cleanup') {
    $s=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    if ($s['poll']) {foreach (['poll_votes','poll_options','polls'] as $table) $db->query('DELETE FROM '.$table.' WHERE '.($table==='polls'?'id':'poll_id').'='.(int)$s['poll']);}
    foreach ($s['users'] as $id) {if(!str_starts_with($users->getUserById($id)['login']??'','it_mlp399_'))throw new RuntimeException('Invalid owner'); $db->query('DELETE FROM chat_messages WHERE user_id='.(int)$id);$users->deleteUser($id);}
    foreach ($s['options'] as $key=>$value) {if($value!==null)$cfg->setOption($key,(string)$value);else{$q=$db->prepare('DELETE FROM site_options WHERE key_name=?');$q->bind_param('s',$key);$q->execute();}}
    unlink($path);
} else throw new RuntimeException('Invalid fixture action');
echo "Fixture PASS\n";
