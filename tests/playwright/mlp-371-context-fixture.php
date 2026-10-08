<?php
require_once dirname(__DIR__).'/integration_helpers.php';
if (PHP_SAPI!=='cli' || !is_file('/.dockerenv') || (it_config()['db']['host']??'')!=='db') exit(1);
it_require_db();
$db=Infra\Database::getInstance()->getConnection();
$config=Infra\ConfigManager::getInstance();
$path=dirname(__DIR__,2).'/docs/private/mlp371-browser-local.json';
$mode=$argv[1]??'';
if ($mode==='setup') {
    if (is_file($path)) throw new RuntimeException('Clean owned fixture first');
    $nonce=bin2hex(random_bytes(6)); $pass=bin2hex(random_bytes(24));
    $f=['login'=>'it371_admin_'.$nonce,'password'=>$pass,'marker'=>'it371_'.$nonce,'saved'=>[]];
    $f['userId']=(new Domain\UserManager())->createUser($f['login'],$pass,'admin','Context Fixture');
    $f['botId']=(new Domain\UserManager())->createUser('it371_bot_'.$nonce,bin2hex(random_bytes(24)),'user','Fixture Lyra');
    foreach (['ai_enabled'=>'1','ai_use_queue'=>'1','ai_worker_mode'=>'cron','ai_bot_user_id'=>(string)$f['botId'],'ai_live_confirm'=>'1','chat_rate_limit'=>'0','ai_delay_min'=>'0','ai_delay_max'=>'0','ai_memory_enabled'=>'1','ai_primary_provider'=>'routerai','ai_routerai_key'=>'fixture-key'] as $key=>$value) {
        $f['saved'][$key]=$config->getOption($key,null); $config->setOption($key,$value);
    }
    $command=$db->query("SELECT * FROM bot_commands WHERE handler_type='todo' LIMIT 1")->fetch_assoc();
    if (!$command) throw new RuntimeException('Seed todo command required');
    $f['command']=$command; $db->query('UPDATE bot_commands SET is_active=1 WHERE id='.(int)$command['id']);
    $f['memoryId']=(new Domain\BotMemoryManager())->add('dossier',$f['userId'],$f['marker'].' original','manual',$f['userId']);
    $mask=umask(0077); file_put_contents($path,json_encode($f,JSON_THROW_ON_ERROR)); chmod($path,0600); umask($mask);
    $config->flushCache(); echo "Fixture ready\n";
} else {
    $f=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    if ($mode==='worker') {
        $messages=$db->query('SELECT * FROM chat_messages WHERE user_id='.(int)$f['userId']." ORDER BY id DESC LIMIT 1")->fetch_assoc();
        $messages=(new Domain\ChatManager())->getMessageById((int)$messages['id']);
        $manager=new class extends LLM\LLMManager {
            public function liveText(string $instruction,?string $mustContain=null):?string {
                $GLOBALS['it371_instruction']=$instruction;
                return str_contains($instruction,'НЕ создана') ? "Такая задача уже есть $mustContain, повторную запись не добавляла." : "Записала новую задачу $mustContain.";
            }
        };
        $manager->processTrigger('dynamic_command',['command'=>$f['command'],'message'=>html_entity_decode($messages['raw_message'],ENT_QUOTES|ENT_HTML5,'UTF-8'),'message_id'=>$messages['id'],'user_id'=>$f['userId'],'username'=>$f['login']]);
        echo json_encode(['usedLive'=>isset($GLOBALS['it371_instruction']),'duplicate'=>str_contains($GLOBALS['it371_instruction']??'','НЕ создана')]);
    } elseif ($mode==='inspect') {
        $stmt=$db->prepare('SELECT id,text,user_id,message_id FROM feedback_backlog WHERE user_id=? ORDER BY id'); $stmt->bind_param('i',$f['userId']);$stmt->execute();
        echo json_encode(['feedback'=>$stmt->get_result()->fetch_all(MYSQLI_ASSOC),'memory'=>(new Domain\BotMemoryManager())->getPage(200)['items']]);
    } elseif ($mode==='cleanup') {
        foreach ([$f['userId'],$f['botId']] as $id) {
            $user=(new Domain\UserManager())->getUserById((int)$id);
            if (!$user || !str_starts_with($user['login'],'it371_')) throw new RuntimeException('Owner mismatch');
            $db->query('DELETE FROM bot_memory WHERE user_id='.(int)$id);
            $db->query('DELETE FROM feedback_backlog WHERE user_id='.(int)$id);
            $db->query('DELETE FROM llm_jobs WHERE JSON_EXTRACT(payload,"$.user_id")='.(int)$id);
            $db->query('DELETE FROM chat_messages WHERE user_id='.(int)$id);
            (new Domain\UserManager())->deleteUser((int)$id);
        }
        foreach ($f['saved'] as $key=>$value) {
            if ($value===null) {$stmt=$db->prepare('DELETE FROM site_options WHERE key_name=?');$stmt->bind_param('s',$key);$stmt->execute();}
            else $config->setOption($key,(string)$value);
        }
        $db->query('UPDATE bot_commands SET is_active='.(int)$f['command']['is_active'].' WHERE id='.(int)$f['command']['id']);
        $config->flushCache(); unlink($path); echo "Fixture cleaned\n";
    } else throw new RuntimeException('Unknown mode');
}
