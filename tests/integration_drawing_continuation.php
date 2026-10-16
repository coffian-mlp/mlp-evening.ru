<?php
require __DIR__ . '/integration_helpers.php';
it_require_db(); $db=Infra\Database::getInstance()->getConnection();
$cfg=Infra\ConfigManager::getInstance();$users=new Domain\UserManager();$chat=new Domain\ChatManager();$ids=[];$backup=[];
$beforeJob=(int)$db->query('SELECT COALESCE(MAX(id),0) n FROM llm_jobs')->fetch_assoc()['n'];
$manager=new Domain\CommandInteractionManager(LLM\CommandInteractions::registry());
try {
    foreach(['ai_bot_user_id','ai_enabled','ai_live_confirm','ai_routerai_key'] as $key)$backup[$key]=$cfg->getOption($key,null);
    foreach(['owner','bot','other'] as $name){$$name=$users->createUser('it_user_draft_'.bin2hex(random_bytes(6)),bin2hex(random_bytes(24)),'user','Drawing '.$name);$ids[]=$$name;}
    $cfg->setOption('ai_bot_user_id',(string)$bot);$cfg->setOption('ai_enabled','1');$cfg->setOption('ai_routerai_key','it-dummy');$cfg->setOption('ai_live_confirm','0');
    $command=null;foreach((new Domain\BotCommandManager())->getActive() as $row)if($row['handler_type']==='image'){$command=$row;break;}
    if(!$command)throw new RuntimeException('Active image command required');
    $llm=new class extends LLM\LLMManager {public function liveText(string $instruction,?string $mustContain=null): ?string {return 'Собираю описание. Дополни ответом с цитатой, затем нажми кнопку.';}};
    $source=$chat->addMessage($owner,'Drawing owner',$command['command_prefix'].' черновик замок');
    $payload=['message_id'=>$source,'message'=>$command['command_prefix'].' черновик замок','user_id'=>$owner,'username'=>'Drawing owner'];
    check((new LLM\LyraArtist($llm))->handleDraw($command,$payload,static fn()=>throw new RuntimeException('Draft must not generate')), 'draft does not invoke generator');
    $reply=$chat->findBotReplyTo($source);preg_match('/\[\[command:(\d+)\]\]/',$reply['raw_message'],$m);$id=(int)$m[1];
    check($manager->readPublic($id,$owner,(int)$reply['id'])['can_act'], 'owner can use draft');
    $greeting=$chat->addMessage($bot,'Drawing bot','Привет!');$normal=$chat->addMessage($owner,'Drawing owner','Что нового?',[$greeting]);
    check(!LLM\CommandInteractionContinuation::routeMessage(['message_id'=>$normal,'user_id'=>$owner],LLM\CommandInteractions::registry()), 'ordinary quote ignores an existing unrelated drawing draft');
    check(!$manager->readPublic($id,$other,(int)$reply['id'])['can_act'], 'other user cannot use draft');
    $followup=$chat->addMessage($owner,'Drawing owner','на облаке',[(int)$reply['id']]);
    check(LLM\CommandInteractionContinuation::routeMessage(['message_id'=>$followup,'user_id'=>$owner],LLM\CommandInteractions::registry()), 'quoted continuation is routed');
    $row=$db->query('SELECT context_json FROM command_interactions WHERE id='.$id)->fetch_assoc();$context=json_decode($row['context_json'],true);
    check(LLM\DrawingCommand::description($context['handler_context'])==="замок\nна облаке", 'description parts accumulate');
    // Resolve using the same generic lease/publish path as the worker, without network calls.
    $registry=LLM\CommandInteractions::registry();$registry['drawing']['continuation']['format']=static fn(string $state,array $result): string=>'Описание уточнено. Нажми «Нарисовать».';
    check(LLM\CommandInteractionContinuation::processWork(['interaction_id'=>$id,'revision'=>$context['revision'],'operation_key'=>$context['pending_work']['operation_key']],$registry), 'shared continuation work publishes a revised choice');
    $row=$db->query('SELECT context_json FROM command_interactions WHERE id='.$id)->fetch_assoc();$child=json_decode($row['context_json'],true)['child_id'];
    $first=$manager->consume($child,'draw',$owner);$second=$manager->consume($child,'draw',$owner);
    check($first===$second && $first['code']==='drawing_queued','double click replays one outcome');
    $cfg->setOption('ai_enabled','0');
    check(!LLM\DrawingCommand::allowed($owner,[]),'disabled AI cannot accept new drawing orders');
    $cfg->setOption('ai_enabled','1');
    $job=(int)$first['facts']['job_id'];$jobRow=$db->query('SELECT payload FROM llm_jobs WHERE id='.$job)->fetch_assoc();$data=json_decode($jobRow['payload'],true);
    check(str_contains($data['message'],"замок\nна облаке") && $data['drawing_request']['reference_url']===null,'durable drawing job receives full description');
    $classify=LLM\DrawingCommand::interactionRegistry()['drawing']['continuation']['classify'];
    check($classify('Как дела?','pending')['intent']==='unknown','ordinary bot mention does not edit draft');
    check($classify('спасибо','pending',['has_quote'=>true])['intent']==='unknown','thanks does not edit draft');
    try{$manager->consume($child,'draw',$other);check(false,'foreign click rejected');}catch(Core\UserError $e){check(true,'foreign click rejected');}
    $source2=$chat->addMessage($owner,'Drawing owner',$command['command_prefix']);
    check((new LLM\LyraArtist($llm))->handleDraw($command,['message_id'=>$source2,'message'=>$command['command_prefix'],'user_id'=>$owner]),'empty command opens draft');
    $reply2=$chat->findBotReplyTo($source2);preg_match('/\[\[command:(\d+)\]\]/',$reply2['raw_message'],$m);
    $outcome=$manager->consume((int)$m[1],'cancel',$owner);
    check($outcome['status']==='cancelled','empty draft can be cancelled without generation');
    $source3=$chat->addMessage($owner,'Drawing owner',$command['command_prefix'].' черновик старое описание');
    $snapshot=$chat->getMessageById($source3);
    $chat->editMessage($source3,$owner,'/расписание');
    try {
        $manager->createContinuation('drawing',$owner,$source3,[],['drawing_request'=>true,'command_id'=>(int)$command['id'],'original_query'=>'старое описание'],'clarifying',Domain\ChatManager::interactionSourceVersion($snapshot));
        check(false,'edited source must not bind old description');
    } catch(Core\UserError $e){check(true,'edited source must not bind old description');}
} finally {
    foreach($ids as $id){$db->query('DELETE FROM command_interactions WHERE owner_id='.$id);$db->query('DELETE FROM chat_messages WHERE user_id='.$id);$users->deleteUser($id);}
    $db->query('DELETE FROM llm_jobs WHERE id>'.$beforeJob);
    foreach($backup as $key=>$value){if($value!==null)$cfg->setOption($key,(string)$value);else{$q=$db->prepare('DELETE FROM site_options WHERE key_name=?');$q->bind_param('s',$key);$q->execute();}}
}
it_done();
