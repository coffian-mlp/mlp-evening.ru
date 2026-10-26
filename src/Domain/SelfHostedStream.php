<?php
namespace Domain;

use Core\UserError;
use Infra\ConfigManager;
use Infra\Database;
use Infra\Env;
use Infra\Transaction;

/** Optional MediaMTX adapter. Public rendering never calls its control API. */
final class SelfHostedStream
{
    public const DEFAULT_URL='/live/evening/index.m3u8';

    public static function settings(): array
    {
        $config=ConfigManager::getInstance();
        $source=$config->getOption('stream_source','external');
        try{$url=self::normalizeUrl($config->getOption('self_hosted_hls_url',self::DEFAULT_URL));}catch(UserError $e){$url=self::DEFAULT_URL;}
        return ['source'=>$source==='self_hosted'?'self_hosted':'external','url'=>$url];
    }

    public static function normalizeUrl(mixed $url): string
    {
        if(!is_string($url)||!preg_match('#^/live/[a-zA-Z0-9_-]{1,64}/index\.m3u8$#D',$url))throw new UserError('Укажи локальный адрес HLS вида /live/evening/index.m3u8.');
        return $url;
    }

    public static function save(array $input): void
    {
        $source=$input['stream_source']??null;
        if(!in_array($source,['external','self_hosted'],true))throw new UserError('Выбери источник видео.');
        $hls=self::normalizeUrl($input['self_hosted_hls_url']??null);
        $external=$input['stream_url']??null;
        if(!is_string($external)||strlen($external)>2048||!filter_var($external,FILTER_VALIDATE_URL)||!in_array(strtolower(parse_url($external,PHP_URL_SCHEME)??''),['http','https'],true)||parse_url($external,PHP_URL_USER)!==null||parse_url($external,PHP_URL_PASS)!==null)throw new UserError('Укажи ссылку HTTP(S) на внешний плеер.');
        $config=ConfigManager::getInstance();
        try{Transaction::run(Database::getInstance()->getConnection(),static function()use($config,$source,$hls,$external){
            foreach(['stream_source'=>$source,'self_hosted_hls_url'=>$hls,'stream_url'=>$external] as $key=>$value)if(!$config->setOption($key,$value))throw new \RuntimeException('Stream settings save failed');
        });}finally{$config->flushCache();}
    }

    public static function status(): array
    {
        $url=self::settings()['url'];$path=explode('/',$url)[2];
        $body='';$curl=curl_init('http://127.0.0.1:8897/v3/paths/get/'.rawurlencode($path));
        curl_setopt_array($curl,[CURLOPT_PROXY=>'',CURLOPT_NOPROXY=>'*',CURLOPT_CONNECTTIMEOUT_MS=>500,CURLOPT_TIMEOUT_MS=>1500,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_WRITEFUNCTION=>static function($curl,$chunk)use(&$body){if(strlen($body)+strlen($chunk)>65536)return 0;$body.=$chunk;return strlen($chunk);}]);
        $ok=curl_exec($curl);$http=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
        $data=json_decode($body,true);$up=$ok!==false&&in_array($http,[200,404],true);
        return ['available'=>$up,'live'=>$up&&$http===200&&!empty($data['ready']),'readers'=>$http===200&&is_array($data['readers']??null)?count($data['readers']):0,
            'tracks'=>$http===200&&is_array($data['tracks']??null)?array_values(array_filter($data['tracks'],'is_string')):[],
            'message'=>!$up?'Медиасервер пока недоступен.':($http===200&&!empty($data['ready'])?'Поток поступает. Открой предпросмотр для проверки видео и звука.':'Медиасервер доступен, ожидает поток из OBS.')];
    }

    public static function credentials(): array
    {
        $path=Env::get('SELF_HOSTED_STREAM_CREDENTIALS','/etc/mlp-evening/stream-publish.json');
        if(!is_file($path)||!is_readable($path)||filesize($path)>4096)throw new UserError('Параметры OBS ещё не установлены на сервере.');
        $data=json_decode(file_get_contents($path),true);
        if(!is_array($data)||!is_string($data['server']??null)||!preg_match('#^rtmps://[a-zA-Z0-9.-]+:1936$#D',$data['server'])||!is_string($data['key']??null)||!preg_match('/^evening\?user=broadcaster&pass=[a-f0-9]{64}$/D',$data['key']))throw new UserError('Проверь файл параметров OBS на сервере.');
        return ['server'=>$data['server'],'key'=>$data['key']];
    }
}
