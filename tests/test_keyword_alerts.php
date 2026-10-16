<?php
require dirname(__DIR__).'/autoload.php';
use Domain\KeywordAlerts;
function check($ok) { if (!$ok) throw new RuntimeException('Assertion failed'); }
$value = json_decode(KeywordAlerts::normalize(json_encode(['enabled'=>true,'words'=>[' Твайлайт ', 'ТВАЙЛАЙТ', ' Ёж ', 'еж', 'ночной   вечерок', '']])),true);
check($value === ['enabled'=>true,'words'=>['Твайлайт','Ёж','ночной вечерок']]);
check(json_decode(KeywordAlerts::normalize('{"enabled":false,"words":[]}'),true)['words'] === []);
foreach (['{', 'null', '{"enabled":1,"words":[]}', '{"enabled":true,"words":[]}', json_encode(['enabled'=>true,'words'=>array_fill(0,11,'a')]), json_encode(['enabled'=>true,'words'=>[str_repeat('я',51)]]), '{"enabled":true,"words":[42]}', '{"enabled":true,"words":{"foo":"bar"}}'] as $bad) {
    try { KeywordAlerts::normalize($bad); throw new RuntimeException('Invalid value accepted'); }
    catch (InvalidArgumentException $expected) {}
}
echo "ALL PASS\n";
