<?php
require_once dirname(__DIR__).'/autoload.php';
use LLM\RussianStemmer;
$fail=0;$checks=0;
function stemCheck(bool $ok,string $label):void{global$fail,$checks;$checks++;echo($ok?'[OK] ':'[FAIL] ').$label."\n";if(!$ok)$fail++;}
$reference=json_decode(file_get_contents(__DIR__.'/fixtures/russian_snowball.json'),true,512,JSON_THROW_ON_ERROR);
foreach($reference['pairs']as[$word,$expected])stemCheck(RussianStemmer::stem($word)===$expected,'Snowball reference: '.$word);
foreach([
 'дом'=>'дом','дому'=>'дом','домом'=>'дом','банка'=>'банк','банку'=>'банк',
 'попугай'=>'попуга','попугая'=>'попуг','трёхлитровый'=>'трехлитров','трёхлитрового'=>'трехлитров',
 'Феникс'=>'феникс','Феникса'=>'феникс','альфарий'=>'альфар','альфария'=>'альфар',
]as$word=>$expected)stemCheck(RussianStemmer::stem($word)===$expected,'Project word follows Snowball: '.$word);
// Snowball is not a lemmatizer: the parrot pair intentionally has the official unequal stems.
foreach([['дом','домино'],['дом','домовой'],['банк','банкир'],['банк','банкомат'],['мост','мостик'],['мост','мостовой'],['река','реклама'],['лиса','лист'],['лист','листок'],['мастер','мастерские']]as[$left,$right])stemCheck(RussianStemmer::stem($left)!==RussianStemmer::stem($right),'No false stem equality: '.$left.' / '.$right);
stemCheck(RussianStemmer::stem('ТРЁХЛИТРОВЫЙ')===RussianStemmer::stem('трехлитровый'),'case and ё are folded before stemming');
stemCheck(RussianStemmer::stem('DL-6')==='dl-6','non-Russian identifiers remain unchanged');
echo $fail?"FAIL: $fail\n":"ALL PASS\n";exit($fail?1:0);
