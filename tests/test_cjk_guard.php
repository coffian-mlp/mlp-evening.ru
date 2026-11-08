<?php
require_once __DIR__ . '/../autoload.php';
use LLM\CjkGuard;
use LLM\ResponseSanitizer;
$fail = 0;
function checkCjk(bool $condition, string $name): void {
    global $fail;
    echo ($condition ? '[OK] ' : '[FAIL] ') . $name . "\n";
    if (!$condition) $fail++;
}
$examples = ['неделю —佩服, три сезона', 'взглядом目前已经 уже кушать', 'час на冷 и в кровать', 'нам纯粹ную ночь',
    'а受访 испытуемых', 'расписанием 已经 выдало', 'моё个人 созвездие', 'нюх足足 — чуть не съела', '当然 сделаю', 'толком出现在 в кадре', '法庭', '悔'];
foreach ($examples as $text) {
    checkCjk(CjkGuard::foreign($text) !== [], 'detect real fragment: ' . $text);
    checkCjk(CjkGuard::foreign(CjkGuard::strip($text)) === [], 'strip real fragment: ' . $text);
}
foreach ([0x3400,0x4e00,0x9fff,0xf900,0x20000,0x2ee5f,0x2f800,0x30000,0x323af,0x3042,0x30ab,0x31f0,0xff76,0x1aff0,0x1b000,0x1b150,0xffa1,0x1100,0x3130,0xa960,0xac00,0xd7b0] as $code) {
    checkCjk(CjkGuard::foreign('до' . mb_chr($code) . 'после') !== [], 'range U+' . dechex($code));
}
// Ideographic and kana iteration/numeral signs are language-bearing, not punctuation.
foreach (array_merge(range(0x3005,0x3007),range(0x3021,0x3029),range(0x3038,0x303b),range(0x3031,0x3035)) as $code) {
    $sign=mb_chr($code);
    checkCjk(CjkGuard::foreign('Вечер '.$sign.' прекрасный.')===[$sign], 'detect ideographic/kana sign U+'.dechex($code));
    checkCjk(CjkGuard::strip('Вечер '.$sign.' прекрасный.')==='Вечер прекрасный.', 'strip sign U+'.dechex($code));
    $quotes=CjkGuard::quoted('Объясни «'.$sign.'».');
    checkCjk($quotes===[$sign] && CjkGuard::foreign($sign,$quotes)===[], 'quoted sign allowed U+'.dechex($code));
}
$clean = ResponseSanitizer::clean('Я &#x6094; и &#27861;&#24237; люблю ёжиков.');
checkCjk(CjkGuard::foreign($clean) === ['悔','法庭'], 'detect after entity decoding');
checkCjk(CjkGuard::strip('  Привет 悔 ，  мир。  ') === 'Привет, мир.', 'strip normalizes spaces and punctuation');
checkCjk(CjkGuard::normalize('「Привет」　ＡＢＣ！？') === '«Привет» ABC!?', 'CJK quotes, spaces and fullwidth ASCII');
checkCjk(CjkGuard::normalize('Цена ￥５， знак ￡ и ﹖') === 'Цена ¥5, знак £ и ﹖', 'fullwidth currency; unrelated forms untouched');
foreach (['Русский ёжик','Phoenix Wright, OBS, PHP','🦄 👩‍💻 🏳️‍🌈','@小明 /検索 https://例子.test/法庭?q=悔','HTTPS://例子.test/法庭?q=悔','/команда_法庭','@CoFFian, привет!'] as $text) {
    checkCjk(CjkGuard::foreign($text) === [], 'negative/protected: ' . $text);
    checkCjk(CjkGuard::strip($text) === $text, 'protected bytes: ' . $text);
}
$allowed = CjkGuard::quoted('Что значит «法庭», "悔" и “かな”? Не цитата: 冷');
checkCjk($allowed === ['法庭','悔','かな'], 'only explicitly quoted fragments permitted');
checkCjk(CjkGuard::foreign('法庭, 悔 и かな', $allowed) === [], 'exact current quotations allowed');
checkCjk(CjkGuard::foreign('法庭外 冷', $allowed) === ['法庭外','冷'], 'allowed fragment does not exempt larger CJK word');
checkCjk(CjkGuard::foreign('Здравствуй, 小明!', ['小明']) === [], 'bare known nickname allowed');
checkCjk(CjkGuard::strip('法庭 и 冷', $allowed) === '法庭 и', 'strip retains only exact allowed fragment');
checkCjk(CjkGuard::normalize('https://例子.test/Ａ，Ｂ @小明 /検索') === 'https://例子.test/Ａ，Ｂ @小明 /検索', 'normalization preserves identifiers and URL');
checkCjk(ResponseSanitizer::foreignLatin('reports 悔', []) === ['reports'], 'Latin guard remains independent');
checkCjk(!ResponseSanitizer::isMeaningful(CjkGuard::strip('悔!')), 'CJK-only output becomes fallback candidate');
echo $fail ? "FAIL: $fail\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
