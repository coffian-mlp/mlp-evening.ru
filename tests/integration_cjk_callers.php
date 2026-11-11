<?php
namespace LLM {
    /** External vision dependency only; text generation and handlers remain real. */
    class VisionDescriber { public static function describe($url): ?string { return 'Синтетический рисунок: пони читает книгу.'; } }
}
namespace {
require_once __DIR__.'/integration_helpers.php';
$db=it_require_db();$cfg=Infra\ConfigManager::getInstance();$users=[];$saved=[];$pollIds=[];
$settings=['ai_enabled'=>1,'ai_live_confirm'=>1,'ai_debug_log'=>0,'ai_memory_enabled'=>0,'ai_memory_recall_enabled'=>0,'ai_online_in_context'=>0,
    'ai_reactions'=>0,'ai_system_prompt'=>'Синтетическая проверка источника команд. caption excuse','ai_additional_instructions'=>'','ai_aliases'=>'лира',
    'stream_command_enabled'=>1,'stream_command_cooldown'=>120,'stream_command_last_refusal'=>0,'ai_reply_min_gap'=>0,
    'ai_image_llm_caption'=>1,'ai_image_daily_limit'=>0,'bot_last_manual_draw'=>0,'bot_last_autodraw'=>0];
foreach(array_merge(array_keys($settings),['ai_bot_user_id','stream_command_owner_id'])as$key)$saved[$key]=$cfg->getOptionDetails($key)['value']??null;
$imageCount=LLM\ImageGenerator::todayCount();
class CjkCallerProvider implements LLM\LLMProviderInterface {
    public array $calls=[];
    public function __construct(private array $answers){}
    public function withTimeout(int $timeout):self{return $this;}
    public function askChat(array $messagesContext,string $systemPrompt):?string{
        $index=count($this->calls);$this->calls[]=['messages'=>$messagesContext,'system'=>$systemPrompt];
        $value=$this->answers[$index]??null;if($value instanceof Throwable)throw $value;return $value;
    }
}
try {
    $tag='callers457_'.bin2hex(random_bytes(4));$um=new Domain\UserManager();
    foreach(['owner','peer','bot']as$role)$users[]=$um->createUser($tag.'_'.$role,'Synthetic-Only-457!','user');
    [$owner,$peer,$bot]=$users;$ownerName=$tag.'_owner';$peerName=$tag.'_peer';
    $settings['ai_bot_user_id']=$bot;$settings['stream_command_owner_id']=$owner;
    foreach($settings as$key=>$value)$cfg->setOption($key,$value);
    $llm=new LLM\LLMManager();
    $install=function(array $answers,?LLM\LLMManager $target=null)use($llm):CjkCallerProvider{
        $target??=$llm;$p=new CjkCallerProvider($answers);
        foreach(['providers','fastProviders']as$key)(new ReflectionProperty(LLM\LLMManager::class,$key))->setValue($target,[$p]);
        (new ReflectionProperty(LLM\LLMManager::class,'vlessLink'))->setValue($target,null);return $p;
    };
    $input=function(int $user,string $name,string $text)use($db):int{
        $s=$db->prepare('INSERT INTO chat_messages(user_id,username,message) VALUES(?,?,?)');$s->bind_param('iss',$user,$name,$text);$s->execute();return (int)$db->insert_id;
    };
    $lastId=fn()=>(int)$db->query('SELECT COALESCE(MAX(id),0) FROM chat_messages WHERE user_id='.(int)$bot)->fetch_row()[0];
    $reply=function(int $after)use($db,$bot):?string{return $db->query('SELECT message FROM chat_messages WHERE user_id='.(int)$bot.' AND id>'.$after.' ORDER BY id DESC LIMIT 1')->fetch_assoc()['message']??null;};
    // Inject only construction in StreamCommand's private manager, never bypass its generation/owner gates.
    $GLOBALS['cjk_caller_manager']=$llm;
    $source=file_get_contents(dirname(__DIR__).'/src/LLM/StreamCommand.php');
    $source=str_replace('$llm = new LLMManager();','$llm = $GLOBALS["cjk_caller_manager"];',$source,$count);
    if($count!==1)throw new RuntimeException('StreamCommand constructor seam mismatch');eval(substr($source,5));
    foreach(['own','foreign']as$kind){
        $request='Лира, включи фильм «法庭»';$id=$input($owner,$ownerName,$request);$input($peer,$peerName,'Обсудим «悔».');
        $answer=$kind==='own'?'Включаю фильм «法庭».':'Поток: «法庭» и 悔.';
        $expected=$kind==='own'?$answer:'Поток: «法庭» и.';$p=$install([$answer,$answer]);$before=$lastId();
        (new LLM\StreamCommand())->handle(['user_id'=>$owner,'username'=>$ownerName,'message'=>$request,'message_id'=>$id]);
        check($reply($before)===$expected && count($p->calls)===($kind==='own'?1:2),'R2 real StreamCommand owner '.$kind);
        check(LLM\StreamCommand::wants($request),'R2 command dispatcher recognizes owner request');
    }
    $p=$install(['Перерыв: «法庭».','Перерыв: «法庭».']);$before=$lastId();
    $llm->withAddressedMessages(['«法庭»'],fn()=>(new LLM\StreamCommand())->handle(['event'=>'episode_ended','message'=>'«法庭»']));
    check($reply($before)==='Перерыв: «».' && count($p->calls)===2,'R2 auto stream event has empty source even inside another request');
    $id=$input($peer,$peerName,'Лира, включи «法庭».');$cfg->setOption('stream_command_last_refusal','0');
    $p=$install(['Чужая команда: «法庭».','Чужая команда: «法庭».']);$before=$lastId();
    $llm->withAddressedMessages(['«法庭»'],fn()=>(new LLM\StreamCommand())->handle(['user_id'=>$peer,'username'=>$peerName,'message'=>'Лира, включи «法庭».','message_id'=>$id]));
    check($reply($before)==='Чужая команда: «».' && count($p->calls)===2,'R2 non-owner refusal cannot grant its command quote');
    foreach(['own','foreign']as$kind){
        $request='/бан @missing_'.$tag.' причина «法庭»';$input($owner,$ownerName,$request);$input($peer,$peerName,'Обсудим «悔».');
        $answer='@'.$ownerName.', модерация «法庭»'.($kind==='foreign'?' и 悔':'').'.';
        $expected='@'.$ownerName.', модерация «法庭»'.($kind==='foreign'?' и':'').'.';$p=$install([$answer,$answer]);$before=$lastId();
        (new LLM\ModerationCommand($llm))->handle(['handler_type'=>'ban','command_prefix'=>'/бан'],['user_id'=>$owner,'username'=>$ownerName,'moderator_role'=>'moderator','message'=>$request]);
        check($reply($before)===$expected && count($p->calls)===($kind==='own'?1:2),'R2 moderation handler '.$kind);
    }
    foreach(['own','foreign']as$kind){
        $id=$input($owner,$ownerName,'/плейлист «法庭»');$input($peer,$peerName,'Обсудим «悔».');
        $answer='@'.$ownerName.', плейлист «法庭»'.($kind==='foreign'?' и 悔':'').'.';
        $expected='@'.$ownerName.', плейлист «法庭»'.($kind==='foreign'?' и':'').'.';$p=$install([$answer]);$before=$lastId();
        (new ReflectionMethod(LLM\PlaylistCommand::class,'say'))->invoke(new LLM\PlaylistCommand($llm),['status'=>'cancelled','code'=>'choice_cancelled','facts'=>[]],$id,time()+30);
        check(str_starts_with($reply($before)??'',$expected) && count($p->calls)===1,'R2 playlist stored source and real bounded formatter '.$kind);
    }
    foreach(['own','foreign']as$kind){
        $action=['actor_id'=>$owner,'include_pinned'=>false,'data'=>['original_query'=>'Нарисуй «法庭».','clarifications'=>[]]];
        $answer='@'.$ownerName.', черновик «法庭»'.($kind==='foreign'?' и 悔':'').'.';$expected='@'.$ownerName.', черновик «法庭»'.($kind==='foreign'?' и':'').'.';
        $p=$install([$answer]);$actual=LLM\DrawingCommand::liveReply($llm,'Сообщи состояние черновика.','Резерв.',time()+20,$action);
        check($actual===$expected && count($p->calls)===1,'R2 drawing continuation action source '.$kind);
    }
    $artist=new LLM\LyraArtist($llm);
    foreach(['caption','excuse']as$branch)foreach(['own','foreign']as$kind){
        $request='/нарисуй «法庭»';$input($owner,$ownerName,$request);$input($peer,$peerName,'Обсудим «悔».');
        $answer='@'.$ownerName.', '.$branch.' «法庭»'.($kind==='foreign'?' и 悔':'').'.';$expected='@'.$ownerName.', '.$branch.' «法庭»'.($kind==='foreign'?' и':'').'.';
        $p=$install([$answer,$answer]);$before=$lastId();
        $artist->handleDraw(['handler_type'=>'image','command_prefix'=>'/нарисуй','system_prompt'=>'Синтетический стиль'],
            ['user_id'=>$owner,'username'=>$ownerName,'message'=>$request],$branch==='caption'?static fn()=>'/upload/lyra/cjk-caller-fixture.jpg':static fn()=>null);
        check(str_starts_with($reply($before)??'',$expected) && count($p->calls)===($kind==='own'?1:2),'R2 artist public '.$branch.' '.$kind);
    }
    $cfg->setOption('bot_last_manual_draw','0');$cfg->setOption('bot_last_autodraw','0');
    for($i=0;$i<12;$i++)$input($owner,$ownerName,'Synthetic spacing message '.$i);
    $p=$install(['Автоматический рисунок «法庭».','Автоматический рисунок «法庭».']);$before=$lastId();
    $llm->withAddressedMessages(['«法庭»'],fn()=>$artist->handleDrawChat(['handler_type'=>'image_chat'],['auto'=>true],static fn()=>'A pony reads a book.',static fn()=>'/upload/lyra/cjk-auto-fixture.jpg'));
    check(str_starts_with($reply($before)??'','Автоматический рисунок «».') && count($p->calls)===2,'R2 automatic artist never inherits the surrounding request');
    $p=$install(['Просмотр засчитан, «法庭».','Просмотр засчитан, «法庭».']);$before=$lastId();
    $llm->withAddressedMessages(['«法庭»'],fn()=>LLM\ScheduleAnnouncement::publish($llm,[['titles'=>['Синтетическая серия']]],'2026-11-08 00:00:00','Синтетическое расписание.'));
    check(str_starts_with($reply($before)??'','Просмотр засчитан, «».') && count($p->calls)===2,'R2 schedule completion notice has empty source');
    $p=$install(['@'.$peerName.', облик «法庭».','@'.$peerName.', облик «法庭».']);$before=$lastId();
    $llm->withAddressedMessages(['«法庭»'],fn()=>(new LLM\LyraOc($llm))->announce($peerName,LLM\LyraOc::PREFIX.' синтетический облик'));
    check($reply($before)==='@'.$peerName.', облик «».' && count($p->calls)===2,'R2 automatic appearance notice has empty source');
    $pm=new Domain\PollManager();$pollIds[]=$pollId=$pm->create($owner,'Синтетический опрос '.$tag,['Да','Нет']);
    $poll=$pm->getPoll($pollId);$p=$install(["1\nГолосую «法庭».","1\nГолосую «法庭»."]);$before=$lastId();
    $llm->withAddressedMessages(['«法庭»'],fn()=>$llm->voteOnPoll($poll));
    check($reply($before)==='Голосую «».' && count($p->calls)===2,'R2 automatic poll participation has empty source');
    $telegramCalls=[];
    $client=new Social\TelegramBotClient('123456:'.str_repeat('x',24),function($method,$params)use(&$telegramCalls){
        $telegramCalls[]=[$method,$params];return ['status'=>200,'body'=>json_encode(['ok'=>true,'result'=>['message_id'=>1]])];
    });
    $editor=new LLM\AnnouncementWorker(new Domain\AnnouncementStore(),$client,new LLM\AnnouncementContent($llm),$owner,'@synthetic457',0,1);
    $p=$install(['Статус «法庭» обновлён.']);
    $editor->respondIncoming(['message'=>['from'=>['id'=>$owner],'chat'=>['id'=>$owner,'type'=>'private'],'text'=>'Правь «法庭».']],'Статус обновлён.');
    check(end($telegramCalls)[1]['text']==='Статус «法庭» обновлён.' && count($p->calls)===1,'R2 private editor reply receives the current owner text');
    $p=$install(['Автостатус «法庭».']);
    $llm->withAddressedMessages(['«法庭»'],fn()=>(new ReflectionMethod($editor,'notify'))->invoke($editor,'Автостатус.'));
    check(end($telegramCalls)[1]['text']==='Автостатус «».' && count($p->calls)===1,'R2 private automatic notification explicitly clears process source');
    $p=$install(['Кнопка «法庭».']);
    $editor->respondIncoming(['callback_query'=>['id'=>'synthetic','from'=>['id'=>$owner],'message'=>['text'=>'«法庭»','chat'=>['id'=>$owner,'type'=>'private']]]],'Кнопка принята.');
    check(end($telegramCalls)[1]['text']==='Кнопка «».' && count($p->calls)===1,'R2 private button callback cannot borrow text from its bot message');
    $llm->withAddressedMessages(['Объясни «法庭».'],function()use($install){
        $nested=new LLM\LLMManager();$p=$install(['«法庭» — суд.','«法庭» — суд.'],$nested);
        check($nested->generateReply([])==='«法庭» — суд.' && count($p->calls)===1,'R2 explicit process source reaches a newly constructed manager');
        try{$nested->withAddressedMessages([],static function(){throw new RuntimeException('nested scope fixture');});}catch(RuntimeException $error){}
        $another=new LLM\LLMManager();$p=$install(['«法庭» — суд.','«法庭» — суд.'],$another);
        check($another->generateReply([])==='«法庭» — суд.' && count($p->calls)===1,'R2 process source restored after nested exception');
    });
    try{$llm->withAddressedMessages(['«悔»'],static function(){throw new RuntimeException('outer scope fixture');});}catch(RuntimeException $error){}
    $fresh=new LLM\LLMManager();$p=$install(['Следующий 悔 ответ.','Следующий 悔 ответ.'],$fresh);
    check($fresh->generateReply([])==='Следующий ответ.' && count($p->calls)===2,'R2 process source never leaks after return or error');
} finally {
    foreach($pollIds as$id){foreach(['poll_votes','poll_options','polls']as$table)$db->query('DELETE FROM '.$table.' WHERE '.($table==='polls'?'id':'poll_id').'='.(int)$id);}
    foreach($users as$id){$db->query('DELETE FROM chat_messages WHERE user_id='.(int)$id);(new Domain\UserManager())->deleteUser($id);}
    foreach($saved as$key=>$value){if($value===null){$s=$db->prepare('DELETE FROM site_options WHERE key_name=?');$s->bind_param('s',$key);$s->execute();}else$cfg->setOption($key,$value);}$cfg->flushCache();
    (new Core\FileCache('imagegen'))->set(gmdate('Y-m-d'),['count'=>$imageCount]);unset($GLOBALS['cjk_caller_manager']);
}
it_done();
}
