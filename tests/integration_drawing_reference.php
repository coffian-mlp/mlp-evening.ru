<?php
namespace LLM {
    function curl_init($url) { return (object)['url'=>$url,'options'=>[]]; }
    function curl_setopt_array($ch,$options) { $ch->options=$options;return true; }
    function curl_setopt($ch,$option,$value) { $ch->options[$option]=$value;return true; }
    function curl_exec($ch) {
        $GLOBALS['drawing_transport']=['url'=>$ch->url,'payload'=>json_decode($ch->options[CURLOPT_POSTFIELDS],true)];
        return json_encode(['choices'=>[['message'=>['images'=>[['image_url'=>['url'=>$GLOBALS['drawing_image_data']]]]]]]]);
    }
    function curl_getinfo($ch,$option) {return 200;}
    function curl_error($ch) {return '';}
    function curl_close($ch) {}
}
namespace {
require __DIR__.'/integration_helpers.php';it_require_db();
$cfg=Infra\ConfigManager::getInstance();$backup=[];$files=[];$ids=[];
try {
    foreach(['ai_image_provider','ai_image_model','ai_routerai_key','ai_bot_user_id','ai_enabled','ai_live_confirm'] as $key)$backup[$key]=$cfg->getOption($key,null);
    $cfg->setOption('ai_image_provider','routerai');$cfg->setOption('ai_image_model','google/gemini-3.1-flash-image');$cfg->setOption('ai_routerai_key','it-dummy');$cfg->setOption('ai_enabled','0');$cfg->setOption('ai_live_confirm','0');
    $im=imagecreatetruecolor(200,200);imagefill($im,0,0,imagecolorallocate($im,80,160,220));ob_start();imagepng($im);$bytes=ob_get_clean();
    $source=LLM\ImageGenerator::saveJpeg($bytes,'it_reference_'.bin2hex(random_bytes(6)));$files[]=$source;
    $GLOBALS['drawing_image_data']='data:image/png;base64,'.base64_encode($bytes);
    $hash=hash_file('sha256',dirname(__DIR__).$source);
    $result=LLM\ImageGenerator::generate('Change only the sky',null,$source);$files[]=$result;
    check($result!==null && $result!==$source,'edit saved as a new drawing');
    $transport=$GLOBALS['drawing_transport'];
    check(str_ends_with($transport['url'],'/chat/completions'),'Gemini uses the documented chat endpoint');
    check($transport['payload']['modalities']===['image','text'],'image output requested');
    check(str_starts_with($transport['payload']['messages'][0]['content'][1]['image_url']['url'],'data:image/jpeg;base64,'),'actual source image supplied');
    check(hash_file('sha256',dirname(__DIR__).$source)===$hash,'original drawing unchanged');
    foreach(['/etc/passwd','/upload/lyra/../private.jpg','https://example.com/a.jpg','/upload/lyra/missing.jpg'] as $invalid){try{LLM\ImageGenerator::referenceData($invalid);check(false,'reject invalid reference');}catch(RuntimeException $e){check(true,'reject invalid reference');}}
    $users=new Domain\UserManager();$chat=new Domain\ChatManager();
    foreach(['owner','bot'] as $name){$$name=$users->createUser('it_user_reference_'.bin2hex(random_bytes(6)),bin2hex(random_bytes(24)),'user');$ids[]=$$name;}
    $cfg->setOption('ai_bot_user_id',(string)$bot);
    $drawing=$chat->addMessage($bot,'Test bot','![рисунок]('.$source.')');
    $sourceId=$chat->addMessage($owner,'Test owner','/нарисуй измени фон',[$drawing]);
    $llm=new LLM\LLMManager();$command=new LLM\DrawingCommand($llm);
    check($command->reference(['message_id'=>$sourceId])===$source,'reference read from persisted quote, not client URL');
    $foreign=$chat->addMessage($owner,'Test owner','![рисунок]('.$source.')');
    check($command->reference(['quoted_msg_ids'=>[$foreign]])===null,'user message cannot impersonate bot drawing');
} finally {
    foreach($files as $file)if($file && str_starts_with($file,'/upload/lyra/'))@unlink(dirname(__DIR__).$file);
    if($ids){$db=Infra\Database::getInstance()->getConnection();foreach($ids as $id){$db->query('DELETE FROM chat_messages WHERE user_id='.$id);$users->deleteUser($id);}}
    foreach($backup as $key=>$value){if($value!==null)$cfg->setOption($key,(string)$value);else{$q=Infra\Database::getInstance()->getConnection()->prepare('DELETE FROM site_options WHERE key_name=?');$q->bind_param('s',$key);$q->execute();}}
}
it_done();
}
