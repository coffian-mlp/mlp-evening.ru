<?php
/** MLP-458: actual reply retry boundary; synthetic provider, disposable DB only. */
require_once __DIR__ . '/integration_helpers.php';
$db = it_require_db();
$cfg = Infra\ConfigManager::getInstance();
$settings = ['ai_enabled'=>1,'ai_debug_log'=>0,'ai_memory_enabled'=>0,'ai_memory_recall_enabled'=>0,
    'ai_evening_summary_enabled'=>0,'ai_return_memory_enabled'=>0,'ai_system_prompt'=>'Синтетическая проверка.'];
$saved=[];
foreach ($settings as $key=>$value) $saved[$key]=$cfg->getOptionDetails($key)['value']??null;
class LatinTitleFixtureProvider implements LLM\LLMProviderInterface {
    public array $prompts=[];
    public function __construct(private string $first, private string $retry = 'Исправленный русский ответ.') {}
    public function askChat(array $messagesContext, string $systemPrompt): ?string {
        $this->prompts[]=$systemPrompt;
        return count($this->prompts)===1 ? $this->first : $this->retry;
    }
}
try {
    foreach ($settings as $key=>$value) $cfg->setOption($key,$value);
    $manager=new LLM\LLMManager();
    $ask=new ReflectionMethod($manager,'askWithFallback');
    $cases=[
        ['Ace Attorney — игра об адвокатах.',1],
        ['Friendship is Magic — название сериала.',1],
        ['The Return of Harmony — название серии.',1],
        ['Советую Minecraft для игры.',2,'Советую «Minecraft» для игры.'],
        ['Советую «Minecraft» для игры.',1],
        ['Привет, *Sniff-sniff* я здесь.',2],
        ['🙂 Random русский ответ.',2],
        [': Random русский ответ.',2],
        ['Советую «a strange title».',1],
        ['Кролик sniff-sniff — и подтвердил!',2],
        ['Слово reports здесь случайное.',2],
        ['Слово ban здесь случайное.',2],
        ['Random слово в начале.',2],
        ['Русский random в середине.',2],
        ['Советую «Minecraft», но minecraft здесь чужое.',2],
        ['Ace Attorney и случайная 漢字 вставка.',2],
    ];
    foreach ($cases as $case) {
        [$text,$calls]=$case; $retry=$case[2]??'Исправленный русский ответ.';
        $provider=new LatinTitleFixtureProvider($text,$retry);
        (new ReflectionProperty($manager,'providers'))->setValue($manager,[$provider]);
        $out=$ask->invoke($manager,[['role'=>'user','content'=>'Расскажи про вечер.']], 'Ответь по-русски.');
        check(count($provider->prompts)===$calls,'reply attempts');
        check($out===($calls===1?$text:$retry),'final text');
        if($calls===2) {
            check(str_contains($provider->prompts[1],'[Язык]:'),'one language retry');
            check(str_contains($provider->prompts[1],'названия')&&str_contains($provider->prompts[1],'«…»'),'retry permits quoted original titles');
        }
    }
    $provider=new LatinTitleFixtureProvider('Random utility unchanged.');
    (new ReflectionProperty($manager,'providers'))->setValue($manager,[$provider]);
    check($ask->invoke($manager,[], 'Служебный запрос.','utility')==='Random utility unchanged.'&&count($provider->prompts)===1,'utility bypass unchanged');
    check(str_contains(LLM\LLMManager::LANG_REMINDER,'названия')&&str_contains(LLM\LLMManager::LANG_REMINDER,'«…»'),'chat reminder permits quoted originals');
} finally {
    foreach ($saved as $key=>$value) {
        if($value!==null)$cfg->setOption($key,$value);else{$stmt=$db->prepare('DELETE FROM site_options WHERE key_name=?');$stmt->bind_param('s',$key);$stmt->execute();}
    }
}
it_done();
