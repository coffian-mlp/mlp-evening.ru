<?php
namespace LLM {
    function curl_init($url){return(object)['options'=>[],'response'=>[]];}
    function curl_setopt_array($ch,$options){$ch->options=$options;return true;}
    function curl_setopt($ch,$key,$value){$ch->options[$key]=$value;return true;}
    function curl_exec($ch){$ch->response=array_shift($GLOBALS['announcement_image_responses']);$GLOBALS['announcement_image_attempts']++;return$ch->response['body']??'';}
    function curl_getinfo($ch,$key){return$ch->response['code']??0;}
    function curl_errno($ch){return 0;}
    function curl_error($ch){return '';}
    function curl_close($ch){}
}
namespace {
require __DIR__.'/integration_helpers.php';it_require_db();
$cfg=Infra\ConfigManager::getInstance();$backup=[];$cache=new Core\FileCache('imagegen');$day=gmdate('Y-m-d');$counter=$cache->get($day,172800);$files=[];
try{
    foreach(['ai_image_provider'=>'routerai','ai_routerai_key'=>'synthetic','ai_image_daily_limit'=>'0','ai_debug_log'=>'0']as$key=>$value){$backup[$key]=$cfg->getOption($key,null);$cfg->setOption($key,$value);}
    $image=imagecreatetruecolor(128,128);for($x=0;$x<128;$x++)for($y=0;$y<128;$y++)imagesetpixel($image,$x,$y,imagecolorallocate($image,($x*7)%255,($y*9)%255,($x+$y)%255));
    ob_start();imagepng($image);$bytes=ob_get_clean();imagedestroy($image);
    $content=new class extends LLM\AnnouncementContent{protected function imagePrompt(array $facts,string $kind,string $caption,string $feedback=''):string{return'Synthetic announcement image';}};
    $GLOBALS['announcement_image_attempts']=0;$GLOBALS['announcement_image_responses']=[['code'=>429],['code'=>200,'body'=>json_encode(['data'=>[['b64_json'=>base64_encode($bytes)]]])]];
    $usage=[];$before=LLM\ImageGenerator::todayCount();
    $url=Domain\LlmUsage::withoutDatabase(fn()=>$content->generateImage([],'main','Синтетический анонс'),static function($row)use(&$usage):void{$usage[]=$row;});
    if($url)$files[]=$url;
    check($url!==null&&$GLOBALS['announcement_image_attempts']===2,'native announcement generation uses the shared retry transport');
    check(LLM\ImageGenerator::todayCount()===$before+1&&count($usage)===2,'announcement accounts one image and both real HTTP attempts');
    $GLOBALS['announcement_image_attempts']=0;$GLOBALS['announcement_image_responses']=array_fill(0,3,['code'=>503]);$usage=[];
    $url=Domain\LlmUsage::withoutDatabase(fn()=>$content->generateImage([],'reminder','Синтетическое напоминание'),static function($row)use(&$usage):void{$usage[]=$row;});
    check($url===null&&$GLOBALS['announcement_image_attempts']===3&&$content->lastError()==='image_unavailable','failed announcement stops after three transient attempts with the existing phase error');
    check(LLM\ImageGenerator::todayCount()===$before+1&&count($usage)===3,'failed announcement attempts never increment successful image count');
}finally{
    foreach($files as$file)@unlink(dirname(__DIR__).$file);
    if($counter===null)$cache->delete($day);else$cache->set($day,$counter);
    foreach($backup as$key=>$value){if($value!==null)$cfg->setOption($key,(string)$value);else{$q=Infra\Database::getInstance()->getConnection()->prepare('DELETE FROM site_options WHERE key_name=?');$q->bind_param('s',$key);$q->execute();}}
}
it_done();
}
