<?php
use LLM\ResponseSanitizer;
/**
 * Юнит-тест ResponseSanitizer::clean() — guard против «прорыва системных сообщений» в чат.
 * Кейсы взяты из реальных прорвавшихся сообщений бота (chat_messages, user_id=12).
 *
 * Запуск:  php tests/test_clean_response.php
 */

require_once __DIR__ . '/../src/LLM/ResponseSanitizer.php';

$fail = 0;
function eq($got, $want, $label) {
    global $fail;
    if ($got === $want) { echo "  [OK] $label\n"; }
    else { echo "  [FAIL] $label\n        want: " . var_export($want, true) . "\n        got:  " . var_export($got, true) . "\n"; $fail++; }
}
function contains($got, $needle, $label) {
    global $fail;
    if (is_string($got) && mb_strpos($got, $needle) !== false) { echo "  [OK] $label\n"; }
    else { echo "  [FAIL] $label\n        expected to contain: " . var_export($needle, true) . "\n        got: " . var_export($got, true) . "\n"; $fail++; }
}

$nick = 'TotallyNotAPony';
$login = 'Lyra';

echo "== Прорывы должны вырезаться в '' ==\n";
// #21938 / #21960 (5 июля) — целиком инструкция-эхо
eq(ResponseSanitizer::clean('Отвечай просто как текст реплики. Поняла?', $nick, $login), '', 'whole leak: "Отвечай просто как текст реплики. Поняла?"');
eq(ResponseSanitizer::clean('Просто пиши текст реплики. Поняла?', $nick, $login), '', 'whole leak: "Просто пиши текст реплики. Поняла?"');
// #17190 (6 июня) — инструкция + дамп контекста
eq(ResponseSanitizer::clean("Не пиши никаких пояснений, системных сообщений, инструкций или мета-текста. Только чистый текст реплики.\n\nКонтекст:\n[17:26] Darbel: ...оп, я тут.\n[17:26] CoFFian: /баярунас", $nick, $login), '', 'instruction + Контекст dump');
// Прорыв текущего шаблона
eq(ResponseSanitizer::clean("[Системное правило]: Пиши ТОЛЬКО текст своего ответа. НИКОГДА не добавляй своё имя.", $nick, $login), '', 'current template "[Системное правило]..."');
eq(ResponseSanitizer::clean("[Система] Проанализируй последние сообщения и ответь.", $nick, $login), '', 'cron template "[Система] Проанализируй..."');

echo "\n== Префикс-прорыв: служебное срезать, полезный текст сохранить ==\n";
// #17773 (7 июня)
contains(ResponseSanitizer::clean('Пиши сразу текст реплики.Эй, @CoFFian, поаккуратнее там с моими мыслями!', $nick, $login), 'Эй, @CoFFian', 'prefix leak keeps real reply');
eq(mb_strpos(ResponseSanitizer::clean('Пиши сразу текст реплики.Эй, @CoFFian, поаккуратнее там с моими мыслями!', $nick, $login), 'текст реплики'), false, 'prefix leak: instruction removed');

echo "\n== Нормальные ответы не трогаем ==\n";
eq(ResponseSanitizer::clean('Привет! Мы тут любуемся на сирен, @CoFFian!', $nick, $login), 'Привет! Мы тут любуемся на сирен, @CoFFian!', 'normal reply unchanged');
eq(ResponseSanitizer::clean('SILENCE', $nick, $login), 'SILENCE', 'SILENCE preserved (no leak signal)');
eq(ResponseSanitizer::clean('[15:22] TotallyNotAPony: Всем привет!', $nick, $login), 'Всем привет!', 'strip "[HH:MM] Ник:" prefix');
eq(ResponseSanitizer::clean('TotallyNotAPony: Как дела?', $nick, $login), 'Как дела?', 'strip bot nickname prefix');
eq(ResponseSanitizer::clean('"В кавычках"', $nick, $login), 'В кавычках', 'unwrap surrounding quotes');
eq(ResponseSanitizer::clean('Скажи &quot;привет&quot;', $nick, $login), 'Скажи "привет"', 'decode html entities');

echo "\n== Граничные ==\n";
eq(ResponseSanitizer::clean(null, $nick, $login), null, 'null -> null');
eq(ResponseSanitizer::clean('   ', $nick, $login), '', 'blank -> ""');

echo "\n" . ($fail === 0 ? "ALL PASS\n" : "FAILURES: $fail\n");
exit($fail === 0 ? 0 : 1);
