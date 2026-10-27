<?php
/** Isolated loopback fixture for the real cURL cookie and redirect handshake. */
if(PHP_SAPI!=='cli')exit(1);
$servers=[];
foreach([8897,8898] as $port){
    $server=@stream_socket_server('tcp://127.0.0.1:'.$port,$errno,$error);
    if(!$server){fwrite(STDERR,"Cannot bind fixture port $port: $error\n");exit(1);}
    $servers[]=$server;
}
echo "READY\n";flush();$sample=0;$deadline=microtime(true)+20;
while(microtime(true)<$deadline){
    $read=$servers;$write=$except=[];
    if(!stream_select($read,$write,$except,1))continue;
    foreach($read as $server){
        $connection=stream_socket_accept($server,1);if(!$connection)continue;
        stream_set_timeout($connection,2);$line=fgets($connection);$headers='';
        while(($header=fgets($connection))!==false&&trim($header)!=='')$headers.=$header;
        $target=explode(' ',trim($line))[1]??'';$path=parse_url($target,PHP_URL_PATH);$query=[];parse_str(parse_url($target,PHP_URL_QUERY)??'',$query);
        $status='200 OK';$extra='';$body='';$session='fixture-session-'.$sample;
        if($path==='/v3/paths/get/evening'){$sample++;$body='{"ready":true}';}
        elseif($path==='/evening/index.m3u8'&&!isset($query['cookieCheck'])){
            $status='302 Found';$extra="Location: /evening/index.m3u8?cookieCheck=1\r\nSet-Cookie: cookieCheck=1; Path=/evening/; HttpOnly\r\n";
        }elseif($path==='/evening/index.m3u8'&&($query['cookieCheck']??'')==='1'&&preg_match('/Cookie:.*\bcookieCheck=1\b/i',$headers)){
            $body="#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=4000000\nvideo1_stream.m3u8?session=$session\n";
        }elseif($path==='/evening/video1_stream.m3u8'&&($query['session']??'')===$session){
            $sequence=$sample>2?101:100;
            $body="#EXTM3U\n#EXT-X-MEDIA-SEQUENCE:$sequence\n#EXT-X-MAP:URI=\"init.mp4?session=$session\"\n#EXTINF:2,\nvideo$sequence.mp4?session=$session\n";
        }else{$status='403 Forbidden';$body='Fixture handshake rejected';}
        fwrite($connection,"HTTP/1.1 $status\r\n$extra".'Content-Length: '.strlen($body)."\r\nConnection: close\r\n\r\n$body");fclose($connection);
    }
}
