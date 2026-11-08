<?php
/** MLP-457: actual generation/delivery paths, fake providers only, Docker fixtures. */
require_once __DIR__ . '/integration_helpers.php';
$db = it_require_db();
$cfg = Infra\ConfigManager::getInstance();
$settings = ['ai_enabled'=>1,'ai_live_confirm'=>1,'ai_debug_log'=>0,'ai_memory_enabled'=>0,'ai_memory_recall_enabled'=>0,
    'ai_online_in_context'=>0,'ai_reactions'=>0,'ai_system_prompt'=>'Синтетическая проверка.','ai_additional_instructions'=>'',
    'ai_reply_min_gap'=>0,'ai_spam_threshold'=>100];
$saved=[];$ids=[];$userIds=[];
foreach (array_merge(array_keys($settings),['ai_bot_user_id']) as $key) $saved[$key]=$cfg->getOptionDetails($key)['value']??null;
class CjkFixtureProvider implements LLM\LLMProviderInterface {
    public array $payloads=[];
    private object $trace;
    public function __construct(private array $answers, private $onCall=null) { $this->trace = new stdClass(); $this->trace->payloads=[]; $this->payloads =& $this->trace->payloads; }
    public function withTimeout(int $timeout): self { return $this; }
    public function askChat(array $messagesContext,string $systemPrompt): ?string {
        $index=count($this->payloads);$this->payloads[]=['messages'=>$messagesContext,'system'=>$systemPrompt,'deadline'=>LLM\RequestDeadline::at()];
        if ($this->onCall) ($this->onCall)($index);
        $answer=$this->answers[$index]??null;
        if ($answer instanceof Throwable) throw $answer;
        return $answer;
    }
}
try {
    $tag='cjk457_'.bin2hex(random_bytes(5));
    foreach (['reader','bot'] as $role) $userIds[]=(new Domain\UserManager())->createUser($tag.'_'.$role,'Synthetic-457!', 'user');
    [$reader,$bot]=$userIds;$settings['ai_bot_user_id']=$bot;
    foreach ($settings as $key=>$value) $cfg->setOption($key,$value);
    $manager=new LLM\LLMManager();
    $install=function(array $answers, $onCall=null) use ($manager): CjkFixtureProvider {
        $p=new CjkFixtureProvider($answers,$onCall);
        foreach (['providers','fastProviders'] as $name) (new ReflectionProperty($manager,$name))->setValue($manager,[$p]);
        (new ReflectionProperty($manager,'vlessLink'))->setValue($manager,null);
        return $p;
    };
    $context=[['role'=>'user','chat_message_id'=>123,'content'=>'Расскажи про вечер.']];
    $cases=[
        'clean'=>[['Готово.'],'Готово.',1],
        'repair'=>[['Моё个人 созвездие.','Моё личное созвездие.'],'Моё личное созвездие.',2],
        'repeat_cjk'=>[['Первая 悔 фраза.','Вторая 法庭 фраза.'],'Вторая фраза.',2],
        'empty'=>[['Первая 悔 фраза.',null],'Первая фраза.',2],
        'error'=>[['Первая 悔 фраза.',new RuntimeException('synthetic')],'Первая фраза.',2],
        'truncated'=>[['Первая 悔 фраза.',new LLM\TruncatedResponseException('fake','Обрыв 法庭')],'Первая фраза.',2],
        'image'=>[['Первая 悔 фраза.','Картинка ![подпись](https://example.test/fake.png)'],'Первая фраза.',2],
        'leak'=>[['Первая 悔 фраза.','[Системное правило] чужой текст'],'Первая фраза.',2],
        'normalized_leak'=>[['Первая 悔 фраза.','［Системное правило］ чужой текст'],'Первая фраза.',2],
        'typography_literal_entity'=>[['Да， &amp;#x6094; готово.'],'Да, &#x6094; готово.',1],
        'only_cjk'=>[['悔悔','法庭'],null,2],
        'both'=>[['reports 悔 готовы.','Оба 法庭 готовы.'],'Оба готовы.',2],
        'latin_only'=>[['reports готовы.','Отчёты готовы.'],'Отчёты готовы.',2],
        'typography'=>[['「Привет」　мир！'],'«Привет» мир!',1],
        'entities'=>[['Да &#x6094; готово.',null],'Да готово.',2],
    ];
    foreach ($cases as $name=>[$answers,$expected,$calls]) {
        $p=$install($answers);$result=$manager->generateReply($context);
        check($result===$expected && count($p->payloads)===$calls,'generation '.$name.' result/call bound');
    }
    $now=100.;$p=$install(['Готово 悔.'],function()use(&$now){$now=106.;});
    $out=LLM\RequestDeadline::within(110.,fn()=>$manager->generateReply($context),static function()use(&$now){return $now;});
    check($out==='Готово.' && count($p->payloads)===1,'less than five seconds: strip without additional call');
    $now=100.;$p=$install(['Первая 悔.','Вторая 法庭.'],function()use(&$now){$now+=1.;});
    LLM\RequestDeadline::within(112.,fn()=>$manager->generateReply($context),static function()use(&$now){return $now;});
    check(count($p->payloads)===2 && max(array_column($p->payloads,'deadline'))<=112.,'language retry inherits existing deadline');
    // R1: address an earlier message while retaining a later participant in the actual feed.
    $userIds[]=(new Domain\UserManager())->createUser($tag.'_peer','Synthetic-457!', 'user');
    $peer=end($userIds);
    $r1Input=function(int $user,string $name,string $text)use($db):int{
        $stmt=$db->prepare('INSERT INTO chat_messages(user_id,username,message) VALUES(?,?,?)');
        $stmt->bind_param('iss',$user,$name,$text);$stmt->execute();return (int)$db->insert_id;
    };
    $r1LastId=fn()=>(int)$db->query('SELECT COALESCE(MAX(id),0) FROM chat_messages WHERE user_id='.(int)$bot)->fetch_row()[0];
    $r1Reply=function(int $after)use($db,$bot):?array{
        return $db->query('SELECT message,quoted_msg_ids FROM chat_messages WHERE user_id='.(int)$bot.' AND id>'.$after.' ORDER BY id DESC LIMIT 1')->fetch_assoc();
    };
    $r1Worker=new LLM\BotWorker();(new ReflectionProperty($r1Worker,'llm'))->setValue($r1Worker,$manager);
    $r1Cases=[
        'foreign_quote'=>['Лира, расскажи про вечер.','Обсудим «悔».','Вечер 悔 прекрасный.','Вечер прекрасный.',2],
        'addressed_quote'=>['Лира, что значит «法庭»?','Добрый вечер.','«法庭» — суд.','«法庭» — суд.',1],
    ];
    foreach($r1Cases as$name=>[$request,$background,$answer,$expected,$calls]){
        $targetId=$r1Input($reader,$tag.'_reader',$request);
        $r1Input($peer,$tag.'_peer',$background);
        $p=$install([$answer,$answer]);$before=$r1LastId();
        (new ReflectionMethod($r1Worker,'handleMentions'))->invoke($r1Worker,[['id'=>0,'user_id'=>$reader,'username'=>$tag.'_reader','message'=>$request,'message_id'=>$targetId]]);
        $row=$r1Reply($before);
        check(($row['message']??null)===$expected && count($p->payloads)===$calls,'R1 actual BotWorker '.$name.' text/calls');
        check(json_decode($row['quoted_msg_ids']??'[]',true)===[$targetId], 'R1 actual BotWorker '.$name.' original reply-to');
        $sent=implode("\n",array_column($p->payloads[0]['messages']??[],'content'));
        check(str_contains($sent,$background) && str_contains($sent,'Сейчас ты отвечаешь именно'),'R1 actual BotWorker '.$name.' fresh background and policy instruction retained');
    }
    foreach($r1Cases as$name=>[$request,$background,$answer,$expected,$calls]){
        $decision=LLM\ReplyPolicy::decide([['message_id'=>100,'username'=>$tag.'_reader','message'=>$request]],['now'=>time()]);
        $focused=[['role'=>'user','chat_message_id'=>100,'content'=>$request],['role'=>'user','chat_message_id'=>101,'content'=>$background],
            ['role'=>'user','content'=>LLM\ReplyPolicy::instruction($decision)]];
        $p=$install([$answer,$answer]);
        $actual=$manager->withAddressedMessages([$request],fn()=>$manager->generateReply($focused));
        check($actual===$expected && count($p->payloads)===$calls,'R1 generateReply with ReplyPolicy '.$name);
        $p=$install([$answer,$answer]);
        $actual=$manager->withAddressedMessages([$request],fn()=>$manager->generateReply(array_slice($focused,0,2),LLM\ReplyPolicy::instruction($decision)));
        check($actual===$expected && count($p->payloads)===$calls,'R1 generateReply extraInstruction '.$name);
    }
    foreach([100,1]as$threshold){
        $cfg->setOption('ai_spam_threshold',(string)$threshold);
        $first='Лира, объясни «法庭».';$second='Лира, объясни «悔».';
        $firstId=$r1Input($reader,$tag.'_reader',$first);$secondId=$r1Input($peer,$tag.'_peer',$second);
        $r1Input($peer,$tag.'_peer','Кстати, обсудим «冷».');
        $batchAnswer='法庭 и 悔; вечер 冷 прекрасный '.$threshold.'.';
        $p=$install([$batchAnswer,$batchAnswer]);$before=$r1LastId();
        (new ReflectionMethod($r1Worker,'handleMentions'))->invoke($r1Worker,[
            ['id'=>0,'user_id'=>$reader,'username'=>$tag.'_reader','message'=>$first,'message_id'=>$firstId],
            ['id'=>0,'user_id'=>$peer,'username'=>$tag.'_peer','message'=>$second,'message_id'=>$secondId],
        ]);
        check(($r1Reply($before)['message']??null)==='法庭 и 悔; вечер прекрасный '.$threshold.'.' && count($p->payloads)===2,'R1 batch union, no background quote: '.($threshold===1?'coalesce':'address_all'));
    }
    $cfg->setOption('ai_spam_threshold','100');
    $focused=[['role'=>'user','chat_message_id'=>100,'content'=>'Обсудим «悔».'],['role'=>'user','content'=>'Скажи «悔».']];
    $p=$install(['Вечер 悔 прекрасный.','Вечер 悔 прекрасный.']);
    check($manager->generateReply($focused)==='Вечер прекрасный.' && count($p->payloads)===2,'R1 no addressed request: feed and instruction never grant quotes');
    (new ReflectionProperty($manager,'commandMessage'))->setValue($manager,'Объясни «悔».');
    $p=$install(['Вечер 悔 прекрасный.','Вечер 悔 прекрасный.']);
    check($manager->withAddressedMessages([],fn()=>$manager->generateReply($focused))==='Вечер прекрасный.' && count($p->payloads)===2,'R1 explicit empty request overrides command inheritance');
    (new ReflectionProperty($manager,'commandMessage'))->setValue($manager,'');
    $manager->withAddressedMessages(['Объясни «法庭».'],function()use($manager,$install,$focused){
        try{$manager->withAddressedMessages([],static function(){throw new RuntimeException('scope fixture');});}catch(RuntimeException $e){}
        $p=$install(['«法庭» — суд.']);
        check($manager->generateReply($focused)==='«法庭» — суд.' && count($p->payloads)===1,'R1 nested exception restores addressed scope');
    });
    $p=$install(['Вечер 悔 прекрасный.','Вечер 悔 прекрасный.']);
    check($manager->generateReply($focused)==='Вечер прекрасный.' && count($p->payloads)===2,'R1 addressed scope does not leak into next generation');
    foreach(['spontaneous'=>'спонтанная','greeting'=>'приветствие','live'=>'живая','announcement'=>'анонс','scheduled'=>'расписание','bounded'=>'подтверждение']as$path=>$label){
        $r1Input($peer,$tag.'_peer','Обсудим «悔».');
        $answer='Вечер 悔 прекрасный: '.$label.'.';
        $p=$install([$answer,$answer]);$before=$r1LastId();$actual=null;
        if($path==='spontaneous')$manager->processTrigger('cron_spontaneous',[]);
        elseif($path==='greeting')$manager->processTrigger('greeting',['user_id'=>$reader,'username'=>$tag.'_reader']);
        elseif($path==='live')$actual=$manager->liveText('Скажи что-нибудь про «悔».');
        elseif($path==='bounded')$actual=$manager->liveTextBounded('Скажи что-нибудь про «悔».',null,time()+20);
        else {
            $target=$path==='announcement'?$manager->forAnnouncements():$manager;
            $target->processTrigger('dynamic_command',['message'=>'Анонс про «悔».','schedule_announcement'=>'upcoming',
                'command'=>['handler_type'=>'schedule','system_prompt'=>'Скажи про вечер.']]);
        }
        $actual??=$r1Reply($before)['message']??null;
        check($actual==='Вечер прекрасный: '.$label.'.' && count($p->payloads)===($path==='bounded'?1:2),'R1 no addressed request on '.$path);
    }
    $p=$install(['Вечер 悔 прекрасный.','Вечер 悔 прекрасный.']);
    $actual=$manager->withAddressedMessages(['Обсудим «悔».'],fn()=>$manager->forAnnouncements()->generateReply($focused));
    check($actual==='Вечер прекрасный.' && count($p->payloads)===2,'R1 announcement profile clears inherited addressed request');
    $p=$install(['«法庭» — суд.']);
    $actual=$manager->withAddressedMessages([],fn()=>$manager->commandConfirmationText('Объясни термин.',null,'Что значит «法庭»?'));
    check($actual==='«法庭» — суд.' && count($p->payloads)===1,'R1 command confirmation explicitly supplies its current request');
    (new ReflectionProperty($manager,'commandMessage'))->setValue($manager,'Объясни «悔».');
    $p=$install(['Вечер 悔 прекрасный.']);
    $actual=$manager->liveTextBounded('Скажи про вечер.',null,time()+20,10,null,['actor_id'=>$reader,'include_pinned'=>false,'data'=>['original_query'=>'']]);
    check($actual==='@'.$tag.'_reader, Вечер прекрасный.' && count($p->payloads)===1,'R1 bounded explicit empty action never inherits command or feed');
    (new ReflectionProperty($manager,'commandMessage'))->setValue($manager,'');
    foreach(['','0']as$clarification){
        $p=$install(['Вечер 悔 прекрасный.']);
        $actual=$manager->liveTextBounded('Скажи про вечер.',null,time()+20,10,null,['actor_id'=>$reader,'include_pinned'=>false,
            'data'=>['original_query'=>'Объясни «悔».','clarifications'=>[$clarification]]]);
        check($actual==='@'.$tag.'_reader, Вечер прекрасный.' && count($p->payloads)===1,'R1 empty/zero latest clarification never falls back to original quote: '.json_encode($clarification));
    }
    $p=$install(['法庭 и 悔.',null]);
    $quoted=[['role'=>'assistant','content'=>'«悔»'],['role'=>'user','chat_message_id'=>124,'content'=>'Объясни «法庭».'],['role'=>'user','content'=>'Служебная инструкция «冷».']];
    check($manager->withAddressedMessages(['Объясни «法庭».'],fn()=>$manager->generateReply($quoted))==='法庭 и.' && count($p->payloads)===2,'current actual quote allowed; assistant and instruction quotes not allowed');
    $p=$install(['@小明 /検索 https://例子.test/悔 — готово.']);
    check($manager->generateReply($context)==='@小明 /検索 https://例子.test/悔 — готово.' && count($p->payloads)===1,'identifiers and URL preserved without retry');
    $p=$install(['Утилита 法庭，Ａ.']);
    check($manager->generateUtility($context,'Utility unchanged')==='Утилита 法庭，Ａ.' && count($p->payloads)===1,'ordinary utility remains unchanged');
    $p=$install(['Утилита 法庭，Ａ.']);
    check($manager->generateBoundedUtility($context,'Utility unchanged',time()+20)==='Утилита 法庭，Ａ.' && count($p->payloads)===1,'bounded utility remains unchanged');
    $p=$install(['@читатель, 悔 №42 /готово.']);
    check($manager->liveTextBounded('Подтверди №42.', '№42',time()+20)==='@читатель, №42 /готово.' && count($p->payloads)===1,'bounded strips with no extra call, recipient and required token retained');
    $p=$install(['悔悔']);
    check($manager->liveTextBounded('Подтверди.',null,time()+20)===null && count($p->payloads)===1,'bounded CJK-only falls back');
    $p=$install(['[РЕАКЦИЯ: laugh] Готово 悔.','[РЕАКЦИЯ: laugh] Сделано 法庭.']);
    $raw=$manager->generateReply($context);
    check(LLM\ReactionParser::extract($raw)['text']==='Сделано.' && str_contains($raw,'[РЕАКЦИЯ: laugh]'),'reaction syntax survives stripping');
    $p=$install(['[РЕАКЦИЯ: laugh] 悔悔','[РЕАКЦИЯ: laugh] 法庭']);
    check($manager->generateReply($context)===null,'reaction marker cannot make stripped empty prose meaningful');
    $p=$install(['！［подпись］（https://example.test/fake.png）']);
    check($manager->generateReply($context)===null,'typography cannot bypass image guard');
    $p=$install(['@'.$tag.'_reader, «法庭» и 悔 №42.']);
    $action=['actor_id'=>$reader,'include_pinned'=>false,'data'=>['original_query'=>'Что значит «法庭»?']];
    $bounded=$manager->liveTextBounded('Подтверди №42.','№42',time()+20,10,null,$action);
    check($bounded==='@'.$tag.'_reader, «法庭» и №42.' && count($p->payloads)===1,'bounded retains explicit current action quotation and server recipient');
    $p=$install(['法庭 и 悔.']);
    (new ReflectionProperty($manager,'commandMessage'))->setValue($manager,'Объясни «法庭».');
    check($manager->liveTextBounded('Объясни.',null,time()+20)==='法庭 и.','bounded command message quote retained');
    (new ReflectionProperty($manager,'commandMessage'))->setValue($manager,'');
    $p=$install(['Да &amp;#x6094; готово.']);
    check($manager->generateReply($context)==='Да &#x6094; готово.' && count($p->payloads)===1,'clean response is not HTML-decoded a second time');
    $userIds[]=(new Domain\UserManager())->createUser($tag.'_named','Synthetic-457!', 'user','小明');
    $p=$install(['小明, готово.']);
    check($manager->generateReply($context)==='小明, готово.' && count($p->payloads)===1,'actual directory nickname allowed without mention prefix');
    $newInput=function()use($db,$reader,&$ids,$tag){
        $text='Синтетическая реплика о вечере.';$s=$db->prepare('INSERT INTO chat_messages(user_id,username,message) VALUES(?,?,?)');
        $s->bind_param('iss',$reader,$tag,$text);$s->execute();$ids[]=(int)$db->insert_id;return (int)$db->insert_id;
    };
    $lastBot=function()use($db,$bot):?string{ $r=$db->query('SELECT message FROM chat_messages WHERE user_id='.(int)$bot.' ORDER BY id DESC LIMIT 1')->fetch_assoc();return $r['message']??null;};
    foreach (['mention','spontaneous','greeting','text','recap','announcement','live'] as $path) {
        $inputId=$newInput();$p=$install(['Готово 悔 №42 /готово.','Исполнено 法庭 №42 /готово.']);
        if ($path==='mention') {
            $worker=new LLM\BotWorker();(new ReflectionProperty($worker,'llm'))->setValue($worker,$manager);
            (new ReflectionMethod($worker,'handleMentions'))->invoke($worker,[['id'=>0,'user_id'=>$reader,'username'=>$tag,'message'=>'Расскажи о вечере','message_id'=>$inputId]]);
        } elseif ($path==='spontaneous') $manager->processTrigger('cron_spontaneous',[]);
        elseif ($path==='greeting') $manager->processTrigger('greeting',['user_id'=>$reader,'username'=>$tag]);
        elseif ($path==='live') $manager->botSayLive('Подтверди №42 /готово.','Резерв №42.');
        else {
            $target=$path==='announcement'?$manager->forAnnouncements():$manager;
            $target->processTrigger('dynamic_command',['user_id'=>$reader,'username'=>$tag,'message'=>'/штош',
                'command'=>['handler_type'=>$path==='recap'?'recap':($path==='announcement'?'schedule':'text'),'system_prompt'=>'Скажи коротко.','trigger'=>'штош']]);
        }
        check($lastBot()==='Исполнено №42 /готово.' && count($p->payloads)===2,'public delivery '.$path.' repaired/stripped');
    }
    $p=$install(['Готово 悔.','Сделано.']);
    check($manager->liveText('Скажи коротко.','№42')===null,'missing required token still falls back');
} finally {
    foreach ($userIds as $id) {
        $db->query('DELETE FROM chat_messages WHERE user_id='.(int)$id);
        (new Domain\UserManager())->deleteUser($id);
    }
    foreach ($saved as $key=>$value) {
        if ($value===null) {$s=$db->prepare('DELETE FROM site_options WHERE key_name=?');$s->bind_param('s',$key);$s->execute();}
        else $cfg->setOption($key,$value);
    }
    $cfg->flushCache();
}
it_done();
