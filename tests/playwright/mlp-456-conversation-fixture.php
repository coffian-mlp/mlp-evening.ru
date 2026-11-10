<?php
require dirname(__DIR__,2).'/autoload.php';
if(PHP_SAPI!=='cli'||!is_file('/.dockerenv')||Infra\Env::get('DB_HOST')!=='db'||Infra\Env::get('DB_NAME')!=='mlp_phase3'||getenv('MLP_CONVERSATION_PW')!=='1')throw new RuntimeException('Isolated conversation fixture only');
$db=Infra\Database::getInstance()->getConnection();$cfg=Infra\ConfigManager::getInstance();$file=dirname(__DIR__,2).'/phase3-browser-state.json';$mode=$argv[1]??'';
if($mode==='setup'){
 if(is_file($file))throw new RuntimeException('Previous fixture requires cleanup');
 $state=['login'=>'it_return_pw_'.bin2hex(random_bytes(5)),'password'=>bin2hex(random_bytes(15)),'messages'=>[],'episodes'=>[]];
 $um=new Domain\UserManager();$state['user']=$um->createUser($state['login'],$state['password'],'user','Синтетический участник');$state['bot']=$um->createUser($state['login'].'_bot',bin2hex(random_bytes(15)),'user','Синтетик');
 foreach([$state['user'],$state['bot']]as$id)Domain\Privacy::setAccountConsent($id,true,false);
 foreach(['ai_enabled'=>1,'ai_bot_user_id'=>$state['bot'],'ai_primary_provider'=>'openai','ai_openai_key'=>'synthetic_browser','ai_openai_model'=>'synthetic','ai_openai_base_url'=>'http://127.0.0.1:8117/_fake_model','ai_fast_model'=>'synthetic','ai_routerai_key'=>'','ai_openrouter_key'=>'','ai_use_queue'=>1,'ai_worker_mode'=>'cron','bot_worker_heartbeat'=>time(),'ai_greeting_cooldown'=>600,'ai_memory_enabled'=>1,'ai_memory_recall_enabled'=>0,'ai_evening_summary_enabled'=>0,'ai_return_memory_enabled'=>1,'ai_return_memory_days'=>14,'ai_memory_block_limit'=>8000,'ai_online_in_context'=>0,'ai_reactions'=>0,'ai_lifelike'=>0,'ai_delay_min'=>0,'ai_delay_max'=>0,'ai_aliases'=>'синтетик','ai_reply_min_gap'=>0,'ai_debug_log'=>0,'ai_memory_auto'=>0,'ai_auto_draw'=>0,'ai_image_auto_interval'=>0,'ai_poll_participation'=>0,'ai_proactive_interval'=>PHP_INT_MAX,'bot_last_proactive'=>time()]as$k=>$v){$state['options'][$k]=$cfg->getOption($k,null);$cfg->setOption($k,(string)$v);}
 $state['dossier']=(new Domain\BotMemoryManager())->add('dossier',$state['user'],'Любит синтетические шутки о чайнике','manual',$state['user']);
 $at=gmdate('Y-m-d H:i:s',time()-40*86400);$s=$db->prepare('INSERT INTO chat_messages(user_id,username,message,created_at) VALUES(?,?,?,?)');$text='Синтетическая шутка про чайник';$s->bind_param('isss',$state['user'],$state['login'],$text,$at);$s->execute();$state['messages'][]=$id=(int)$db->insert_id;
 $llm=new LLM\LLMManager();$profile=$llm->conversationRecallProfile();$reader=new LLM\MemorySourceReader();$builder=new LLM\MemoryEpisodeBuilder($state['bot']);$index=new Domain\MemoryEpisodeManager();$ep=$builder->chat((new Domain\ChatManager())->memorySourceRows([$id]),$profile)[0];$r=$index->register($ep,$reader->current(...));$v=array_fill(0,512,0.0);$v[0]=1;$index->publish($ep,$r,'synthetic',$v,$reader->current(...));$state['episodes'][]=$r['id'];
 $db->query('UPDATE users SET last_seen=UTC_TIMESTAMP()-INTERVAL 30 DAY WHERE id='.$state['user']);
 (new Domain\ConversationMemoryStore())->purge();
 umask(0077);file_put_contents($file,json_encode($state,JSON_THROW_ON_ERROR));echo json_encode(['login'=>$state['login'],'password'=>$state['password'],'user'=>$state['user'],'bot'=>$state['bot']]);exit;
}
$state=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);$uid=(int)$state['user'];$bot=(int)$state['bot'];
if($mode==='work'){
 $db->query("UPDATE llm_jobs SET run_after=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE status='pending'");
 $w=new LLM\BotWorker();$w->tick();echo'{}';
}elseif($mode==='inspect'){
 $rows=$db->query("SELECT message FROM chat_messages WHERE user_id=$bot ORDER BY id")->fetch_all(MYSQLI_ASSOC);echo json_encode(['messages'=>array_column($rows,'message'),'arrivals'=>$db->query("SELECT state FROM bot_memory_arrivals WHERE user_id=$uid")->fetch_assoc()],JSON_UNESCAPED_UNICODE);
}elseif($mode==='off'){$cfg->setOption('ai_return_memory_enabled','0');echo'{}';
}elseif($mode==='reset'){
 $db->query("DELETE FROM chat_messages WHERE user_id=$bot");$db->query("DELETE FROM llm_jobs WHERE JSON_EXTRACT(payload,'$.user_id')=$uid");$db->query("DELETE FROM bot_memory_arrivals WHERE user_id=$uid");$db->query("UPDATE users SET last_seen=UTC_TIMESTAMP()-INTERVAL 30 DAY WHERE id=$uid");echo'{}';
}elseif($mode==='cleanup'){
 foreach($state['episodes']as$id)$db->query('DELETE FROM memory_episodes WHERE id='.(int)$id);
 $db->query("DELETE FROM llm_jobs WHERE JSON_EXTRACT(payload,'$.user_id')=$uid");$db->query("DELETE FROM chat_messages WHERE user_id IN ($uid,$bot)");$db->query("DELETE FROM bot_memory WHERE user_id IN ($uid,$bot) OR created_by IN ($uid,$bot)");
 foreach([$uid,$bot]as$id){$db->query("DELETE FROM bot_memory_arrivals WHERE user_id=$id");foreach(['privacy_consent_events','account_privacy','privacy_sessions','online_sessions','user_options']as$table)$db->query("DELETE FROM $table WHERE user_id=$id");$db->query("DELETE FROM users WHERE id=$id");}
 unlink($file);foreach($state['options'] as$k=>$v){if($v===null){$s=$db->prepare('DELETE FROM site_options WHERE key_name=?');$s->bind_param('s',$k);$s->execute();}else$cfg->setOption($k,$v);}$cfg->flushCache();echo'{}';
}else throw new RuntimeException('Unknown fixture mode');
