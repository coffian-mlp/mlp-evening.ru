<?php
require_once __DIR__.'/../autoload.php';
use Domain\AnnouncementSettings;
use Social\AnnouncementProxy;
$fail=0;
$check=static function($yes,$label) use (&$fail) { echo ($yes?'PASS ':'FAIL ').$label."\n"; if(!$yes)$fail++; };
$old=['announcements_enabled'=>'0','announcements_token'=>'123456:'.str_repeat('a',35),'announcements_owner_id'=>'42','announcements_channel'=>'@mlp_evening','announcements_proxy_url'=>'socks5h://user:secret@localhost:1080','announcements_event_id'=>'20','announcements_first_number'=>'179','announcements_not_before'=>'0'];
$v=AnnouncementSettings::normalize(['announcements_token'=>'','announcements_proxy_url'=>'','announcements_first_date'=>'2026-10-17'],$old);
$check($v['announcements_token']===$old['announcements_token'] && $v['announcements_proxy_url']===$old['announcements_proxy_url'],'blank secrets preserve existing settings');
$check($v['announcements_not_before']===(string)(new DateTimeImmutable('2026-10-17',new DateTimeZone('Europe/Moscow')))->getTimestamp(),'first date interpreted in Moscow');
$check(AnnouncementSettings::normalize(['announcements_clear_proxy'=>'1'],$old)['announcements_proxy_url']==='','explicit direct connection clears proxy');
foreach ([['announcements_token'=>'secret'],['announcements_owner_id'=>'-1'],['announcements_enabled'=>'2'],['announcements_channel'=>'https://t.me/mlp_evening'],['announcements_first_number'=>'4000'],['announcements_first_date'=>'2026-02-30'],['announcements_proxy_url'=>'file:///etc/passwd'],['announcements_proxy_url'=>['secret']]] as $bad) {
 try { AnnouncementSettings::normalize($bad,$old); $check(false,'invalid input rejected'); }
 catch(InvalidArgumentException $e) { $check(!str_contains($e->getMessage(),'secret'),'invalid input rejected without credential echo'); }
}
$link='vless://aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee@example.org:443?security=reality&type=tcp&pbk=public&sid=00&sni=example.net&flow=xtls-rprx-vision';
AnnouncementProxy::validate($link); $cfg=AnnouncementProxy::config($link,12345);
$check($cfg['inbounds'][0]['listen']==='127.0.0.1' && $cfg['inbounds'][0]['port']===12345,'isolated loopback listener');
$check($cfg['outbounds'][0]['settings']['vnext'][0]['users'][0]['flow']==='xtls-rprx-vision','VLESS flow retained');
$check($cfg['outbounds'][0]['streamSettings']['realitySettings']['serverName']==='example.net','REALITY server name retained');
$check((new AnnouncementProxy('socks5://example.org:1080'))->resolve()==='socks5h://example.org:1080','SOCKS uses remote DNS');
$check((new AnnouncementProxy(''))->resolve()==='','empty proxy explicitly direct');
foreach (['vless://bad@example.org?security=reality&pbk=x',str_replace('pbk=public','pbk=',$link),str_replace('type=tcp','type=invalid',$link),str_replace('sid=00','sid[]=00',$link)] as $bad) {
 try {AnnouncementProxy::validate($bad);$check(false,'invalid VLESS rejected');} catch(InvalidArgumentException $e){$check(true,'invalid VLESS rejected');}
}
$routes=require __DIR__.'/../src/Api/routes.php';
$check($routes['update_announcements']['role']==='admin','announcement settings remain administrator-only');
echo $fail?"FAILURES $fail\n":"ALL PASS\n";exit($fail?1:0);
