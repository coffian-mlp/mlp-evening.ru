<?php
namespace LLM {
    function time(): int { return $GLOBALS['fixture_clock']; }
    function sleep(int $seconds): int { $GLOBALS['fixture_clock'] += $seconds; return 0; }
}
namespace {
require dirname(__DIR__).'/integration_helpers.php';
if(PHP_SAPI!=='cli'||!is_file('/.dockerenv')||(it_config()['db']['host']??'')!=='db')exit(2);
$lockDb=it_require_db();$db=Infra\Database::getInstance()->getConnection();$cfg=Infra\ConfigManager::getInstance();$users=new Domain\UserManager();$GLOBALS['fixture_clock']=time();
$path=dirname(__DIR__,2).'/docs/private/mlp398-local.json';
$save=static function(array $s)use($path){$old=umask(0077);file_put_contents($path,json_encode($s,JSON_THROW_ON_ERROR));umask($old);chmod($path,0600);};
$action=$argv[1]??'';
if($action==='setup'){
    if(is_file($path))throw new RuntimeException('Existing fixture');
    $s=['login'=>'it_mlp398_'.bin2hex(random_bytes(6)),'password'=>bin2hex(random_bytes(24)),'users'=>[],'options'=>[],'job'=>0];
    foreach(['ai_bot_user_id','ai_enabled','ai_use_queue','ai_worker_mode'] as $key)$s['options'][$key]=$cfg->getOption($key,null);
    $save($s);
    $s['user']=$users->createUser($s['login'],$s['password'],'user','Inline Requester');$s['users'][]=$s['user'];$save($s);
    $s['bot']=$users->createUser('it_mlp398_bot_'.bin2hex(random_bytes(6)),bin2hex(random_bytes(24)),'user','Inline Bot');$s['users'][]=$s['bot'];$save($s);
    $cfg->setOption('ai_bot_user_id',(string)$s['bot']);$cfg->setOption('ai_enabled','0');$cfg->setOption('ai_use_queue','0');$cfg->setOption('ai_worker_mode','inline');
    $s['beforeMessage']=(int)$db->query('SELECT COALESCE(MAX(id),0) n FROM chat_messages')->fetch_assoc()['n'];$save($s);
    $beforeJob=(int)$db->query('SELECT COALESCE(MAX(id),0) n FROM llm_jobs')->fetch_assoc()['n'];
    $llm=new class extends LLM\LLMManager {
        public function liveText(string $instruction,?string $mustContain=null): ?string {return null;}
    };
    if(!(int)$lockDb->query("SELECT GET_LOCK('bot_drawing',0) n")->fetch_assoc()['n'])throw new RuntimeException('Fixture lock busy');
    try{
        (new ReflectionMethod(LLM\BotDispatch::class,'inlineDrawing'))->invoke(null,['command'=>['handler_type'=>'image'],'message'=>'fixture398:'.$s['login'],'username'=>'Inline Requester'],0,static fn()=>LLM\DrawingSchedule::run(false,static fn()=>throw new RuntimeException('Generation must not start')),$llm);
    }finally{$lockDb->query("SELECT RELEASE_LOCK('bot_drawing')");}
    $row=$db->query('SELECT id,status FROM llm_jobs WHERE id>'.$beforeJob.' ORDER BY id LIMIT 1')->fetch_assoc();$s['job']=(int)$row['id'];$save($s);
    if($row['status']!=='failed')throw new RuntimeException('Inline drawing not terminal');
    $row=$db->query('SELECT id,message FROM chat_messages WHERE user_id='.(int)$s['bot'].' AND id>'.$s['beforeMessage'].' ORDER BY id LIMIT 1')->fetch_assoc();
    if(!$row||!str_contains($row['message'],'Повтори'))throw new RuntimeException('Retry notice not persisted');$s['reply']=(int)$row['id'];$save($s);
}elseif($action==='cleanup'){
    $s=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    foreach($s['users'] as $id){if(!str_starts_with($users->getUserById($id)['login']??'','it_mlp398_'))throw new RuntimeException('Unexpected fixture owner');$db->query('DELETE FROM chat_messages WHERE user_id='.(int)$id);$users->deleteUser($id);}
    if($s['job'])$db->query('DELETE FROM llm_jobs WHERE id='.(int)$s['job']);
    foreach($s['options'] as $key=>$value){if($value!==null)$cfg->setOption($key,(string)$value);else{$q=$db->prepare('DELETE FROM site_options WHERE key_name=?');$q->bind_param('s',$key);$q->execute();}}
    unlink($path);
}else throw new RuntimeException('Unknown fixture operation');
echo "Fixture operation PASS\n";
}
