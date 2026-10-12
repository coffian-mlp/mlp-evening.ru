<?php
namespace Social {
    function curl_init($url) { return \curl_init($url); }
    function curl_setopt_array($handle,$options) { $GLOBALS['tg385_options']=$options; return true; }
    function curl_exec($handle) { return '{"ok":true,"result":{"is_bot":true,"id":12345,"message_id":42}}'; }
    function curl_getinfo($handle,$option) { return 200; }
    function curl_errno($handle) { return 0; }
    function curl_close($handle) { \curl_close($handle); }
}
namespace {
    require_once __DIR__.'/../autoload.php';
    $fail=0;
    function verify385($condition,$label) { global $fail; echo ($condition?'[OK] ':'[FAIL] ').$label."\n"; if(!$condition)$fail++; }
    $vars=new \ReflectionProperty(\Infra\Env::class,'vars');
    $saved=$vars->getValue();
    try {
        $token='123456789:'.str_repeat('a',35);
        $vars->setValue(null,['TELEGRAM_ANNOUNCEMENTS_IPV6_ONLY'=>'1']);
        (new \Social\TelegramBotClient($token))->request('getMe');
        verify385($GLOBALS['tg385_options'][CURLOPT_IPRESOLVE]===CURL_IPRESOLVE_V6 && $GLOBALS['tg385_options'][CURLOPT_PROXY]==='', 'direct mode uses IPv6 and disables inherited proxy');
        (new \Social\TelegramBotClient($token,null,'socks5h://example.org:1080'))->request('getMe');
        verify385(!isset($GLOBALS['tg385_options'][CURLOPT_IPRESOLVE]) && $GLOBALS['tg385_options'][CURLOPT_PROXY]==='socks5h://example.org:1080','explicit proxy remains unaffected by direct IPv6 setting');
        $photoDir=dirname(__DIR__).'/upload/lyra';
        $madeDir=!is_dir($photoDir); if($madeDir)mkdir($photoDir,0755,true);
        $photoName='fixture385_'.bin2hex(random_bytes(8)).'.jpg'; $photoFile=$photoDir.'/'.$photoName;
        file_put_contents($photoFile,'fixture');
        try {
            (new \Social\TelegramBotClient($token))->request('sendPhoto',['chat_id'=>1,'caption'=>'Fixture','photo'=>'/upload/lyra/'.$photoName]);
            verify385($GLOBALS['tg385_options'][CURLOPT_TIMEOUT]===90 && $GLOBALS['tg385_options'][CURLOPT_POSTFIELDS]['photo'] instanceof \CURLFile, 'photo upload receives its own timeout and remains multipart');
        } finally { unlink($photoFile); if($madeDir)rmdir($photoDir); }
        $vars->setValue(null,[]);
        (new \Social\TelegramBotClient($token))->request('getMe');
        verify385(!isset($GLOBALS['tg385_options'][CURLOPT_IPRESOLVE]),'automatic address selection remains default');
        verify385($GLOBALS['tg385_options'][CURLOPT_TIMEOUT]===15,'short requests retain original timeout');
    } finally { $vars->setValue(null,$saved); }
    echo $fail?"FAIL: $fail\n":"ALL PASS\n";
    exit($fail?1:0);
}
