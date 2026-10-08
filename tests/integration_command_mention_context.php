<?php
require_once __DIR__ . '/integration_helpers.php';
if ((it_config()['db']['host'] ?? '') !== 'db') it_skip('isolated db required');
$probe = it_require_db(); $probe->close();
$db = Infra\Database::getInstance()->getConnection();
class MentionFixtureLLM extends LLM\LLMManager {
    public array $normalization = [];
    public array $verification = [];
    public function generateSearchQueryUtility(array $context, string $prompt, int $deadlineSec, int $timeoutSec = 8): ?string {
        $this->normalization = json_decode($context[0]['content'],true);
        return '{"version":2,"intent":"plot","search_query":"Twilight Sparkle lesson friendship anxiety"}';
    }
    public function generateSearchUtility(array $context, string $prompt, ?int $deadlineSec = null): ?string {
        return json_encode(['content'=>json_encode(['candidates'=>[['episode_code'=>'S02E03','title'=>'Lesson Zero','evidence'=>'Twilight worries about her friendship report','source_url'=>'https://example.org/lesson']]]),'sources'=>[['url'=>'https://example.org/lesson']]]);
    }
    public function generateBoundedUtility(array $context, string $prompt, int $deadlineSec, int $timeoutSec = 20): ?string {
        $this->verification = json_decode($context[0]['content'],true);
        return json_encode(['verified'=>[$GLOBALS['mention_episode_id']]]);
    }
}
$db->begin_transaction();
try {
    $run = bin2hex(random_bytes(5));
    $db->query("INSERT INTO users(login,nickname,password_hash,role) VALUES('mention_author_$run','Автор','fixture','user'),('mention_target_$run','Пшеница_$run','fixture','user'),('mention_other_$run','Другой','fixture','user')");
    $ids = array_column($db->query("SELECT id,login FROM users WHERE login LIKE '%$run'")->fetch_all(MYSQLI_ASSOC),'id','login');
    $actor = (int)$ids['mention_author_'.$run]; $target = (int)$ids['mention_target_'.$run]; $other = (int)$ids['mention_other_'.$run];
    $bm = new Domain\BotMemoryManager();
    $bm->add('dossier',$actor,'AUTHOR_PRIVATE_'.$run,'manual',$actor);
    $bm->add('dossier',$target,'TARGET_INTEREST_'.$run.' Любит тревожную Твайлайт и истории дружбы.','manual',$actor);
    $bm->add('dossier',$other,'OTHER_PRIVATE_'.$run,'manual',$actor);
    $config = Infra\ConfigManager::getInstance();
    $config->setOption('ai_memory_enabled','1'); $config->setOption('ai_memory_block_limit','2400');
    $config->setOption('ai_memory_user_limit','400'); $config->setOption('ai_online_in_context','0');
    $llm = new MentionFixtureLLM();
    $query = 'серию для @mention_target_'.$run;
    $ctx = $llm->commandMentionContext($query);
    check(array_column($ctx['users'],'id') === [$target], 'target resolved independently of author');
    check(str_contains($ctx['memory'],'TARGET_INTEREST_'.$run) && !str_contains($ctx['memory'],'AUTHOR_PRIVATE_') && !str_contains($ctx['memory'],'OTHER_PRIVATE_') && !str_contains($ctx['memory'],'Мем чата:'), 'only explicit target dossiers, no author/other/global memes');
    $db->query("INSERT INTO episode_list(TITLE,TIMES_WATCHED,WANNA_WATCH) VALUES('My Little Pony Friendship is Magic - Season 2 Episode 3 - Lesson Zero',0,0)");
    $GLOBALS['mention_episode_id'] = (int)$db->insert_id;
    $result = (new LLM\EpisodeResolver($llm))->resolve($query,[['ID'=>$GLOBALS['mention_episode_id'],'TITLE'=>'My Little Pony Friendship is Magic - Season 2 Episode 3 - Lesson Zero']],time()+55,false);
    check($result['status']==='found', 'personalized episode goes through normalizer, sources and independent verifier');
    check($llm->normalization['original_query']===$query && str_contains($llm->normalization['mentioned_users']['memory'],'TARGET_INTEREST_'), 'normalizer receives original plus personal preference data');
    check($llm->verification['original_query']===$query && $llm->verification['mentioned_users']['users'][0]['id']===$target, 'verification preserves original and subject identity');
    $method = new ReflectionMethod(LLM\LLMManager::class,'buildActionContext');
    $action = $method->invoke($llm,['actor_id'=>$actor,'include_pinned'=>false,'data'=>['original_query'=>$query,'clarifications'=>[]]]);
    $joined = implode('\n',array_column($action,'content'));
    check(str_contains($joined,'TARGET_INTEREST_') && str_contains($joined,'AUTHOR_PRIVATE_') && !str_contains($joined,'OTHER_PRIVATE_'), 'action background has recipient and explicit subject, unrelated dossier excluded');
    $recipientData = json_decode(substr(end($action)['content'],strpos(end($action)['content'],'{')),true);
    check($recipientData['recipient']['id']===$actor, 'personalization never transfers command actor');
    check($llm->addressActionReply('Ответ @mention_target_'.$run,$actor)===null, 'reply recipient restriction survives target context');
    $config->setOption('ai_memory_enabled','0');
    check($llm->commandMentionContext($query)['memory']===null, 'disabled memory remains disabled with explicit mention');
    $config->setOption('ai_enabled','0');
    $llm->processTrigger('dynamic_command',['command'=>['handler_type'=>'text'],'message'=>$query]);
    $property=new ReflectionProperty(LLM\LLMManager::class,'commandMessage');
    check($property->getValue($llm)==='', 'worker command scope restored after trigger');
} finally { $db->rollback(); }
it_done();
