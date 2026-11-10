<?php
use LLM\ResponseSanitizer;
/**
 * Юнит-тест стража латиницы (MLP-356): какие латинские слова в реплике Лиры считаются чужими.
 * Разрешены слова из реплик людей, данных контекста и ников; прошлые реплики самой Лиры
 * не легитимируют (26.09: «sniff-sniff» скопирован из её же ответа). Pure, без БД.
 *
 * Запуск: php tests/test_latin_guard.php
 */
require_once __DIR__ . '/../autoload.php';

$fail = 0;
function ok($cond, $label) {
    global $fail;
    echo ($cond ? "  [OK] " : "  [FAIL] ") . $label . "\n";
    if (!$cond) $fail++;
}

echo "== Что считается латинским словом ==\n";
ok(ResponseSanitizer::latinWords('мятный кролик sniff-sniff!') === ['sniff'], '«sniff-sniff» — одно слово sniff');
ok(ResponseSanitizer::latinWords('@Wellerman, привет') === [], '@упоминание не считается');
ok(ResponseSanitizer::latinWords('напиши /нарисуй и /schedule') === [], '/команды не считаются');
ok(ResponseSanitizer::latinWords('смотри https://example.com/phoenix-wright.png') === [], 'ссылки не считаются');
ok(ResponseSanitizer::latinWords('SG-1, OBS, PHP и XD') === [], 'аббревиатуры и короткое не считаются');
ok(ResponseSanitizer::latinWords('Phoenix Wright и phoenix') === ['phoenix', 'wright'], 'без повторов, в нижнем регистре');
ok(ResponseSanitizer::latinWords('только по-русски, ёжик') === [], 'кириллица — пусто');

echo "\n== Чужая латиница ==\n";
ok(ResponseSanitizer::foreignLatin('@Wellerman, кролик sniff-sniff — и подтвердил!', []) === ['sniff'], 'прецедент 26.09: sniff — чужое');
ok(ResponseSanitizer::foreignLatin('@CoFFian reports на @Спамер', []) === ['reports'], 'прецедент: reports');
ok(ResponseSanitizer::foreignLatin('и ban с мутом снимаются', []) === ['ban'], 'прецедент: ban');
ok(ResponseSanitizer::foreignLatin('завтра Phoenix Wright!', ['phoenix', 'wright', 'ace']) === [], 'название из описания события — можно');
ok(ResponseSanitizer::foreignLatin('Darbel опять шутит', ['darbel']) === [], 'ник без @ — можно');
ok(ResponseSanitizer::foreignLatin('[РЕАКЦИЯ: laugh] Ха!', ['like', 'heart', 'laugh']) === [], 'код реакции из промпта — можно');
ok(ResponseSanitizer::foreignLatin('Чистый русский ответ.', []) === [], 'без латиницы — пусто');

echo "\n== Названия в ответах (MLP-458) ==\n";
$accepted = [
    'Ace Attorney — игра об адвокатах.',
    'Friendship is Magic — название сериала.',
    'The Return of Harmony — название серии.',
    'Советую «Minecraft».', 'Советую "a strange title".',
    'Название “Friendship is Magic”.', 'Название „Ace Attorney“.',
    'Серия The Return of Harmony мне нравится.',
    'Книга The Lord of the Rings известна.',
    "Название «strange\ntitle».", "Название \"strange\ntitle\".",
    "Название “strange\ntitle”.", "Название „strange\ntitle“.",
];
foreach ($accepted as $text) ok(ResponseSanitizer::foreignLatinInReply($text, []) === [], 'название допустимо');
$rejected = [
    '@Wellerman, кролик sniff-sniff — и подтвердил!' => ['sniff'],
    '@CoFFian reports на @Спамер' => ['reports'],
    'и ban с мутом снимаются' => ['ban'],
    'Тут random слово.' => ['random'],
    'Советую Minecraft для совместной игры.' => ['minecraft'],
    'Привет, *Sniff-sniff* я здесь.' => ['sniff'],
    '🙂 Random русский ответ.' => ['random'],
    ': Random русский ответ.' => ['random'],
    'Тут myMinecraft.' => ['myminecraft'],
    'Тут myMinecraft Attorney.' => ['myminecraft', 'attorney'],
    'Тут myGrand Theft Auto.' => ['mygrand'],
    'Тут «Minecraft», но myMinecraft целиком чужое.' => ['myminecraft'],
    'Тут «Minecraft», но Minecraft вне кавычек чужое.' => ['minecraft'],
    'Random слово в начале.' => ['random'],
    "Первая строка.\nRandom на новой строке." => ['random'],
    'Русская фраза! Random в новом предложении.' => ['random'],
    'Русская фраза. «Random без закрытия.' => ['random'],
    '*sniff-sniff* Русский ответ.' => ['sniff'],
    '*Random* Русский ответ.' => ['random'],
    'Он сказал: «Завершено.» Random в новом предложении.' => ['random'],
    'Завершено.) Random в новом предложении.' => ['random'],
    'Ace reports Attorney — случайное слово между именами.' => ['ace', 'reports', 'attorney'],
    'Советую «Minecraft», но minecraft вне названия чужое.' => ['minecraft'],
    'Тут «ban», но ban вне кавычек чужое.' => ['ban'],
    'The return of harmony — нижний регистр не название.' => ['the', 'return', 'harmony'],
];
foreach ($rejected as $text => $expected) ok(ResponseSanitizer::foreignLatinInReply($text, []) === $expected, 'постороннее слово сохраняется');
// A frozen MLP-356 oracle protects the shared parser and the summary decision.
function oldForeignLatin(string $text, array $allowed): array {
    $text = (string)preg_replace('~https?://\\S+~u', ' ', $text);
    preg_match_all("~(?<![@/\\w])[A-Za-z][A-Za-z'\\-]*~u", $text, $matches);
    $out = [];
    foreach ($matches[0] as $word) foreach (explode('-', $word) as $part) {
        $part = trim($part, "'");
        if (strlen($part) >= 3 && !preg_match('~^[A-Z]{3,5}$~', $part) && !in_array(strtolower($part), array_map('strtolower', $allowed), true)) $out[strtolower($part)] = true;
    }
    return array_keys($out);
}
$urls = [
    'https://example.test/?q="Minecraft"&lang=english',
    'https://example.test/#«Foreign»&lang=english',
    'https://example.test/?q=Minecraft&lang=english',
    'https://example.test/#Minecraft&foreign=word',
    'https://example.test/?q=Grand%20Theft%20Auto#Foreign&lang=english',
    'https://example.test/path?first=Title&second=Foreign#Last',
];
foreach ($urls as $url) ok(ResponseSanitizer::foreignLatinInReply($url, []) === [], 'ссылка полностью исключается');
$long = 'Title ' . str_repeat('of ', 8000) . 'random';
ok(strlen($long) >= 20000 && in_array('random', ResponseSanitizer::foreignLatinInReply($long, []), true), 'длинная цепочка сохраняет постороннее слово');
$fragments = array_merge($urls, $accepted, array_keys($rejected), ['@Nickname /command https://example.test/title', 'PHP OBS go to', 'Known name', 'Завершено.']);
$cases = 0; $oldAccepted = 0; $newAccepted = 0; $mismatch = 0; $sharedMismatch = 0; $summaryMismatch = 0;
$summary = new ReflectionMethod(LLM\ConversationMemoryPolicy::class, 'excessiveLatin');
foreach ($fragments as $first) foreach ($fragments as $second) foreach ([[], ['random', 'sniff'], ['ace', 'attorney', 'minecraft', 'the', 'return', 'harmony']] as $allowed) {
    $text = $first . ' ' . $second; $before = oldForeignLatin($text, $allowed); $after = ResponseSanitizer::foreignLatinInReply($text, $allowed); $cases++;
    if (!$before) { $oldAccepted++; if ($after) $mismatch++; }
    if (!$after) $newAccepted++;
    if (array_diff($after, $before)) $mismatch++;
    if (ResponseSanitizer::foreignLatin($text, $allowed) !== $before) $sharedMismatch++;
    // Independent frozen phase3 threshold, including occurrence count and six-word runs.
    $foreign = array_flip($before); $count = 0; $run = 0; $excessive = false;
    preg_match_all("~[@/]?\\p{L}+(?:['’]\\p{L}+)*~u", preg_replace('~https?://\\S+~u', ' ', $text), $words);
    foreach ($words[0] as $word) {
        $unknown = (bool)array_intersect(oldForeignLatin($word, []), array_keys($foreign));
        if ($unknown) { $count++; $run++; } else $run = 0;
        if ($run >= 6) $excessive = true;
    }
    $excessive = $excessive || $count * 10 > count($words[0]) * 3;
    if ($summary->invoke(null, $text, $allowed) !== $excessive) $summaryMismatch++;
}
echo "Property: $cases cases, $oldAccepted old accepted, $newAccepted new accepted, $mismatch violations; shared $sharedMismatch; summary $summaryMismatch\n";
ok($oldAccepted > 0 && $newAccepted > $oldAccepted && $mismatch === 0, 'строго более мягкое правило, без новых чужих слов');
ok($sharedMismatch === 0, 'общий foreignLatin побайтно соответствует старому правилу');
ok($summaryMismatch === 0, 'решения конспекта совпадают с независимым прежним порогом');

echo "\n";
if ($fail > 0) { echo "FAIL: $fail\n"; exit(1); }
echo "ALL PASS\n";
