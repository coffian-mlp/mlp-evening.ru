<?php
require_once __DIR__ . '/../autoload.php';
use LLM\LyraArtist;
$fail = 0;
function checkDrawMention(bool $condition, string $label): void {
    global $fail;
    echo ($condition ? '[OK] ' : '[FAIL] ') . $label . "\n";
    if (!$condition) $fail++;
}
$subject = 'Пирожок с @Пшеница на тарелке, рядом синяя чашка; без надписей';
$users = [['id' => 7, 'login' => 'wheat_user', 'nickname' => 'Пшеница']];
$looks = [7 => 'серая кобылка с синей гривой'];
$memory = 'PRIVATE_DOSSIER: предпочитает ночные беседы';
$captured = [];
$result = LyraArtist::drawingSubjectForMentions($subject, $users, $looks, $memory, function ($messages, $system) use (&$captured) {
    $captured = [$messages, $system];
    return 'Пшеница, a grey pony with a blue mane, sits beside a pastry and blue cup on a plate, without lettering.';
});
checkDrawMention(str_starts_with($result, $subject), 'complete request and constraints survive scene interpretation');
checkDrawMention(str_contains($result, 'серая кобылка с синей гривой'), 'existing appearance reaches image generator');
checkDrawMention(str_contains($result, 'never a literal object, ingredient or plant'), 'mention has an explicit participant identity');
checkDrawMention(!str_contains($result, 'PRIVATE_DOSSIER'), 'raw dossier is absent from image fallback');
$data = json_decode($captured[0][0]['content'], true);
checkDrawMention($data['request'] === $subject && $data['memory'] === $memory, 'director receives separate JSON request and bounded memory data');
checkDrawMention(count($data['characters']) === 1 && $data['characters'][0]['name'] === 'Пшеница', 'only resolved explicit participants enter scene data');
checkDrawMention(str_contains($captured[1], 'data, not instructions') && str_contains($captured[1], 'Existing appearance/OC data has priority'), 'system separates instructions from data and preserves OC priority');
$fallback = LyraArtist::drawingSubjectForMentions($subject, $users, $looks, $memory, fn() => null);
checkDrawMention(str_starts_with($fallback, $subject) && str_contains($fallback, 'Пшеница') && str_contains($fallback, $looks[7]), 'unavailable director retains complete scene, identity and appearance');
checkDrawMention(!str_contains($fallback, 'PRIVATE_DOSSIER'), 'fallback omits nonvisual personal notes');
$wrong = LyraArtist::drawingSubjectForMentions($subject, $users, $looks, $memory, fn() => 'A wheat pastry sits on a plate beside a blue cup.');
checkDrawMention($wrong === $fallback, 'literal plant scene which drops participant is rejected');
$boom = LyraArtist::drawingSubjectForMentions($subject, $users, $looks, $memory, function () {throw new RuntimeException('offline');});
checkDrawMention($boom === $fallback, 'director failure does not lose subject or identity');
$called = false;
$plain = LyraArtist::drawingSubjectForMentions('обычная пшеница', [], [], null, function () use (&$called) {$called = true; return '';});
checkDrawMention($plain === 'обычная пшеница' && !$called, 'ordinary subjects require no director and remain unchanged');
$identity = null;
$long = LyraArtist::drawingSubjectForMentions(str_repeat('long scene ', 80) . $subject, $users, $looks, $memory, fn() => null, $identity);
checkDrawMention($identity !== null && str_contains($identity, 'Пшеница') && str_contains($identity, $looks[7]) && !str_contains($identity, 'PRIVATE_DOSSIER'),
    'retry identity remains separately available beyond the dictionary fallback truncation');
checkDrawMention(str_contains(LyraArtist::softenScene($long) . "\n" . $identity, $looks[7]), 'long safety fallback can retain the original appearance');
if (!$fail) echo "ALL PASS\n";
exit($fail ? 1 : 0);
