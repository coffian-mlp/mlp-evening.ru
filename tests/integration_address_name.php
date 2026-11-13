<?php
/** Addressing paths with current synthetic identities, without external calls. */
require_once __DIR__ . '/integration_helpers.php';
it_require_db()->close();
$db = Infra\Database::getInstance()->getConnection();
$users = new Domain\UserManager();
class Address463LLM extends LLM\LLMManager {
    public array $said = []; public array $instructions = []; public array $replyContext = [];
    public function generateReply(array $context, string $extraInstruction = '', bool $fast = false): ?string { $this->replyContext=$context; return null; }
    public function botSay(string $text, array $quotedIds = []) { $this->said[]=$text; return true; }
    public function botSayLive(string $instruction,string $fallback,array $quotedIds=[],?string $mustContain=null) { $this->instructions[]=$instruction; return $this->botSay($fallback,$quotedIds); }
}
class Address463Provider implements LLM\LLMProviderInterface {
    public array $requests=[]; public string $response = 'Рада тебя видеть!';
    public function askChat(array $context,string $prompt): ?string { $this->requests[]=$context; return $this->response; }
}
$db->begin_transaction();
try {
    $run = bin2hex(random_bytes(5));
    $actor = $users->createUser('address_actor_'.$run,'Synthetic-463!','user','Текущий Ник '.$run);
    $other = $users->createUser('address_other_'.$run,'Synthetic-463!','user','Другой Ник '.$run);
    $nick='Текущий Ник '.$run; $login='address_actor_'.$run;
    foreach ([$actor,$other] as $id) { Domain\Privacy::setAccountConsent($id,true,false); Domain\Privacy::unlockAccount($id); }
    $cfg=Infra\ConfigManager::getInstance();
    foreach (['ai_enabled'=>'1','ai_primary_provider'=>'routerai','ai_routerai_key'=>'synthetic','ai_bot_user_id'=>(string)$other,'ai_memory_enabled'=>'0','ai_evening_summary_enabled'=>'0','ai_return_memory_enabled'=>'0','ai_online_in_context'=>'0','ai_reactions'=>'0','ai_debug_log'=>'0'] as $k=>$v) $cfg->setOption($k,$v);
    $llm=new Address463LLM(); $provider=new Address463Provider();
    foreach (['providers'=>[$provider],'fastProviders'=>[$provider]] as $k=>$v) (new ReflectionProperty(LLM\LLMManager::class,$k))->setValue($llm,$v);
    check($llm->actionRecipientPrefix($actor)==='@'.$nick.', ','action prefix is current nickname with spaces');
    check($llm->addressActionReply('Готово.',$actor)==='@'.$nick.', Готово.','server prepends nickname to unaddressed reply');
    check($llm->addressActionReply('@'.$nick.', готово.',$actor)==='@'.$nick.', готово.','guard accepts complete own nickname');
    check($llm->addressActionReply('@'.$login.', готово.',$actor)==='@'.$nick.', готово.','guard canonicalizes own login');
    check($llm->addressActionReply('@Другой Ник '.$run.', готово.',$actor)===null,'guard rejects another complete nickname');
    check($llm->addressActionReply('@'.$nick.' и @invented, готово.',$actor)===null,'guard rejects unknown mention beside own nickname');
    $payload=['user_id'=>$actor,'username'=>'SavedOldName','message'=>''];
    $llm->processTrigger('greeting',$payload);
    $request=end($provider->requests); $instruction=end($request)['content'];
    check(str_contains($instruction,'@'.$nick)&&!str_contains($instruction,'SavedOldName'),'native greeting instruction uses current nickname');
    (new ReflectionMethod(LLM\LLMManager::class,'handleTodoCommand'))->invoke($llm,['command_prefix'=>'/todo'],array_replace($payload,['message'=>'/todo']));
    check(str_starts_with(end($llm->said),'@'.$nick.', '),'todo template uses current nickname');
    (new LLM\RecapCommand($llm))->handle(['command_prefix'=>'/штош'],$payload);
    check(str_starts_with(end($llm->said),'@'.$nick.', '),'recap template uses current nickname');
    (new LLM\LyraArtist($llm))->handleDraw(['command_prefix'=>'/нарисуй'],$payload,fn()=>null);
    check(str_starts_with(end($llm->said),'@'.$nick.', '),'drawing template uses current nickname');
    (new LLM\ModerationCommand($llm))->handle(['command_prefix'=>'/мут','handler_type'=>'mute'],$payload);
    check(str_starts_with(end($llm->said),'@'.$nick.', '),'moderation template uses current nickname');
    (new LLM\LyraMemory($llm))->handleRemember(['command_prefix'=>'/запомни'],$payload);
    check(str_starts_with(end($llm->said),'@'.$nick.', '),'memory template uses current nickname');
    (new LLM\LyraOc($llm))->handleSetOwn(['command_prefix'=>'/яос'],$payload);
    check(str_starts_with(end($llm->said),'@'.$nick.', '),'appearance template uses current nickname');
    check(method_exists(Domain\UserMention::class,'currentAddressName')&&Domain\UserMention::currentAddressName(0,'SavedGuest')==='SavedGuest','guest keeps saved name');
    $worker = new LLM\BotWorker();
    (new ReflectionProperty(LLM\BotWorker::class,'llm'))->setValue($worker,$llm);
    $cfg->setOption('ai_reply_min_gap','0');
    (new ReflectionMethod(LLM\BotWorker::class,'handleMentions'))->invoke($worker,[['id'=>0,'user_id'=>$actor,'username'=>'SavedOldName','message'=>'Синтетический вопрос']]);
    check(str_contains(end($llm->replyContext)['content'],'@'.$nick)&&!str_contains(end($llm->replyContext)['content'],'SavedOldName'),'queued reply policy addresses current nickname');
    $reminders = new Domain\ReminderManager();
    $reminders->add($actor,'SavedOldName','Синтетическое напоминание',gmdate('Y-m-d H:i:s',time()-10));
    (new ReflectionMethod(LLM\BotWorker::class,'deliverReminders'))->invoke($worker);
    check(str_starts_with(end($llm->said),'@'.$nick.', '),'due reminder ignores saved author name');
    $drawCommand=['id'=>463,'handler_type'=>'image','command_prefix'=>'/нарисуй'];
    $original=LLM\CommandDedup::findOriginal([['status'=>'done','age'=>1,'data'=>['user_id'=>$actor,'username'=>'SavedOldName','command'=>$drawCommand,'message'=>'/нарисуй пони']]],LLM\CommandDedup::key($drawCommand,'/нарисуй пони'));
    check(($original['user_id']??0)===$actor,'dedup receipt preserves original author identity');
    $llm->processTrigger('dynamic_command',['user_id'=>$other,'username'=>'AnotherSavedName','command'=>$drawCommand,'dedup_of'=>$original]);
    check(str_contains(end($llm->instructions),'@'.$nick)&&!str_contains(end($llm->instructions),'SavedOldName'),'dedup instruction refreshes original author');
    $chat = new class extends Domain\ChatManager {
        public string $last = '';
        public function addMessage($userId,$username,$message,$quotedMsgIds=[],bool $deferTransport=false) { $this->last=$message; return 1; }
    };
    $publisher = new LLM\LLMManager();
    (new ReflectionProperty(LLM\LLMManager::class,'chatManager'))->setValue($publisher,$chat);
    foreach (['providers'=>[$provider],'fastProviders'=>[$provider]] as $k=>$v) (new ReflectionProperty(LLM\LLMManager::class,$k))->setValue($publisher,$v);
    $provider->response='@'.$login.', привет!';
    $publisher->processTrigger('greeting',$payload);
    check($chat->last==='@'.$nick.', привет!','native greeting is published with nickname at the beginning');
    $publisher->publishConversationReply('@'.$login.', привет!');
    check($chat->last==='@'.$nick.', привет!','ordinary and spontaneous publication canonicalizes generated login');
    $resolved=Domain\UserMention::matches($chat->last,$users->mentionDirectory());
    check(count($resolved)===1&&(int)$resolved[0]['user']['id']===$actor,'published address still targets original user');
    $users->updateUser($actor,['nickname'=>'Переименован '.$run]);
    check($llm->actionRecipientPrefix($actor)==='@Переименован '.$run.', ','rename immediately changes action prefix');
    $users->updateUser($actor,['nickname'=>'Другой Ник '.$run]);
    check($llm->actionRecipientPrefix($actor)==='@'.$login.', ','ambiguous nickname falls back to login');
    $users->updateUser($actor,['nickname'=>'bad@nickname']);
    check($llm->actionRecipientPrefix($actor)==='@'.$login.', ','unsafe nickname falls back to login');
} finally { $db->rollback(); Infra\ConfigManager::getInstance()->flushCache(); Domain\UserMention::invalidateDirectory(); }
it_done();
