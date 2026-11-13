<?php
/** Server address names must preserve mention identity. */
require_once dirname(__DIR__) . '/autoload.php';
use Domain\UserMention;
$failed = 0; $total = 0;
$check = function (bool $ok, string $label) use (&$failed, &$total) {
    $total++; $failed += (int)!$ok;
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . "\n";
};
$actor = ['id'=>1, 'login'=>'address_actor', 'nickname'=>'Новый Ник'];
$other = ['id'=>2, 'login'=>'address_other', 'nickname'=>'Другой'];
$name = static fn($user, $directory) => method_exists(UserMention::class, 'addressName') ? UserMention::addressName($user, $directory) : $user['login'];
foreach (['Ник', 'Новый Ник', 'Nick.Name!', 'Пони-★', " \u{200B}Ник "] as $nick) {
    $user = array_replace($actor, ['nickname'=>$nick]); $directory = [$user, $other];
    $selected = $name($user, $directory);
    $check($selected === UserMention::normalize($nick), 'safe nickname selected: ' . json_encode($nick));
    $matches = UserMention::matches('@' . $selected . ', привет!', $directory);
    $check(count($matches) === 1 && (int)$matches[0]['user']['id'] === 1, 'selected mention resolves to same id');
}
foreach (['', '   ', "\u{200B}", 'bad@name', '<name>', "line\nbreak", "line\rbreak"] as $nick) {
    $user = array_replace($actor, ['nickname'=>$nick]);
    $check($name($user, [$user,$other]) === $actor['login'], 'empty or unsafe nickname falls back to login');
}
foreach (['другой', 'ADDRESS_OTHER', "Дру\u{200B}гой"] as $nick) {
    $user = array_replace($actor, ['nickname'=>$nick]);
    $check($name($user, [$user,$other]) === $actor['login'], 'case insensitive alias collision falls back to login');
}
$user = array_replace($actor, ['nickname'=>'ADDRESS_ACTOR']);
$check($name($user, [$user,$other]) === 'ADDRESS_ACTOR', 'own login is not a collision');
$canonical = static fn($text,$directory) => method_exists(UserMention::class,'addressMentions') ? UserMention::addressMentions($text,$directory) : $text;
$check($canonical('@address_actor, привет @address_other!', [$actor,$other]) === '@Новый Ник, привет @Другой!', 'ordinary generated mentions use current names');
$check($canonical('mail address_actor@example.org /@address_actor', [$actor,$other]) === 'mail address_actor@example.org /@address_actor', 'email and path are preserved');
foreach (['`@address_actor`', "```\n@address_actor\n```", '[ссылка](https://example.org/?user=@address_actor)', '![рисунок](/upload/@address_actor.png)', '<https://example.org/?user=@address_actor>', 'https://example.org/?user=@address_actor'] as $protected) {
    $check($canonical($protected, [$actor,$other]) === $protected, 'code, links and URLs are not addresses');
}
$staff = [array_replace($actor, ['nickname'=>'Другой']), $other];
$check(LLM\ModerationCommand::staffToCall($staff, [1=>'admin',2=>'user'], [], $staff) === ['address_actor'], 'staff ping uses login for an ambiguous nickname');
echo $failed ? "FAIL: $failed/$total\n" : "ALL PASS ($total)\n";
exit($failed ? 1 : 0);
