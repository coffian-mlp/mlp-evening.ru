<?php
namespace LLM {
    require_once __DIR__.'/../autoload.php';
    /** Substitute only the external transport; real description, prompt, debug and cache code runs. */
    class RouterAIProvider implements LLMProviderInterface {
        public static array $calls = [];
        public static string $answer = 'Синтетический объект перемещается по кадру.';
        public function __construct(...$args) {}
        public function withConfiguredReasoning(): self { return $this; }
        public function withTimeout(int $seconds): self { return $this; }
        public function askChat(array $messagesContext, string $systemPrompt): ?string {
            self::$calls[] = ['messages'=>$messagesContext,'system'=>$systemPrompt];
            return self::$answer;
        }
    }
}
namespace {
require_once __DIR__.'/integration_helpers.php';
require_once __DIR__.'/helpers/gif_fixture.php';
$db=it_require_db();
$cfg=Infra\ConfigManager::getInstance();
$saved=[]; $files=[];
$tag='mlp465_'.bin2hex(random_bytes(5));
$cacheRoot=sys_get_temp_dir().'/'.$tag;
$cache=new Core\FileCache('', $cacheRoot);
$options=['ai_vision_provider'=>'routerai','ai_vision_model'=>$tag,'ai_routerai_key'=>'synthetic-only-465',
    'ai_vision_prompt'=>'','ai_debug_log'=>1];
foreach ($options as $k=>$v) $saved[$k]=$cfg->getOptionDetails($k)['value']??null;
$hint='Это раскадровка анимации (GIF): 9 кадров по порядку слева направо, сверху вниз, номера в углах. Опиши, что происходит в движении целиком, а не каждый кадр по отдельности.';
try {
    foreach ($options as $k=>$v) $cfg->setOption($k,$v);
    $url='/upload/chat/'.$tag.'.gif';
    $file=dirname(__DIR__).$url; $files[]=$file;
    file_put_contents($file,gif_fixture(array_map(fn($i)=>['color'=>$i],range(1,12))));
    $cache->set(md5($url),['d'=>'OLD FIRST FRAME']);
    $cache->set(md5($url).'_fail',['fail'=>time()]);
    $out=LLM\VisionDescriber::describe($url,null,$cache);
    check($out==='Синтетический объект перемещается по кадру.','old success/failure cache not reused for storyboard');
    $call=end(LLM\RouterAIProvider::$calls);
    check($call && $call['system']===LLM\VisionDescriber::PROMPT."\n".$hint,'default prompt includes animation instruction');
    $image=$call['messages'][0]['content'][1]['image_url']['url']??'';
    $size=@getimagesizefromstring(base64_decode(substr($image,23)));
    check($size && $size[0]===144 && $size[1]===144,'describer sends storyboard JPEG');
    $before=count(LLM\RouterAIProvider::$calls);
    check(LLM\VisionDescriber::describe($url,null,$cache)===$out && count(LLM\RouterAIProvider::$calls)===$before,'new storyboard cache reused');
    check($cache->get(md5($url),604800)['d']==='OLD FIRST FRAME','legacy cache is preserved');

    $cfg->setOption('ai_vision_prompt','Configured synthetic prompt');
    LLM\VisionDescriber::describe($url,null,new Core\FileCache('configured',$cacheRoot));
    check(end(LLM\RouterAIProvider::$calls)['system']==='Configured synthetic prompt'."\n".$hint,'configured prompt includes animation instruction');
    LLM\VisionDescriber::describeWith($url,'Override synthetic prompt');
    check(end(LLM\RouterAIProvider::$calls)['system']==='Override synthetic prompt'."\n".$hint,'describeWith override includes animation instruction');
    $row=$db->query("SELECT request FROM llm_debug_log WHERE model='".$db->real_escape_string($tag)."' ORDER BY id DESC LIMIT 1")->fetch_assoc();
    check($row && str_contains($row['request'],$hint) && !str_contains($row['request'],'data:image/'),'debug log retains instruction, strips base64');

    $text754=str_repeat('я',753).'✓';
    LLM\RouterAIProvider::$answer=$text754;
    $lengthUrl=$url.'?length=754';
    $full=LLM\VisionDescriber::describe($lengthUrl,null,$cache);
    check($full===$text754,'storyboard preserves all 754 Unicode characters');
    check(($cache->get(md5($lengthUrl.'|gif-storyboard-v1'),604800)['d']??null)===$text754,'storyboard cache key unchanged and stores full 754 characters');
    $context=LLM\VisionDescriber::maybeDescribe([['role'=>'user','content'=>'![x]('.$lengthUrl.')']],null,$cache);
    check($context[0]['content']==='[Картинка: '.$text754.']','model context receives complete storyboard description');
    $director=LLM\LyraArtist::directorTask($context,'',[],'','synthetic framing');
    check(str_contains($director[0]['content']??'',$text754),'director transcript preserves complete storyboard description');
    LLM\RouterAIProvider::$answer=str_repeat('б',1199).'✓';
    $cut=LLM\VisionDescriber::describe($url.'?length=1200',null,$cache);
    check($cut===str_repeat('б',999).'…','1200 storyboard characters become exactly 1000 with ellipsis');
    LLM\RouterAIProvider::$answer=str_repeat('в',999).'✓';
    check(LLM\VisionDescriber::describe($url.'?length=1000',null,$cache)===LLM\RouterAIProvider::$answer,'exactly 1000 storyboard characters are not truncated');

    file_put_contents($file,gif_fixture([['color'=>1]]));
    LLM\VisionDescriber::describeWith($url,'Still prompt');
    check(end(LLM\RouterAIProvider::$calls)['system']==='Still prompt','single frame has no animation hint');
    LLM\RouterAIProvider::$answer=$text754;
    check(LLM\VisionDescriber::describe($url.'?single-length=754',null,$cache)===str_repeat('я',599).'…','single-frame GIF keeps 600-character limit');
    $pngUrl='/upload/chat/'.$tag.'.png'; $png=dirname(__DIR__).$pngUrl; $files[]=$png;
    $im=imagecreatetruecolor(48,48);imagepng($im,$png);
    LLM\VisionDescriber::describeWith($pngUrl,'PNG prompt');
    check(end(LLM\RouterAIProvider::$calls)['system']==='PNG prompt','PNG has no animation hint');
    check(LLM\VisionDescriber::describe($pngUrl,null,$cache)===str_repeat('я',599).'…','754 static-image characters become 600 with ellipsis');
    $privateUrl='/tests/'.$tag.'.gif';$privateFile=dirname(__DIR__).$privateUrl;$files[]=$privateFile;
    file_put_contents($privateFile,gif_fixture([['color'=>1],['color'=>2]]));
    $before=count(LLM\RouterAIProvider::$calls);
    check(!LLM\VisionFormatter::isLocalGif($privateUrl),'GIF classifier rejects closed directory');
    LLM\VisionDescriber::describeWith($privateUrl,'Private prompt');
    check(count(LLM\RouterAIProvider::$calls)===$before,'closed image is not sent to describeWith provider');
    $cache->set(md5($privateUrl),['d'=>'PRIVATE LEGACY']);
    check(LLM\VisionDescriber::describe($privateUrl,null,$cache)===null,'closed image cannot reuse an old description cache');
    $remote='https://example.invalid/'.$tag.'.gif';
    $cache->set(md5($remote),['d'=>'EXTERNAL LEGACY']);
    check(LLM\VisionDescriber::describe($remote,null,$cache)==='EXTERNAL LEGACY','external GIF keeps legacy cache key without fetching');
} finally {
    foreach ($saved as $k=>$v) {
        if ($v===null) { $stmt=$db->prepare('DELETE FROM site_options WHERE key_name=?');$stmt->bind_param('s',$k);$stmt->execute(); } else $cfg->setOption($k,$v);
    }
    $cfg->flushCache();
    $stmt=$db->prepare('DELETE FROM llm_debug_log WHERE model=?');$stmt->bind_param('s',$tag);$stmt->execute();
    foreach ($files as $file) if (is_file($file)) unlink($file);
    foreach (['','/configured'] as $sub) foreach (glob($cacheRoot.$sub.'/*.json')?:[] as $f) unlink($f);
    @rmdir($cacheRoot.'/configured');@rmdir($cacheRoot);
}
it_done();
}
