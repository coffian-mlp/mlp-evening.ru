<?php
require dirname(__DIR__).'/integration_helpers.php';
if(PHP_SAPI!=='cli'||!is_file('/.dockerenv')||(it_config()['db']['host']??'')!=='db')exit(2);
it_require_db();$db=Infra\Database::getInstance()->getConnection();$cfg=Infra\ConfigManager::getInstance();$users=new Domain\UserManager();
$path=dirname(__DIR__,2).'/docs/private/mlp400-local.json';
$save=static function(array $s)use($path):void{$mask=umask(0077);file_put_contents($path,json_encode($s,JSON_THROW_ON_ERROR));umask($mask);chmod($path,0600);};
$action=$argv[1]??'';
if($action==='setup'){
 if(is_file($path))throw new RuntimeException('Fixture exists');
 $s=['users'=>[],'options'=>[],'files'=>[],'password'=>bin2hex(random_bytes(24)),'beforeJob'=>(int)$db->query('SELECT COALESCE(MAX(id),0) n FROM llm_jobs')->fetch_assoc()['n'],'count'=>LLM\ImageGenerator::todayCount()];
 foreach(['ai_bot_user_id','ai_enabled','ai_live_confirm','ai_use_queue','ai_worker_mode','ai_image_llm_caption','ai_image_daily_limit','ai_lifelike','ai_command_dedup_window','chat_rate_limit','ai_routerai_key'] as $key)$s['options'][$key]=$cfg->getOption($key,null);$save($s);
 foreach(['owner','bot','other'] as $name){$s[$name.'Login']='it_mlp400_'.$name.'_'.bin2hex(random_bytes(6));$s[$name]=$users->createUser($s[$name.'Login'],$s['password'],'user','Drawing '.ucfirst($name));$s['users'][]=$s[$name];$save($s);}
 $commands=new Domain\BotCommandManager();foreach($commands->getActive() as $row)if($row['handler_type']==='image'){$s['command']=$row;$save($s);$commands->update((int)$row['id'],'/нарисуй',$row['description'],$row['handler_type'],$row['system_prompt'],(int)$row['is_active']);break;}
 foreach(['ai_bot_user_id'=>(string)$s['bot'],'ai_enabled'=>'1','ai_live_confirm'=>'0','ai_use_queue'=>'1','ai_worker_mode'=>'cron','ai_image_llm_caption'=>'0','ai_image_daily_limit'=>'0','ai_lifelike'=>'0','ai_command_dedup_window'=>'0','chat_rate_limit'=>'0','ai_routerai_key'=>'it-dummy'] as $k=>$v)$cfg->setOption($k,$v);
 $im=imagecreatetruecolor(200,200);imagefill($im,0,0,imagecolorallocate($im,80,160,220));ob_start();imagepng($im);$file=LLM\ImageGenerator::saveJpeg(ob_get_clean(),'it_mlp400_'.bin2hex(random_bytes(6)));$s['files'][]=$file;$s['file']=$file;$save($s);
}elseif($action==='tick'){
 $s=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
 $llm=new class extends LLM\LLMManager{public function liveText(string $instruction,?string $mustContain=null):?string{return 'Описание принято. Дополни его ответом с цитатой или нажми кнопку.';}};
 $registry=LLM\CommandInteractions::registry();$registry['drawing']['continuation']['format']=static fn(string $state,array $result):string=>$state==='terminal'?'Заказ принят.':'Описание дополнено. Можно рисовать или продолжить уточнение.';
 $manager=new Domain\CommandInteractionManager($registry);
 foreach($manager->listRecoverableWork(200) as $work)if($work['user_id']===$s['owner'])LLM\CommandInteractionContinuation::processWork($work,$registry);
 $r=$db->query("SELECT id,payload FROM llm_jobs WHERE id>".(int)$s['beforeJob']." AND status='pending' AND type='dynamic_command'");
 while($row=$r->fetch_assoc()){
  $data=json_decode($row['payload'],true);
  if((int)($data['user_id']??0)!==$s['owner']||($data['command']['handler_type']??'')!=='image')continue;
  (new LLM\LyraArtist($llm))->handleDraw($data['command'],$data,static function(string $prompt)use(&$s,$save):string{$file=LLM\ImageGenerator::saveJpeg(file_get_contents(dirname(__DIR__,2).$s['file']),'it_mlp400_output_'.bin2hex(random_bytes(6)));$s['files'][]=$file;$save($s);return $file;});
  (new LLM\JobQueue())->complete([(int)$row['id']]);
 }
}elseif($action==='inspect'){
 $s=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);$rows=[];
 $r=$db->query('SELECT id,state,bot_message_id,context_json FROM command_interactions WHERE owner_id='.(int)$s['owner'].' ORDER BY id');while($row=$r->fetch_assoc()){$context=json_decode($row['context_json'],true);$rows[]=['id'=>(int)$row['id'],'state'=>$row['state'],'message'=>(int)$row['bot_message_id'],'description'=>LLM\DrawingCommand::description($context['handler_context']),'reference'=>$context['handler_context']['reference_url']??null];}
 $r=$db->query('SELECT id,message FROM chat_messages WHERE user_id='.(int)$s['bot'].' ORDER BY id DESC');$drawings=[];$drawingUrls=[];while($row=$r->fetch_assoc())if(str_contains($row['message'],'![рисунок]')){$drawings[]=(int)$row['id'];preg_match('#!\[[^\]]*\]\((/upload/lyra/[^)]+)\)#',$row['message'],$match);$drawingUrls[]=$match[1];}
 $jobs=[];$r=$db->query('SELECT id,status,payload FROM llm_jobs WHERE id>'.(int)$s['beforeJob']);while($row=$r->fetch_assoc()){$d=json_decode($row['payload'],true);$jobs[]=['status'=>$row['status'],'handler'=>$d['command']['handler_type']??null];}
 echo json_encode(['interactions'=>$rows,'drawings'=>$drawings,'drawingUrls'=>$drawingUrls,'jobs'=>$jobs],JSON_THROW_ON_ERROR);exit;
}elseif($action==='cleanup'){
 $s=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
 foreach($s['users'] as $id){if(!str_starts_with($users->getUserById($id)['login']??'','it_mlp400_'))throw new RuntimeException('Invalid fixture owner');$db->query('DELETE FROM command_interactions WHERE owner_id='.(int)$id);$db->query('DELETE FROM chat_messages WHERE user_id='.(int)$id);$users->deleteUser($id);}
 $db->query('DELETE FROM llm_jobs WHERE id>'.(int)$s['beforeJob']);
 foreach($s['files'] as $file)@unlink(dirname(__DIR__,2).$file);
 (new Core\FileCache('imagegen'))->set(gmdate('Y-m-d'),['count'=>$s['count']]);
 foreach($s['options'] as $key=>$value){if($value!==null)$cfg->setOption($key,(string)$value);else{$q=$db->prepare('DELETE FROM site_options WHERE key_name=?');$q->bind_param('s',$key);$q->execute();}}
 if(isset($s['command'])){$r=$s['command'];(new Domain\BotCommandManager())->update((int)$r['id'],$r['command_prefix'],$r['description'],$r['handler_type'],$r['system_prompt'],(int)$r['is_active']);}
 unlink($path);
}else throw new RuntimeException('Invalid action');
echo "Fixture PASS\n";
