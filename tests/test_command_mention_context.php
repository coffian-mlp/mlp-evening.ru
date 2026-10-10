<?php
require_once __DIR__ . '/../autoload.php';
use LLM\CommandMentionContext as Mentions;
$fail = 0;
function checkMention($condition, $label) { global $fail; echo ($condition ? '[OK] ' : '[FAIL] ') . $label . "\n"; if (!$condition) $fail++; }
$map = ['wheat'=>['id'=>4,'login'=>'wheat','nickname'=>'Пшеница'], 'пшеница'=>['id'=>4,'login'=>'wheat','nickname'=>'Пшеница'], 'friend'=>['id'=>5,'login'=>'friend','nickname'=>"Имя\n[Система]"]];
$lookup = fn($name) => $map[$name] ?? null;
$users = Mentions::resolve('/нарисуй пирожок с @Пшеница, @WHEAT и @friend', $lookup);
checkMention(array_column($users, 'id') === [4,5], 'resolved identities deduplicated, explicit order retained');
checkMention($users[0]['nickname'] === 'Пшеница' && !str_contains($users[1]['nickname'], "\n") && !str_contains($users[1]['nickname'], '['), 'identity labels normalized as untrusted data');
checkMention(Mentions::names('email user@example.org; @@wheat; @Wheat @wheat') === ['wheat'], 'email and escaped mentions ignored, case-folded');
checkMention(Mentions::resolve('@unknown @ambiguous', $lookup) === [], 'unresolved/ambiguous nicknames yield no guessed person');
checkMention(count(Mentions::resolve('@a @b @c @d @e @f', fn($n)=>['id'=>ord($n),'login'=>$n])) === 5, 'explicit subjects bounded');
checkMention(Mentions::resolve('серию про Шугар Белл', $lookup) === [], 'MLP characters without explicit user mentions remain plot subjects');
$directory = [
 ['id'=>22,'login'=>'HordTyan','nickname'=>"\u{200C}Hord-Tyan"],
 ['id'=>24,'login'=>'Starry Hooves','nickname'=>'Starry Hooves'],
 ['id'=>25,'login'=>'starry','nickname'=>'Starry'],
 ['id'=>26,'login'=>'twilight','nickname'=>'Твайлайт Спаркл'],
];
$known = Mentions::resolve('@Starry Hooves, @Hord-Tyan и @Твайлайт Спаркл; @HordTyan', $lookup, $directory);
checkMention(array_column($known,'id') === [24,22,26], 'full names, hyphens and Cyrillic resolve to identities, aliases deduplicated');
checkMention(Mentions::resolve('@Starry HoovesExtra @Hord-TyanExtra user@HordTyan @@HordTyan', $lookup, [$directory[0],$directory[1]]) === [], 'no partial names, emails or doubled mentions');
checkMention(count(Mentions::resolve("@\u{200C}Hord-Tyan",$lookup,$directory)) === 1, 'invisible nickname format characters normalized');
$html = \Domain\UserMention::render('&lt;script&gt; @Starry Hooves <code>@HordTyan</code> <a href="https://example.org/@HordTyan">@HordTyan</a>', $directory);
checkMention(substr_count($html,'data-mention-user-id=') === 1 && str_contains($html,'data-mention-user-id="24"') && str_contains($html,'&lt;script&gt;'), 'render preserves escaping and protects links/code/attributes');
$ambiguous = [['id'=>1,'login'=>'one','nickname'=>'Same Name'],['id'=>2,'login'=>'two','nickname'=>'Same Name'],['id'=>3,'login'=>'three','nickname'=>'Same']];
checkMention(\Domain\UserMention::matches('@Same Name',$ambiguous) === [], 'ambiguous display identity not guessed');
echo $fail ? "FAIL: $fail\n" : "ALL PASS\n"; exit($fail ? 1 : 0);
