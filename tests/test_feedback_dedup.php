<?php
require __DIR__.'/../autoload.php';
$fail=0;
function verifyFeedback($ok,$label){global $fail;echo ($ok?'[OK] ':'[FAIL] ').$label."\n";if(!$ok)$fail++;}
$normal=new ReflectionMethod(Domain\FeedbackManager::class,'canonicalText');
$canonical=static fn($text)=>$normal->invoke(null,$text);
verifyFeedback($canonical("  ПОЧИНИТЬ\n\t поиск \u{00A0} эпизода ") === 'починить поиск эпизода','Unicode case and whitespace canonical form');
verifyFeedback($canonical('ЁЖ') === $canonical('ёж'),'Cyrillic case equal');
verifyFeedback($canonical('все') !== $canonical('всё'),'е/ё remain different');
verifyFeedback($canonical('не менять поиск') !== $canonical('менять поиск'),'negation preserved');
verifyFeedback($canonical('поиск?') !== $canonical('поиск!'),'punctuation preserved');
verifyFeedback($canonical("\u{00A0}\n\t") === '','Unicode whitespace-only empty');
verifyFeedback($canonical('fix search') !== $canonical('repair search'),'different wording not semantically merged');
echo $fail?"FAIL: $fail\n":"ALL PASS\n";exit($fail?1:0);
