<?php
require_once dirname(__DIR__).'/integration_helpers.php';
if(PHP_SAPI!=='cli'||!is_file('/.dockerenv')||(it_config()['db']['host']??'')!=='db')exit(1);
it_require_db();$db=Infra\Database::getInstance()->getConnection();$config=Infra\ConfigManager::getInstance();$users=new Domain\UserManager();
$path=dirname(__DIR__,2).'/docs/private/mlp391-local.json';
$save=static function(array $state)use($path){$old=umask(0077);file_put_contents($path,json_encode($state,JSON_THROW_ON_ERROR));umask($old);chmod($path,0600);};
if(($argv[1]??'')==='setup'){
 if(is_file($path))throw new RuntimeException('Existing owned fixture');
 $s=['login'=>'it_mlp391_'.bin2hex(random_bytes(6)),'password'=>bin2hex(random_bytes(24)),'users'=>[],'options'=>[],'messages'=>[],'job'=>null];
 foreach(['ai_bot_user_id','ai_reactions','ai_enabled','ai_reply_min_gap'] as $key)$s['options'][$key]=$config->getOption($key,null);
 $save($s);
 $s['userId']=$users->createUser($s['login'],$s['password'],'user','Batch Requester');$s['users'][]=$s['userId'];$save($s);
 $s['botId']=$users->createUser('it_mlp391_bot_'.bin2hex(random_bytes(6)),bin2hex(random_bytes(24)),'user','Batch Bot');$s['users'][]=$s['botId'];$save($s);
 $config->setOption('ai_bot_user_id',(string)$s['botId']);$config->setOption('ai_reactions','1');$config->setOption('ai_enabled','0');$config->setOption('ai_reply_min_gap','0');
 for($i=0;$i<10;$i++){
  $uid=$i%2?$s['userId']:$s['botId'];$name=$i%2?'Batch Requester':'Batch Bot';
  $text=$i===9?'@Batch Bot поставь последним 10 сообщениям какашки':'mlp391-fixture-message-'.$i;
  $stmt=$db->prepare('INSERT INTO chat_messages(user_id,username,message,created_at) VALUES (?,?,?,UTC_TIMESTAMP())');$stmt->bind_param('iss',$uid,$name,$text);$stmt->execute();$s['messages'][]=(int)$stmt->insert_id;$save($s);
 }
 $raw='[№34213] @Batch Requester, готово!';foreach($s['messages'] as $id)$raw.="\n[РЕАКЦИЯ: poop $id]";
 $llm=new class($raw) extends LLM\LLMManager {
  public array $seen=[];
  public function __construct(private string $reply){parent::__construct();}
  public function generateReply(array $context,string $extraInstruction='',bool $fast=false):?string{$this->seen=$context;return $this->reply;}
 };
 $source=end($s['messages']);$payload=['message_id'=>$source,'user_id'=>$s['userId'],'username'=>'Batch Requester','message'=>'@Batch Bot поставь последним 10 сообщениям какашки'];
 $s['job']=(new LLM\JobQueue())->enqueue('mention',$payload,0);$save($s);
 $worker=new LLM\BotWorker();(new ReflectionProperty($worker,'llm'))->setValue($worker,$llm);
 (new ReflectionMethod($worker,'handleMentions'))->invoke($worker,[['id'=>$s['job'],'attempts'=>0]+$payload]);
 $row=$db->query('SELECT id,message FROM chat_messages WHERE user_id='.(int)$s['botId'].' AND id>'.$source.' ORDER BY id DESC LIMIT 1')->fetch_assoc();
 if(!$row)throw new RuntimeException('Worker reply missing');$s['replyId']=(int)$row['id'];$s['messages'][]=$s['replyId'];$save($s);
 if($row['message']!=='@Batch Requester, готово!')throw new RuntimeException('Service annotations escaped into reply');
 $targets=array_slice($s['messages'],0,10);$where=implode(',',$targets);
 $count=(int)$db->query('SELECT COUNT(*) AS n FROM chat_reactions WHERE user_id='.(int)$s['botId']." AND reaction='poop' AND message_id IN ($where)")->fetch_assoc()['n'];
 if($count!==10)throw new RuntimeException('Whole worker reaction batch not applied');
 foreach($llm->seen as $entry)if(in_array((int)($entry['chat_message_id']??0),$targets,true)&&preg_match('/\[№\d+\]/u',$entry['content']))throw new RuntimeException('Source IDs remain in dialogue');
 // Replaying a model response must preserve all ten reactions.
 $llm->applyReplyReaction($raw,$llm->seen,$source);
 $count=(int)$db->query('SELECT COUNT(*) AS n FROM chat_reactions WHERE user_id='.(int)$s['botId']." AND reaction='poop' AND message_id IN ($where)")->fetch_assoc()['n'];
 if($count!==10)throw new RuntimeException('Replay toggled reactions off');
 $config->setOption('ai_reactions','0');$llm->applyReplyReaction('[РЕАКЦИЯ: heart '.$source.']',$llm->seen,$source);
 if((int)$db->query("SELECT COUNT(*) AS n FROM chat_reactions WHERE user_id=".(int)$s['botId']." AND reaction='heart'")->fetch_assoc()['n']!==0)throw new RuntimeException('Disabled reactions were applied');
 $config->setOption('ai_reactions','1');
 echo "Worker fixture: ten real targets, clean reply, replay and disabled switch PASS\n";
}elseif(($argv[1]??'')==='cleanup'){
 $s=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
 foreach($s['users'] as $id){$user=$users->getUserById($id);if(!$user||!str_starts_with($user['login'],'it_mlp391_'))throw new RuntimeException('Unexpected fixture owner');$stmt=$db->prepare('DELETE FROM chat_messages WHERE user_id=?');$stmt->bind_param('i',$id);$stmt->execute();$users->deleteUser($id);}
 if($s['job'])$db->query('DELETE FROM llm_jobs WHERE id='.(int)$s['job']);
 foreach($s['options'] as $key=>$value){if($value!==null)$config->setOption($key,(string)$value);else{$stmt=$db->prepare('DELETE FROM site_options WHERE key_name=?');$stmt->bind_param('s',$key);$stmt->execute();}}
 unlink($path);echo "Owned fixture removed and options restored\n";
}
