<?php
namespace LLM;

use Infra\ConfigManager;

/** Query-only transport. Expansion, when explicitly enabled, runs beside embeddings. */
final class MemoryRecallQuery
{
    public function __invoke(string $text, float $deadline, bool $expand): array
    {
        if (!$expand) {
            $usage=[];
            $meter=static function($raw,$code,$error,$duration) use (&$usage) {$body=json_decode($raw??'',true);$row=UsageMeter::measurement('routerai',RouterAIEmbeddingProvider::MODEL,is_array($body)?$body:[],$code,$error,$duration,'memory_recall');$usage[]=$row;UsageMeter::defer($row);};
            $vector=RouterAIEmbeddingProvider::fromConfig($meter)->embed([$text], $deadline)[0];
            return ['vector'=>$vector,'tags'=>'','usage'=>$usage];
        }
        $config = ConfigManager::getInstance();
        $key = (string)$config->getOption('ai_routerai_key', '');
        $model = (string)$config->getOption('ai_fast_model', '');
        if ($key === '' || $model === '' || $config->getOption('ai_fast_provider', 'routerai') !== 'routerai') throw new \RuntimeException('Recall expansion unavailable');
        $proxy = (string)$config->getOption('ai_proxy_url', '');
        if (str_starts_with($proxy, 'socks5://')) $proxy = 'socks5h://' . substr($proxy, 9);
        if (str_starts_with($proxy, 'vless://')) $proxy = 'socks5h://127.0.0.1:10808';
        $requests = [
            ['url'=>'embeddings','model'=>RouterAIEmbeddingProvider::MODEL,'payload'=>['model'=>RouterAIEmbeddingProvider::MODEL,'input'=>[$text],'dimensions'=>512,'encoding_format'=>'float']],
            ['url'=>'chat/completions','model'=>$model,'payload'=>['model'=>$model,'messages'=>[
                ['role'=>'system','content'=>'Разверни реплику в 5–10 коротких поисковых ассоциаций. ' . MemoryAssociations::PROMPT],
                ['role'=>'user','content'=>json_encode(['source_data'=>$text], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
            ],'max_tokens'=>1000,'reasoning'=>['effort'=>'low'],'temperature'=>0.7]],
        ];
        $multi = curl_multi_init(); $handles = []; $bodies = [];
        try {
            foreach ($requests as $request) {
                $remaining = (int)floor(($deadline - microtime(true)) * 1000);
                if ($remaining < 1) throw new MemoryIndexDeadline('deadline');
                $handle = curl_init('https://routerai.ru/api/v1/' . $request['url']);
                curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($request['payload'], JSON_THROW_ON_ERROR),
                    CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key,'Content-Type: application/json'],CURLOPT_TIMEOUT_MS=>$remaining,CURLOPT_CONNECTTIMEOUT_MS=>$remaining,
                    CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
                if ($proxy !== '') curl_setopt($handle, CURLOPT_PROXY, $proxy);
                $handles[] = $handle; curl_multi_add_handle($multi, $handle);
            }
            do {
                $status = curl_multi_exec($multi, $running);
                if ($status !== CURLM_OK || microtime(true) >= $deadline) throw new MemoryIndexDeadline('deadline');
                if ($running) curl_multi_select($multi, min(0.01, max(0.001, $deadline - microtime(true))));
            } while ($running);
            foreach ($handles as $i => $handle) {
                $raw = curl_multi_getcontent($handle); $code = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE); $error = curl_errno($handle) !== 0;
                // Query accounting must not open a second, potentially blocking DB connection inside the deadline.
                if ($error || $code !== 200) throw new \RuntimeException('Recall expansion failed');
                $bodies[$i] = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            }
        } finally {
            foreach ($handles as $i=>$handle) {
                $raw=curl_multi_getcontent($handle);$body=json_decode(is_string($raw)?$raw:'',true);$code=(int)curl_getinfo($handle,CURLINFO_HTTP_CODE);
                UsageMeter::defer(UsageMeter::measurement('routerai',$requests[$i]['model'],is_array($body)?$body:[],$code,curl_errno($handle)!==0||$code===0,(int)round(curl_getinfo($handle,CURLINFO_TOTAL_TIME)*1000),'memory_recall'));
                curl_multi_remove_handle($multi, $handle); curl_close($handle);
            }
            curl_multi_close($multi);
        }
        return self::parse($bodies);
    }

    public static function parse(array $bodies): array
    {
        if((isset($bodies[0]['error'])||isset($bodies[1]['error']))||count($bodies[0]['data']??[])!==1||($bodies[0]['data'][0]['index']??null)!==0)throw new \RuntimeException('Invalid recall embedding response');
        $vector = $bodies[0]['data'][0]['embedding'] ?? [];
        $tags = $bodies[1]['choices'][0]['message']['content'] ?? '';
        if (!is_string($tags) || trim($tags) === '' || ($bodies[1]['choices'][0]['finish_reason']??'')==='length') throw new \RuntimeException('Recall expansion empty or truncated');
        return ['vector'=>MemoryVectorMatrix::unit($vector),'tags'=>mb_substr(strip_tags($tags), 0, 1000)];
    }
}
