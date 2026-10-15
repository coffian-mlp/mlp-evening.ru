<?php
require __DIR__ . '/../autoload.php';
require __DIR__ . '/../src/Components/VideoPlayer/class.php';
use Components\VideoPlayer\VideoPlayerComponent as Player;
$failures=0;
function playerCheck(bool $ok,string $label):void{global $failures;echo ($ok?'[OK] ':'[FAIL] ').$label."\n";if(!$ok)$failures++;}
$options=Player::twitchOptions('https://player.twitch.tv/?channel=coffian&parent=mlp-evening.ru&parent=www.mlp-evening.ru&muted=true&autoplay=false');
playerCheck($options['channel']==='coffian','configured embed channel');
playerCheck($options['parent']===['mlp-evening.ru','www.mlp-evening.ru'],'all configured parents retained');
playerCheck($options['muted']===true&&$options['autoplay']===false,'playback flags retained');
playerCheck(Player::twitchOptions('https://www.twitch.tv/coffian')['channel']==='coffian','public channel link');
playerCheck(Player::twitchOptions('https://twitch.tv/videos/12345')['video']==='12345','public VOD link');
playerCheck(Player::twitchOptions('https://player.twitch.tv/?video=v12345&time=1h2m3s')['time']==='1h2m3s','VOD start time retained');
playerCheck(Player::twitchOptions('https://player.twitch.tv/?collection=abc-123&video=v12')['collection']==='abc-123','collection retained');
playerCheck(!isset(Player::twitchOptions('https://player.twitch.tv/?channel=coffian&video=v12')['video']),'channel has precedence over VOD');
foreach(['https://goodgame.ru/player?161438#autoplay','https://example.com/?channel=coffian','https://player.twitch.tv.evil.test/?channel=coffian','https://player.twitch.tv@evil.test/?channel=coffian','https://evil.test@player.twitch.tv/?channel=coffian','https://player.twitch.tv/?channel[]=coffian','https://player.twitch.tv/','https://clips.twitch.tv/embed?clip=sample','javascript:alert(1)','/relative/path','https://twitch.tv/directory'] as $source)playerCheck(Player::twitchOptions($source)===null,'leave unsupported source unchanged: '.$source);
echo $failures ? "FAIL: $failures\n" : "ALL PASS\n";
exit($failures?1:0);
