<?php
require __DIR__.'/integration_helpers.php';
it_require_db();$db=Infra\Database::getInstance()->getConnection();$cfg=Infra\ConfigManager::getInstance();
$users=new Domain\UserManager();$chat=new Domain\ChatManager();$ids=[];$backup=[];$files=[];
$beforeJob=(int)$db->query('SELECT COALESCE(MAX(id),0) n FROM llm_jobs')->fetch_assoc()['n'];
$count=LLM\ImageGenerator::todayCount();
try {
    foreach(['owner','bot','other'] as $name){$$name=$users->createUser('it_user_quoteedit_'.bin2hex(random_bytes(6)),bin2hex(random_bytes(24)),'user','Quote '.$name);$ids[]=$$name;}
    foreach(['ai_bot_user_id'=>(string)$bot,'ai_enabled'=>'1','ai_live_confirm'=>'0','ai_routerai_key'=>'it-dummy','ai_image_llm_caption'=>'0','ai_image_daily_limit'=>'0','bot_last_manual_draw'=>'0','bot_last_autodraw'=>'0'] as $key=>$value){$backup[$key]=$cfg->getOption($key,null);$cfg->setOption($key,$value);}
    $command=null;foreach((new Domain\BotCommandManager())->getActive() as $row)if($row['handler_type']==='image'){$command=$row;break;}
    $im=imagecreatetruecolor(200,200);imagefill($im,0,0,imagecolorallocate($im,80,160,220));ob_start();imagepng($im);$file=LLM\ImageGenerator::saveJpeg(ob_get_clean(),'it_quote_edit_'.bin2hex(random_bytes(6)));$files[]=$file;
    $hash=hash_file('sha256',dirname(__DIR__).$file);
    $llm=new LLM\LLMManager();$drawing=new LLM\DrawingCommand($llm);$artist=new LLM\LyraArtist($llm);
    $picture=$chat->addMessage($bot,'Quote bot','![рисунок]('.$file.')');
    $comment=$chat->addMessage($owner,'Quote owner','Спасибо, очень красиво!',[$picture]);
    check($drawing->matchQuotedEdit($comment,$owner)===null,'praise of a drawing remains ordinary conversation');
    $question=$chat->addMessage($owner,'Quote owner','Почему фон синий?',[$picture]);
    check($drawing->matchQuotedEdit($question,$owner)===null,'question about a drawing does not open an edit');
    $greeting=$chat->addMessage($bot,'Quote bot','Привет!');$normal=$chat->addMessage($owner,'Quote owner','Замени фон синим',[$greeting]);
    check($drawing->matchQuotedEdit($normal,$owner)===null,'imperative quoting a normal reply does not invent a drawing');
    $foreign=$chat->addMessage($other,'Quote other','![рисунок]('.$file.')');$fake=$chat->addMessage($owner,'Quote owner','Измени фон',[$foreign]);
    check($drawing->matchQuotedEdit($fake,$owner)===null,'user attachment cannot impersonate a bot drawing');
    $source=$chat->addMessage($owner,'Quote owner','Сделай фон синим',[$picture]);
    $matched=$drawing->matchQuotedEdit($source,$owner);
    check($matched!==null && $drawing->matchQuotedEdit($source,$other)===null,'stored drawing quote matches only its actual requesting actor');
    $payload=['message_id'=>$source,'user_id'=>$owner,'username'=>'Quote owner','message'=>'Сделай фон синим','drawing_reply'=>true,'drawing_reply_version'=>$matched['source_version']];
    check($artist->handleDraw($matched['command'],$payload,static fn()=>throw new RuntimeException('Edit must await confirmation')),'quoted edit opens without a repeated command and does not generate yet');
    $reply=$chat->findBotReplyTo($source);preg_match('/\[\[command:(\d+)\]\]/',$reply['raw_message'],$m);$id=(int)$m[1];
    $registry=LLM\CommandInteractions::registry();$registry['drawing']['continuation']['format']=static fn()=> 'Дополнение принято.';
    $manager=new Domain\CommandInteractionManager($registry);
    $public=$manager->readPublic($id,$owner,(int)$reply['id']);
    check($public['can_act'] && $public['options'][0]['label']==='Применить правки','owner gets the real edit confirmation button');
    check(!$manager->readPublic($id,$other,(int)$reply['id'])['can_act'],'another participant cannot confirm this edit');
    $followup=$chat->addMessage($owner,'Quote owner','И добавь шар',[(int)$reply['id']]);
    check(LLM\CommandInteractionContinuation::routeMessage(['message_id'=>$followup,'user_id'=>$owner],$registry),'quoted edit accepts additional description through the existing continuation mechanism');
    $row=$db->query('SELECT context_json FROM command_interactions WHERE id='.$id)->fetch_assoc();$context=json_decode($row['context_json'],true);
    check(LLM\CommandInteractionContinuation::processWork(['interaction_id'=>$id,'revision'=>$context['revision'],'operation_key'=>$context['pending_work']['operation_key']],$registry),'edit refinement publishes the next confirmation');
    $context=json_decode($db->query('SELECT context_json FROM command_interactions WHERE id='.$id)->fetch_assoc()['context_json'],true);$child=(int)$context['child_id'];
    $outcome=$manager->consume($child,'draw',$owner);check($manager->consume($child,'draw',$owner)===$outcome,'double confirmation replays one edit request');
    $job=(int)$outcome['facts']['job_id'];$data=json_decode($db->query('SELECT payload FROM llm_jobs WHERE id='.$job)->fetch_assoc()['payload'],true);
    check($data['drawing_request']['reference_url']===$file && str_contains($data['message'],"Сделай фон синим\nИ добавь шар"),'edit job preserves the exact source image and accumulated description');
    $calls=0;$artist->handleDraw($command,$data,static function()use(&$calls):string{$calls++;return '/upload/lyra/it_edited_'.bin2hex(random_bytes(4)).'.jpg';});
    check($calls===1 && hash_file('sha256',dirname(__DIR__).$file)===$hash,'confirmed edit generates a new image and preserves the original');
    $source2=$chat->addMessage($owner,'Quote owner','Убери шляпу',[$picture]);$matched=$drawing->matchQuotedEdit($source2,$owner);
    $chat->editMessage($source2,$owner,'Почему фон синий?');
    check(!$artist->handleDraw($command,['message_id'=>$source2,'user_id'=>$owner,'drawing_reply'=>true,'drawing_reply_version'=>$matched['source_version']]),'edited source cannot reopen a stale edit request');
    $payload['drawing_reply_version']='invalid';
    check(!$artist->handleDraw($command,$payload),'forged expected source version is rejected');
} finally {
    foreach($ids as $id){$db->query('DELETE FROM command_interactions WHERE owner_id='.$id);$db->query('DELETE FROM chat_messages WHERE user_id='.$id);$users->deleteUser($id);}
    $db->query('DELETE FROM llm_jobs WHERE id>'.$beforeJob);foreach($files as $file)@unlink(dirname(__DIR__).$file);
    (new Core\FileCache('imagegen'))->set(gmdate('Y-m-d'),['count'=>$count]);
    foreach($backup as $key=>$value){if($value!==null)$cfg->setOption($key,(string)$value);else{$q=$db->prepare('DELETE FROM site_options WHERE key_name=?');$q->bind_param('s',$key);$q->execute();}}
}
it_done();
