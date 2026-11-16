<?php
namespace {
    if (!is_file(dirname(__DIR__,2).'/.mlp468-owned') || !is_file('/.dockerenv')) throw new \RuntimeException('Synthetic drawing transport requires the owned Docker laboratory');
}
namespace LLM {
    function curl_init($url) { return (object)['url'=>$url,'options'=>[],'code'=>200,'errno'=>0,'error'=>'']; }
    function curl_setopt($ch,$key,$value) { $ch->options[$key]=$value; return true; }
    function curl_setopt_array($ch,$options) { $ch->options=$options+$ch->options; return true; }
    function curl_getinfo($ch,$key) { return $key===CURLINFO_HTTP_CODE ? $ch->code : 0.001; }
    function curl_errno($ch) { return $ch->errno; }
    function curl_error($ch) { return $ch->error; }
    function curl_close($ch) {}
    function curl_exec($ch) {
        $dir=dirname(__DIR__,2).'/cache/mlp468';
        $state=json_decode(file_get_contents($dir.'/state.json'),true,512,JSON_THROW_ON_ERROR);
        $payload=$ch->options[CURLOPT_POSTFIELDS] ?? [];
        if (is_string($payload)) $payload=json_decode($payload,true);
        $image=str_contains($ch->url,'/images') || in_array('image',$payload['modalities'] ?? [],true);
        $trace=['kind'=>$image?'image':'text','pid'=>getmypid(),'time'=>microtime(true)];
        if(class_exists(DrawingJob::class)&&DrawingJob::preparing()){
            $observer=\Infra\Database::getInstance()->getConnection();
            $row=$observer->query("SELECT id,claimed_at,JSON_UNQUOTE(JSON_EXTRACT(payload,'$.drawing_v1.phase')) phase,JSON_EXTRACT(payload,'$.drawing_v1.phase_version') version
                FROM llm_jobs WHERE status='processing' AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.drawing_v1.phase')) IN ('scene','image','safe_rewrite','safe_image','vision') ORDER BY id DESC LIMIT 1")->fetch_assoc();
            if($row)$trace+=['job'=>(int)$row['id'],'phase'=>$row['phase'],'claimed_at'=>$row['claimed_at'],'version'=>(int)$row['version']];
        }
        file_put_contents($dir.'/trace.jsonl',json_encode($trace)."\n",FILE_APPEND|LOCK_EX);
        if ($image) {
            if (!empty($state['block'])) {
                file_put_contents($dir.'/entered',json_encode($trace));
                $until=microtime(true)+90;
                while (!is_file($dir.'/release') && microtime(true)<$until) usleep(20000);
                if (!is_file($dir.'/release')) throw new \RuntimeException('Drawing barrier not released');
            }
            if(($state['safety_remaining']??0)>0){
                $state['safety_remaining']--;file_put_contents($dir.'/state.json',json_encode($state));
                if(!empty($state['age_before_safety'])&&isset($trace['job']))\Infra\Database::getInstance()->getConnection()->query('UPDATE llm_jobs SET claimed_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 599 SECOND) WHERE id='.(int)$trace['job']);
                return '{"error":{"message":"content safety blocked"}}';
            }
            if (!empty($state['fail_image'])) { $ch->errno=28; $ch->error='synthetic timeout'; return false; }
            $im=imagecreatetruecolor(128,128);
            for ($x=0;$x<128;$x++) for($y=0;$y<128;$y++) imagesetpixel($im,$x,$y,imagecolorallocate($im,($x*7)%255,($y*9)%255,($x+$y)%255));
            ob_start(); imagepng($im); $bytes=ob_get_clean(); imagedestroy($im);
            return json_encode(['data'=>[['b64_json'=>base64_encode($bytes)]]]);
        }
        $system=(string)($payload['messages'][0]['content'] ?? '');
        $last=$payload['messages'][array_key_last($payload['messages'] ?? [])]['content'] ?? '';
        $user=is_string($last)?$last:json_encode($last,JSON_UNESCAPED_UNICODE);
        if (!empty($state['block_caption']) && str_contains($user,'как художница')) {
            file_put_contents($dir.'/caption_entered','1');$until=microtime(true)+30;
            while(!is_file($dir.'/caption_release')&&microtime(true)<$until)usleep(20000);
            if(!is_file($dir.'/caption_release'))throw new \RuntimeException('Caption barrier not released');
        }
        $text='@Рисующий, отвечаю, пока рисую.';
        if (str_contains($system,'служебный генератор пони-обликов')) $text="внешность: мятный единорог, синяя грива, яркий шарф, кьютимарка — книга\nперсонаж: хранит библиотеку и любит придумывать истории";
        elseif (str_contains($system,'режиссёр-описатель') || str_contains($system,'rewrite scene')) $text='A mint pony carries a bright balloon through a castle courtyard.';
        elseif (str_contains($system,'Опиши изображение')) $text='Мятный пони несёт яркий воздушный шар во дворе замка.';
        elseif (str_contains($user,'занята другим рисунком')) $text='@Рисующий, сейчас занята другим рисунком. Твой запрос ждёт в очереди.';
        elseif (str_contains($user,'как художница')) $text='@Рисующий, вот рисунок! Мне нравится ритм линий и выразительная композиция.';
        elseif (str_contains($user,'одну минуту')) $text='Даю минуту на пожелания ответом с цитатой. Затем нарисую сама.';
        elseif (str_contains($user,'Ты придумала, как выглядит')) $text='@Рисующий, я представила тебя мятным единорогом с синей гривой. Если хочешь другой облик — напиши «/яос своё описание».';
        return json_encode(['choices'=>[['finish_reason'=>'stop','message'=>['content'=>$text]]]]);
    }
}
