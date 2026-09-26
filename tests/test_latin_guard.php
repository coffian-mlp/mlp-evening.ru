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

echo "\n";
if ($fail > 0) { echo "FAIL: $fail\n"; exit(1); }
echo "ALL PASS\n";
