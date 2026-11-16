<?php
require_once dirname(__DIR__,2).'/autoload.php';
if (!is_file(dirname(__DIR__,2).'/.mlp468-owned') || Infra\Env::get('DB_HOST')!=='db') throw new RuntimeException('Owned synthetic laboratory required');

function drawingFixture(string $action): array {
    $root=dirname(__DIR__,2); $dir=$root.'/cache/mlp468'; $path=$dir.'/state.json';
    $db=Infra\Database::getInstance()->getConnection(); $cfg=Infra\ConfigManager::getInstance(); $users=new Domain\UserManager();
    if ($action==='setup') {
        if (is_file($path)) throw new RuntimeException('Drawing fixture already exists');
        if (!is_dir($dir)) mkdir($dir,0777,true);
        $state=['users'=>[],'options'=>[],'beforeJob'=>(int)$db->query('SELECT COALESCE(MAX(id),0) n FROM llm_jobs')->fetch_assoc()['n'],
            'count'=>(new Core\FileCache('imagegen'))->get(gmdate('Y-m-d'),172800),'files'=>glob($root.'/upload/lyra/*') ?: [],'block'=>false];
        $save=static function() use (&$state,$path): void { file_put_contents($path,json_encode($state,JSON_THROW_ON_ERROR)); chmod($path,0600); };
        $save();
        foreach (['owner'=>'Рисующий','bot'=>'Лаборатория','other'=>'Другой'] as $key=>$name) {
            $state[$key.'Login']='it_mlp468_'.$key.'_'.bin2hex(random_bytes(4));
            $state['password']=bin2hex(random_bytes(16));
            $state[$key]=$users->createUser($state[$key.'Login'],$state['password'],'user',$name);
            $state['users'][]=$state[$key]; Domain\Privacy::setAccountConsent($state[$key],true); $save();
        }
        // All user passwords are synthetic; use one stable owner credential for the HTTP flow.
        $state['password']=bin2hex(random_bytes(16));
        $hash=password_hash($state['password'],PASSWORD_DEFAULT);
        $q=$db->prepare('UPDATE users SET password_hash=? WHERE id=?'); $q->bind_param('si',$hash,$state['owner']); $q->execute();
        $values=['ai_bot_user_id'=>(string)$state['bot'],'ai_enabled'=>'1','ai_primary_provider'=>'routerai','ai_routerai_key'=>'synthetic',
            'ai_openai_key'=>'','ai_openrouter_key'=>'','ai_yandex_key'=>'','ai_gigachat_key'=>'','ai_cli_enabled'=>'0',
            'ai_use_queue'=>'1','ai_worker_mode'=>'cron','ai_live_confirm'=>'1','ai_image_llm_caption'=>'0','ai_image_daily_limit'=>'0',
            'ai_image_provider'=>'routerai','ai_image_auto_interval'=>'0','ai_image_style_prompt'=>'Synthetic illustration.',
            'ai_memory_enabled'=>'0','ai_memory_auto'=>'0','ai_online_in_context'=>'0','ai_oc_auto'=>'0','ai_memory_recall_enabled'=>'0','ai_evening_summary_enabled'=>'0','ai_return_memory_enabled'=>'0',
            'ai_reactions'=>'0','ai_delay_min'=>'0','ai_delay_max'=>'0','ai_command_dedup_window'=>'0','ai_reply_min_gap'=>'0','ai_spam_threshold'=>'100',
            'ai_debounce_window'=>'0','chat_rate_limit'=>'0','bot_last_proactive'=>(string)(time()+86400),'bot_last_manual_draw'=>'0','bot_last_autodraw'=>'0'];
        foreach ($values as $key=>$value) { $state['options'][$key]=$cfg->getOption($key,null); $save(); $cfg->setOption($key,$value); }
        foreach ((new Domain\BotCommandManager())->getActive() as $command) if (in_array($command['handler_type'],['image','image_chat'],true)) $state[$command['handler_type']]=$command;
        $save(); return $state;
    }
    $state=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    if ($action==='cleanup') {
        foreach ($state['users'] as $id) {
            if (!str_starts_with($users->getUserById($id)['login'] ?? '','it_mlp468_')) throw new RuntimeException('Fixture ownership changed');
            $db->query('DELETE FROM command_interactions WHERE owner_id='.(int)$id);
            $db->query('DELETE FROM chat_messages WHERE user_id='.(int)$id);
            $db->query('DELETE FROM privacy_files WHERE user_id='.(int)$id);
            foreach (['privacy_consent_events','account_privacy','privacy_account_choices','privacy_browser_owners','online_sessions','auth_tokens','privacy_sessions'] as $table) $db->query("DELETE FROM $table WHERE user_id=".(int)$id);
            $users->deleteUser($id);
        }
        $db->query('DELETE FROM llm_jobs WHERE id>'.(int)$state['beforeJob']);
        foreach ($state['options'] as $key=>$value) {
            if ($value!==null) $cfg->setOption($key,(string)$value);
            else { $q=$db->prepare('DELETE FROM site_options WHERE key_name=?'); $q->bind_param('s',$key); $q->execute(); }
        }
        $cache=new Core\FileCache('imagegen');
        if ($state['count']===null) $cache->delete(gmdate('Y-m-d')); else $cache->set(gmdate('Y-m-d'),$state['count']);
        foreach (array_diff(glob($root.'/upload/lyra/*') ?: [],$state['files']) as $file) if (is_file($file)) unlink($file);
        foreach (glob($dir.'/*') as $file) if (is_file($file)) unlink($file);
        $cfg->flushCache(); return [];
    }
    if ($action==='block') { $state['block']=true; @unlink($dir.'/entered'); @unlink($dir.'/release'); }
    if ($action==='release') { $state['block']=false; file_put_contents($dir.'/release','1'); }
    if ($action==='block' || $action==='release') file_put_contents($path,json_encode($state,JSON_THROW_ON_ERROR));
    return $state;
}

function drawingEnqueue(array $state, string $subject='замок', string $handler='image'): int {
    $command=$state[$handler]; $text=$command['command_prefix'].' '.$subject;
    $source=(new Domain\ChatManager())->addMessage($state['owner'],'Рисующий',$text);
    LLM\BotDispatch::dispatch('dynamic_command',['command'=>$command,'message'=>$text,'message_id'=>$source,'user_id'=>$state['owner'],'username'=>'Рисующий']);
    return (int)Infra\Database::getInstance()->getConnection()->query('SELECT MAX(id) n FROM llm_jobs')->fetch_assoc()['n'];
}

function drawingInspect(array $state): array {
    $db=Infra\Database::getInstance()->getConnection(); $jobs=[];
    $r=$db->query('SELECT id,status,payload FROM llm_jobs WHERE id>'.(int)$state['beforeJob'].' ORDER BY id');
    while ($row=$r->fetch_assoc()) { $data=json_decode($row['payload'],true); $jobs[]=['id'=>(int)$row['id'],'status'=>$row['status'],'phase'=>$data['drawing_v1']['phase'] ?? null,'image'=>$data['drawing_v1']['image_message_id'] ?? null,'notice'=>$data['drawing_v1']['busy_notice_message_id'] ?? null]; }
    $pictures=[]; $messages=[];
    foreach ($db->query('SELECT id,message FROM chat_messages WHERE user_id='.(int)$state['bot'].' ORDER BY id')->fetch_all(MYSQLI_ASSOC) as $row) {
        $messages[]=['id'=>(int)$row['id'],'text'=>html_entity_decode($row['message'],ENT_QUOTES|ENT_HTML5,'UTF-8')];
        if (str_contains($row['message'],'/upload/lyra/')) $pictures[]=(int)$row['id'];
    }
    $interactions=[];
    foreach($db->query('SELECT id,state,bot_message_id FROM command_interactions WHERE owner_id='.(int)$state['owner'].' ORDER BY id')->fetch_all(MYSQLI_ASSOC)as$row)$interactions[]=['id'=>(int)$row['id'],'state'=>$row['state'],'message'=>(int)$row['bot_message_id']];
    return compact('jobs','pictures','messages','interactions');
}
