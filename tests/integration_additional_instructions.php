<?php
require_once __DIR__ . '/integration_helpers.php';
$db = it_require_db();
$cfg = Infra\ConfigManager::getInstance();
$keys = ['ai_system_prompt','ai_additional_instructions','ai_live_confirm','ai_memory_enabled','ai_enabled'];
$saved = [];
foreach ($keys as $key) {
    $s=$db->prepare('SELECT value FROM site_options WHERE key_name=?');$s->bind_param('s',$key);$s->execute();
    $saved[$key]=$s->get_result()->fetch_assoc();
}
try {
    $cfg->setOption('ai_system_prompt','Base persona MLP453');
    $cfg->setOption('ai_live_confirm','1');
    $cfg->setOption('ai_enabled','1');
    $cfg->setOption('ai_memory_enabled','0');
    class Mlp453Provider implements LLM\LLMProviderInterface {
        public string $prompt = '';
        public function withTimeout(int $seconds): self { return $this; }
        public function askChat(array $messagesContext,string $systemPrompt): ?string {
            $this->prompt=$systemPrompt;
            return 'Я готова смотреть вместе!';
        }
    }
    $fake = new Mlp453Provider();
    foreach (['','  Ночь кошмаров: редкие шутки про руки.\nСохраняй полезность.  '] as $extra) {
        $cfg->setOption('ai_additional_instructions',$extra);
        $llm=new LLM\LLMManager();
        (new ReflectionProperty($llm,'botUserId'))->setValue($llm,1);
        (new ReflectionProperty($llm,'vlessLink'))->setValue($llm,null);
        foreach (['providers','fastProviders'] as $property) (new ReflectionProperty($llm,$property))->setValue($llm,[$fake]);
        $llm->liveTextBounded('Позови смотреть вместе.',null,time()+15,10);
        check(str_contains($fake->prompt,'Base persona MLP453'),'Основная личность сохранена');
        check($extra==='' ? !str_contains($fake->prompt,'[Дополнительные инструкции Лире]') : str_contains($fake->prompt,trim($extra)),'Дополнение включается только при непустом значении');
        if ($extra!=='') check(strpos($fake->prompt,'Base persona MLP453')<strpos($fake->prompt,trim($extra)),'Дополнение следует после основной личности');
        $llm->generateBoundedUtility([],'Utility task only',time()+15,10);
        check($fake->prompt==='Utility task only','Служебный запрос изолирован от личности и дополнения');
        check($cfg->getOption('ai_system_prompt')==='Base persona MLP453','Основной промпт в настройках не изменён');
    }
} finally {
    foreach ($saved as $key=>$row) {
        if ($row!==null) $cfg->setOption($key,$row['value']);
        else {$s=$db->prepare('DELETE FROM site_options WHERE key_name=?');$s->bind_param('s',$key);$s->execute();}
    }
    $cfg->flushCache();
}
it_done();
