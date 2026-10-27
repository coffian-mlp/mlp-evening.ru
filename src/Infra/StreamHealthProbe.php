<?php
namespace Infra;

/** Fixed loopback endpoints only; playlist progression is checked by StreamHealthPolicy. */
final class StreamHealthProbe
{
    public function __construct(private $transport=null) {}
    public function sample(string $path): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$path)) throw new \InvalidArgumentException('Invalid stream path');
        $curl=curl_init();
        curl_setopt_array($curl,[CURLOPT_PROXY=>'',CURLOPT_NOPROXY=>'*',CURLOPT_CONNECTTIMEOUT_MS=>500,CURLOPT_TIMEOUT_MS=>1500,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_COOKIEFILE=>'']);
        try {
            $api=$this->get($curl,'http://127.0.0.1:8897/v3/paths/get/'.$path);
            $data=json_decode($api['body'],true);
            if ($api['status']===404 || ($api['status']===200 && is_array($data) && array_key_exists('ready',$data) && !$data['ready'])) return ['state'=>'down','reason'=>'no_source'];
            $hls=$this->get($curl,'http://127.0.0.1:8898/'.$path.'/index.m3u8');
            if ($hls['status']===200 && str_starts_with(trim($hls['body']),'#EXTM3U')) {
                $body=$hls['body'];
                if (str_contains($body,'#EXT-X-STREAM-INF')) {
                    if (!preg_match('/#EXT-X-STREAM-INF[^\n]*\n([A-Za-z0-9_-]+\.m3u8(?:\?session=[A-Za-z0-9_-]{1,128})?)\s*(?:\n|$)/',$body,$m)) return ['state'=>'unknown','reason'=>'invalid_manifest'];
                    $media=$this->get($curl,'http://127.0.0.1:8898/'.$path.'/'.$m[1]);
                    $body=$media['status']===200?$media['body']:'';
                }
                if (str_contains($body,'#EXTINF:') && !str_contains($body,'#EXT-X-ENDLIST')) {
                    // A fresh HLS session must not disguise an unchanged media sequence.
                    $progress=preg_replace('/\?session=[A-Za-z0-9_-]{1,128}/','',$body);
                    return ['state'=>'live','progress'=>hash('sha256',$progress),'reason'=>'hls'];
                }
            }
            return ['state'=>'down','reason'=>$api['status']===200?'hls_unavailable':'media_unavailable'];
        } finally { curl_close($curl); }
    }

    private function get(\CurlHandle $curl, string $url): array
    {
        $request=$url;
        for($redirects=0;$redirects<=2;$redirects++){
            $response=$this->request($curl,$request);
            if(!in_array($response['status'],[301,302,303,307,308],true))return $response;
            $location=$response['location']??'';
            $path=parse_url($url,PHP_URL_PATH);
            // Only MediaMTX's same-playlist cookie/session handshake is permitted.
            if($redirects===2||!str_starts_with($url,'http://127.0.0.1:8898/')||
                !preg_match('#^(?:http://127\.0\.0\.1:8898)?'.preg_quote($path,'#').'(?:\?(?:cookieCheck=1|session=[A-Za-z0-9_-]{1,128}))?$#D',$location))return ['status'=>0,'body'=>''];
            $request=str_starts_with($location,'/')?'http://127.0.0.1:8898'.$location:$location;
        }
        return ['status'=>0,'body'=>''];
    }

    private function request(\CurlHandle $curl, string $url): array
    {
        if($this->transport!==null)return ($this->transport)($url);
        $body='';$location='';curl_setopt($curl,CURLOPT_URL,$url);
        curl_setopt($curl,CURLOPT_HEADERFUNCTION,static function($curl,$header)use(&$location){if(strncasecmp($header,'Location:',9)===0)$location=trim(substr($header,9));return strlen($header);});
        curl_setopt($curl,CURLOPT_WRITEFUNCTION,static function($curl,$chunk)use(&$body){if(strlen($body)+strlen($chunk)>65536)return 0;$body.=$chunk;return strlen($chunk);});
        $ok=curl_exec($curl);
        return ['status'=>$ok===false?0:(int)curl_getinfo($curl,CURLINFO_HTTP_CODE),'body'=>$body,'location'=>$location];
    }
}
