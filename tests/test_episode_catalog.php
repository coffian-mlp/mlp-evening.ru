<?php
require_once __DIR__.'/../autoload.php';
use Domain\EpisodeCatalog;
$rows=[];foreach([2,1,3] as $id)$rows[]=['ID'=>$id,'TITLE'=>"My Little Pony Friendship is Magic - Season 1 Episode 0$id - Name$id",'LENGTH'=>$id===3?4:2,'TWOPART_ID'=>$id===3?null:3-$id,'WANNA_WATCH'=>$id===2?2:0,'TIMES_WATCHED'=>1];
function check($v,$label){if(!$v){echo "FAIL $label\n";exit(1);}echo "OK $label\n";}
$s=EpisodeCatalog::normalize($rows);check($s[0]['ids']===[1,2],'canonical pair');check($s[0]['weight']===1.0,'weight');check($s[1]['length']===4,'movie slots');check(EpisodeCatalog::resolveExact('с1э2',$rows)['episodes'][0]['ID']===2,'code');check(EpisodeCatalog::resolveExact('серию 3',$rows)['status']==='found','numeric');$rows[0]['TWOPART_ID']=99;check(count(EpisodeCatalog::normalize($rows))===1,'bad links excluded');

foreach ([[0,0,1.0],[2,0,2.0],[2,1,1.0]] as [$votes,$views,$weight]) {
    $row=['ID'=>100,'TITLE'=>'Weight fixture','LENGTH'=>1,'TWOPART_ID'=>null,'WANNA_WATCH'=>$votes,'TIMES_WATCHED'=>$views];
    $normalized=EpisodeCatalog::normalize([$row]);
    check($normalized[0]['weight']===$weight,'weight V'.$votes.'/P'.$views);
}
foreach ([[0,2147483647],[2147483647,2147483647]] as [$votes,$views]) {
    $row=['ID'=>101,'TITLE'=>'Viewed fixture','LENGTH'=>1,'TWOPART_ID'=>null,'WANNA_WATCH'=>$votes,'TIMES_WATCHED'=>$views];
    $normalized=EpisodeCatalog::normalize([$row]);
    check(is_finite($normalized[0]['weight']) && $normalized[0]['weight']>0,'large DB counters positive finite');
    check(count(Domain\PlaylistSelector::select($normalized,1,fn()=>0.5))===1,'viewed story remains available');
}
echo "ALL PASS\n";
