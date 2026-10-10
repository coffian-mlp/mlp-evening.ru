<?php
/** Actual storage-to-prompt and Markdown-to-identity regression, isolated DB only. */
require_once __DIR__.'/integration_helpers.php';
it_require_db();
$db=\Infra\Database::getInstance()->getConnection();
$config=\Infra\ConfigManager::getInstance();
$marker='it_topic_'.bin2hex(random_bytes(5));
$db->begin_transaction();
try {
    foreach (['ai_memory_enabled'=>'1','ai_memory_meme_limit'=>'200','ai_memory_block_limit'=>'2400'] as $key=>$value) $config->setOption($key,$value);
    $legend=$marker.' Пат-патокалипсис: Бон-Бон спасает всех арбалетом.';
    $q=$db->prepare("INSERT INTO bot_memory(kind,user_id,source,text) VALUES ('meme',NULL,'auto',?)");$q->bind_param('s',$legend);$q->execute();$oldId=(int)$db->insert_id;
    for($i=0;$i<105;$i++) {$text=$marker.' свежая заметка о чае '.$i;$q->bind_param('s',$text);$q->execute();}
    $llm=new \LLM\LLMManager();
    $memory=new \LLM\LyraMemory($llm);
    $block=$memory->buildPromptBlock([],true,$marker.' Бон-Бон с арбалета');
    check(str_contains($block??'',$legend),'actual stored old legend beyond 100 rows reaches focused prompt');
    $context=$llm->buildReplyContext(4,null,null,false,true,$marker.' Бон-Бон с арбалета');
    check(str_contains(json_encode($context,JSON_UNESCAPED_UNICODE),$legend),'explicit question flows through reply context into memory selection');
    $empty=$llm->buildReplyContext(4,null,null,false,false,$marker.' Бон-Бон с арбалета');
    check(!str_contains(json_encode($empty,JSON_UNESCAPED_UNICODE),$legend),'disabled memory path remains disabled');
    $stored=$db->query('SELECT text FROM bot_memory WHERE id='.$oldId)->fetch_assoc()['text'];
    check($stored===$legend,'retrieval does not rewrite original legend');
    $nick=$marker.' Two Words';$login=$marker.'_actor';
    $uid=(new \Domain\UserManager())->createUser($login,'fixture-only','user',$nick);
    $parse=new ReflectionMethod(\Domain\ChatManager::class,'parseMarkdown');
    $html=$parse->invoke(new \Domain\ChatManager(),'@'.$nick.', привет! `@'.$nick.'`');
    check(substr_count($html,'data-mention-user-id="'.$uid.'"')===1,'actual Markdown resolves full known nickname and protects code');
    $subjects=$llm->commandMentionContext('/нарисуй @'.$nick);
    check(array_column($subjects['users'],'id')===[$uid],'actual command resolves full stored nickname');
} finally {
    $db->rollback();$config->flushCache();
}
it_done();
