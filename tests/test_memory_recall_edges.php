<?php
require_once dirname(__DIR__).'/autoload.php';
$fail=0;
function edgeCheck(bool $ok,string $label):void{global $fail;echo($ok?'[OK] ':'[FAIL] ').$label."\n";if(!$ok)$fail++;}

$source='synthetic_recall_source_821';
$request=['messages'=>[['content'=>LLM\MemoryRecallBlock::MARKER."\n".$source]],'url'=>'https://example.test/a/b'];
$cases=['array'=>$request,'nested_array'=>['outer'=>[$request]]];
foreach([0,JSON_UNESCAPED_UNICODE,JSON_UNESCAPED_SLASHES,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES]as$flags){
    $json=json_encode($request,$flags);
    $cases['json_'.$flags]=$json;
    $cases['nested_json_'.$flags]=['wrapper'=>json_encode(['inner'=>$json],$flags)];
    $cases['json_string_'.$flags]=json_encode($json,$flags);
}
foreach($cases as$name=>$input){
    $found=false;$output=LLM\LlmDebugLog::redactRecall($input,$found);
    edgeCheck($found&&!str_contains(LLM\LlmDebugLog::squash($output),$source),'F2-R3 '.$name.' redacts serialized recall');
}
$plain=['message'=>'Обычная реплика / путь','nested'=>['value'=>'unchanged']];
$ordinary=['array'=>$plain,'spaced_json'=>"{\n  \"url\": \"https:\\/\\/example.test\", \"text\": \"\\u041b\\u0438\\u0440\\u0430\"\n}"];
foreach([0,JSON_UNESCAPED_UNICODE,JSON_UNESCAPED_SLASHES,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES]as$flags)$ordinary['json_'.$flags]=json_encode(['wrapper'=>json_encode($plain,$flags)],$flags);
foreach($ordinary as$name=>$input){$found=false;edgeCheck(LLM\LlmDebugLog::redactRecall($input,$found)===$input&&!$found,'F2-R3 ordinary '.$name.' is byte-identical');}

$queries=[
    'Лира, сравни кодеки Lyra и Opus'=>'сравни кодеки Lyra и Opus',
    'Помнишь участника @Лира?'=>'участника @Лира?',
    'Игра на лире'=>'Игра на лире',
    'Как устроен Lyra codec?'=>'устроен Lyra codec?',
    'Лира, а ты знаешь Лиру Хартстрингс?'=>'знаешь Лиру Хартстрингс?',
    'Lyra, what about Lyra Heartstrings?'=>'what about Lyra Heartstrings?',
    '>Феникс <Райт'=>'Феникс Райт',
    'Привет, Лира!'=>'',
    'Феникс + - * " ~ < > ( ) @ Райт'=>'Феникс Райт',
    '+Феникс -Райт*'=>'Феникс Райт',
    'Феникс "Райт'=>'Феникс Райт',
    'помнишь DL-6?'=>'DL-6?',
    'попугай-чейнджлинг'=>'попугай-чейнджлинг',
    'Кто такая Лира?'=>'такая Лира?',
    '@Ли'=>'@Ли',
];
foreach($queries as$input=>$expected)edgeCheck(LLM\MemoryQueryText::clean($input)===$expected,'F2-R4 preserves topic: '.$input);
foreach([',',':','!','']as$delimiter)edgeCheck(LLM\MemoryQueryText::clean('Лира'.$delimiter.' Феникс Райт')==='Феникс Райт','F2-R4 leading address delimiter '.($delimiter?:'none'));
edgeCheck(LLM\MemoryQueryText::clean('CustomBot: тема о CustomBot',['CustomBot'])==='тема CustomBot','F2-R4 configured name removed only as leading address');
edgeCheck(LLM\MemoryQueryText::clean('тема @CustomBot и @Лира',['CustomBot'])==='тема и @Лира','F2-R4 configured bot mention removed while participant remains');
edgeCheck(LLM\MemoryQueryText::clean('@CustomBot: тема @Other',['@CustomBot'])==='тема @Other','F2-R4 configured aliases accept mention prefix');
edgeCheck(LLM\MemoryQueryText::clean('тема @CustomBot-v2',['CustomBot'])==='тема @CustomBot-v2','F2-R4 bot nickname prefix does not remove another participant');
edgeCheck(LLM\MemoryQueryText::clean('Lyra-codec и Opus')==='Lyra-codec и Opus','F2-R4 compound topic is not a leading address');
edgeCheck(in_array('лира',LLM\MemoryRetriever::terms(LLM\MemoryQueryText::clean('Кто такая Лира?')),true),'F2-R4 lexical terms retain meaningful Lyra');
echo $fail?"FAIL: $fail\n":"ALL PASS\n";exit($fail?1:0);
