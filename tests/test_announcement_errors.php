<?php
require_once __DIR__.'/../autoload.php';
$failed=0;
function check426($ok,$label) { global $failed;echo ($ok?'[OK] ':'[FAIL] ').$label."\n";if(!$ok)$failed++; }
$facts=['snapshot'=>['stories'=>[['ids'=>[188],'titles'=>['My Little Pony Friendship is Magic - Season 8 Episode 19 - Road to Friendship']]]]];
$text=Domain\AnnouncementError::describe('synopsis_sources_missing:188',$facts);
check426(str_contains($text,'Road to Friendship') && str_contains($text,'источники'),'synopsis failure identifies episode and stage');
foreach(['synopsis_unavailable:188','synopsis_invalid:188','synopsis_deadline:188','synopsis_catalogue_invalid','text_unavailable','text_exception','text_invalid','image_daily_limit','image_unavailable','image_exception','delivery_uncertain','delivery_interrupted','delivery_rejected','source_unavailable','generation_failed'] as $code) {
    $text=Domain\AnnouncementError::describe($code,$facts);
    check426($text!=='' && !str_contains($text,$code),'safe human explanation for '.$code);
}
check426(!str_contains(Domain\AnnouncementError::describe('<script>credential</script>'),'<script>'),'unknown error never echoes provider content');
echo $failed?"FAIL: $failed\n":"ALL PASS\n";exit($failed?1:0);
