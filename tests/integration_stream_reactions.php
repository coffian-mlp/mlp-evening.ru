<?php
namespace Infra {
    function curl_init(){return new \stdClass();}
    function curl_setopt($handle,$option,$value){$GLOBALS['stream_realtime_options'][$option]=$value;return true;}
    function curl_exec($handle){return '{"result":{}}';}
    function curl_getinfo($handle,$option){return 200;}
    function curl_close($handle){}
}
namespace {
require __DIR__.'/integration_helpers.php';it_require_db();ob_start();
$db=Infra\Database::getInstance()->getConnection();$users=new Domain\UserManager();$owner=new Domain\StreamReactions();
$id=$users->createUser('it_stream_react_'.bin2hex(random_bytes(6)),'Aa!'.bin2hex(random_bytes(10)));
$first=$owner->latestId();$fail=0;
$check=static function($v,$text)use(&$fail){echo ($v?'[OK] ':'[FAIL] ').$text."\n";if(!$v)$fail++;};
$blocked=static function(callable $fn){try{$fn();return false;}catch(Core\UserError $error){return true;}};
try{
    $check($blocked(fn()=>$owner->send($id,'heart')),'account without consent cannot send');
    Domain\Privacy::setAccountConsent($id,true);
    Infra\Env::get('CHAT_DRIVER');$envProperty=new ReflectionProperty(Infra\Env::class,'vars');$env=$envProperty->getValue();
    $envProperty->setValue(null,array_merge($env,['CHAT_DRIVER'=>'centrifugo','CENTRIFUGO_API_KEY'=>'test-only-key']));
    try{$event=$owner->send($id,'heart');}finally{$envProperty->setValue(null,$env);}
    $sent=json_decode($GLOBALS['stream_realtime_options'][CURLOPT_POSTFIELDS]??'',true);
    $check(($sent['channel']??null)==='public:chat' && ($sent['data']??null)===$event && $GLOBALS['stream_realtime_options'][CURLOPT_TIMEOUT]===2,'Centrifugo publishes the exact anonymous event with a bounded timeout');
    $check($event['reaction']==='heart' && !isset($event['user_id']) && $event['expires_at']>time()*1000,'event carries an expiry but no identity');
    $check($blocked(fn()=>$owner->send($id,'poop')),'server cooldown cannot be bypassed by a second click');
    $check($blocked(fn()=>$owner->send(0,'heart')) && $blocked(fn()=>$owner->send($id,'<img>')),'guest and arbitrary reaction rejected');
    $db->query('UPDATE stream_reaction_limits SET last_at=UTC_TIMESTAMP()-INTERVAL 10 SECOND WHERE user_id='.(int)$id);
    $db->query('UPDATE users SET muted_until=UTC_TIMESTAMP()+INTERVAL 1 HOUR WHERE id='.(int)$id);
    $check($blocked(fn()=>$owner->send($id,'heart')),'mute policy applies to broadcast emotions');
    $db->query('UPDATE users SET muted_until=NULL,is_banned=1 WHERE id='.(int)$id);
    $check($blocked(fn()=>$owner->send($id,'heart')),'ban policy applies to broadcast emotions');
    $db->query('UPDATE users SET is_banned=0 WHERE id='.(int)$id);
    Domain\Privacy::unlockAccount($id);
    $program='require "autoload.php";if(Infra\\Env::get("DB_HOST")!=="db")exit(2);try{(new Domain\\StreamReactions())->send('.(int)$id.',"fire");echo "accepted";}catch(Core\\UserError $e){echo "blocked";}';
    $processes=[];
    for($i=0;$i<2;$i++){$pipes=[];$process=proc_open([PHP_BINARY,'-r',$program],[1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));$processes[]=[$process,$pipes];}
    $answers=[];foreach($processes as[$process,$pipes]){$answers[]=stream_get_contents($pipes[1]);fclose($pipes[1]);fclose($pipes[2]);proc_close($process);}sort($answers);
    $check($answers===['accepted','blocked'],'concurrent requests accept exactly one emotion');
    $db->query('UPDATE stream_reactions SET created_at=UTC_TIMESTAMP()-INTERVAL 20 SECOND WHERE id>'.(int)$first);
    $check(!$owner->after($first),'expired events are not delivered');
    $db->query('UPDATE stream_reactions SET created_at=UTC_TIMESTAMP()-INTERVAL 2 MINUTE WHERE id>'.(int)$first);
    $db->query('UPDATE stream_reaction_limits SET last_at=UTC_TIMESTAMP()-INTERVAL 2 DAY WHERE user_id='.(int)$id);
    $owner->purgeExpired();
    $check(!$db->query('SELECT 1 FROM stream_reactions WHERE id>'.(int)$first)->num_rows && !$db->query('SELECT 1 FROM stream_reaction_limits WHERE user_id='.(int)$id)->num_rows,'bounded cleanup removes expired anonymous events and rate-limit linkage');
    $db->query('INSERT INTO stream_reaction_limits(user_id,last_at) VALUES('.(int)$id.',UTC_TIMESTAMP())');
    $owner->eraseUserData($id);
    $check(!$db->query('SELECT 1 FROM stream_reaction_limits WHERE user_id='.(int)$id)->num_rows,'privacy erasure clears the only account-linked record');
}finally{
    $db->query('DELETE FROM stream_reactions WHERE id>'.(int)$first);
    $owner->eraseUserData($id);Domain\Privacy::stageErasure($id,[]);$users->deleteUser($id);
}
echo $fail?"FAIL: $fail\n":"ALL PASS\n";exit($fail?1:0);
}
