<?php
require dirname(__DIR__).'/integration_helpers.php';
if (PHP_SAPI!=='cli'||!is_file('/.dockerenv')||(it_config()['db']['host']??'')!=='db') exit(2);
it_require_db();$db=Infra\Database::getInstance()->getConnection();$cfg=Infra\ConfigManager::getInstance();$users=new Domain\UserManager();
$path=dirname(__DIR__,2).'/docs/private/mlp404-local.json';
$save=static function(array $state)use($path){$old=umask(0077);file_put_contents($path,json_encode($state,JSON_THROW_ON_ERROR));umask($old);chmod($path,0600);};
$action=$argv[1]??'';
if($action==='setup'){
 if(is_file($path))throw new RuntimeException('Fixture exists');
 $s=['users'=>[],'options'=>[],'password'=>bin2hex(random_bytes(24)),'beforeJob'=>(int)$db->query('SELECT COALESCE(MAX(id),0) n FROM llm_jobs')->fetch_assoc()['n']];$save($s);
 foreach(['ai_bot_user_id','ai_enabled','ai_use_queue','ai_worker_mode','ai_delay_min','ai_delay_max','ai_command_dedup_window','chat_rate_limit'] as $key)$s['options'][$key]=$cfg->getOption($key,null);$save($s);
 foreach(['owner','bot'] as $name){$s[$name.'Login']='it_mlp404_'.$name.'_'.bin2hex(random_bytes(6));$s[$name]=$users->createUser($s[$name.'Login'],$s['password'],'user','Delivery '.ucfirst($name));$s['users'][]=$s[$name];$save($s);}
 (new Domain\BotCommandManager())->create('/проверкадоставки','Delivery fixture','text','Fixture',1);$s['command']=$db->insert_id;$save($s);
 foreach(['ai_bot_user_id'=>(string)$s['bot'],'ai_enabled'=>'0','ai_use_queue'=>'0','ai_worker_mode'=>'inline','ai_delay_min'=>'0','ai_delay_max'=>'0','ai_command_dedup_window'=>'0','chat_rate_limit'=>'0'] as $k=>$v)$cfg->setOption($k,$v);
}elseif($action==='recover'){
 $s=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);$cfg->setOption('ai_enabled','1');
 $llm=new class extends LLM\LLMManager{public function isEnabled(){return true;}public function processTrigger($type,$data=[]){return $this->botSay('Ответ команды сохранён и доставлен.',[]);}};
 $r=$db->query('SELECT * FROM llm_jobs WHERE id>'.(int)$s['beforeJob']." AND type='dynamic_command'");
 while($job=$r->fetch_assoc()){$job['data']=json_decode($job['payload'],true);if((int)($job['data']['command']['id']??0)!==(int)$s['command'])continue;LLM\CommandReplyDelivery::processJob($llm,new LLM\JobQueue(),$job,true);}
 $cfg->setOption('ai_enabled','0');
}elseif($action==='inspect'){
 $s=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);$jobs=[];
 $r=$db->query('SELECT status,payload FROM llm_jobs WHERE id>'.(int)$s['beforeJob']." AND type='dynamic_command'");while($row=$r->fetch_assoc()){$data=json_decode($row['payload'],true);if((int)($data['command']['id']??0)===(int)$s['command'])$jobs[]=['status'=>$row['status'],'receipt'=>$data['reply_message_id']??null];}
 $count=(int)$db->query('SELECT COUNT(*) n FROM chat_messages WHERE user_id='.(int)$s['bot'])->fetch_assoc()['n'];echo json_encode(['jobs'=>$jobs,'replies'=>$count]);exit;
}elseif($action==='cleanup'){
 $s=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
 foreach($s['users'] as $id){if(!str_starts_with($users->getUserById($id)['login']??'','it_mlp404_'))throw new RuntimeException('Invalid owner');$db->query('DELETE FROM chat_messages WHERE user_id='.(int)$id);$users->deleteUser($id);}
 $db->query('DELETE FROM llm_jobs WHERE id>'.(int)$s['beforeJob']);if(isset($s['command']))(new Domain\BotCommandManager())->delete((int)$s['command']);
 foreach($s['options'] as $key=>$value){if($value!==null)$cfg->setOption($key,(string)$value);else{$q=$db->prepare('DELETE FROM site_options WHERE key_name=?');$q->bind_param('s',$key);$q->execute();}}
 unlink($path);
}else throw new RuntimeException('Invalid action');
echo "Fixture PASS\n";
