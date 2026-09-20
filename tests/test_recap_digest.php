<?php
use LLM\RecapCommand;
/**
 * Юнит-тест RecapCommand (MLP-325, /штош): дайджест реплик пользователя для промпта и инструкция.
 * TZ намеренно America/New_York (как на проде) — таймкоды дайджеста должны быть МСК.
 *
 * Запуск: php tests/test_recap_digest.php
 */
require_once __DIR__ . '/../autoload.php';
date_default_timezone_set('America/New_York');

$fail = 0;
function ok($cond, $label) {
    global $fail;
    echo ($cond ? "  [OK] " : "  [FAIL] ") . $label . "\n";
    if (!$cond) $fail++;
}
$rows = [
    ['id' => 1, 'message' => '/штош', 'created_at' => '2026-09-05 16:10:00'],
    ['id' => 2, 'message' => '&quot;Пришлите нормальный кофе&quot;', 'created_at' => '2026-09-05 19:46:33'],
    ['id' => 3, 'message' => "![изображение.png](/upload/chat/x.png)", 'created_at' => '2026-09-05 19:55:29'],
    ['id' => 4, 'message' => "[[poll:7]]", 'created_at' => '2026-09-05 19:56:00'],
    ['id' => 5, 'message' => "первая строка\n   вторая   строка", 'created_at' => '2026-09-05 19:57:00'],
    ['id' => 6, 'message' => str_repeat('оченьдлинно ', 40), 'created_at' => '2026-09-05 19:58:00'],
];

echo "== digest: фильтры и формат ==\n";
$d = RecapCommand::digest($rows);
$lines = explode("\n", $d);
ok(!str_contains($d, '/штош'), 'команды боту в дайджест не попадают');
ok($lines[0] === '[22:46] "Пришлите нормальный кофе"', 'HTML-сущности декодированы, время МСК: ' . $lines[0]);
ok($lines[1] === '[22:55] (картинка)', 'markdown-картинка → плейсхолдер');
ok($lines[2] === '[22:56] (опрос)', 'тег опроса → плейсхолдер');
ok($lines[3] === '[22:57] первая строка вторая строка', 'многострочность схлопнута в одну строку');
ok(mb_strlen($lines[4]) <= RecapCommand::LINE_CHARS + 8 && str_ends_with($lines[4], '…'), 'длинная реплика усечена с многоточием');
ok(count($lines) === 5, 'пять строк из шести сообщений (команда отброшена): ' . count($lines));

echo "\n== digest: бюджеты (свежие важнее) ==\n";
$d2 = RecapCommand::digest($rows, 2, 4000);
ok(substr_count($d2, "\n") === 1 && str_starts_with($d2, '[22:57]'), 'maxMessages=2 → две самые свежие');
$d3 = RecapCommand::digest(array_slice($rows, 1, 3), 80, 60);
ok($d3 === "[22:55] (картинка)\n[22:56] (опрос)", 'бюджет символов режет старые первыми: ' . json_encode($d3, JSON_UNESCAPED_UNICODE));
ok(RecapCommand::digest([]) === '', 'пусто → пустая строка');
ok(RecapCommand::digest([['message' => '/todo идея', 'created_at' => '2026-09-05 19:00:00']]) === '', 'только команды → пустая строка');

echo "\n== instruction ==\n";
$ins = RecapCommand::instruction('CoFFian', $d, 'Тон — тёплый.');
ok(str_contains($ins, '@CoFFian') && str_contains($ins, 'последние 8 часов'), 'адресат и окно в инструкции');
ok(str_contains($ins, $d), 'дайджест вложен целиком');
ok(str_contains($ins, "Тон — тёплый.\nТВОЯ ЗАДАЧА"), 'промпт команды — перед задачей');
$empty = RecapCommand::instruction('Darbel', '', '');
ok(str_contains($empty, 'реплик за это время нет'), 'без реплик — явная пометка');
ok(!str_contains($empty, "\n\n\nТВОЯ"), 'пустой промпт команды не оставляет дыр');

echo "\n== parseTarget / инструкция за другого (MLP-345) ==\n";
ok(RecapCommand::parseTarget('@Пшеница') === 'Пшеница', 'ник с @');
ok(RecapCommand::parseTarget('  @Darbel, давай') === 'Darbel', 'ник с хвостом');
ok(RecapCommand::parseTarget('') === null && RecapCommand::parseTarget('просто текст') === null && RecapCommand::parseTarget('@') === null, 'без @ / пусто → null');
$for = RecapCommand::instruction('Пшеница', '[22:00] кек', '', 'CoFFian');
ok(str_contains($for, 'Модератор @CoFFian') && str_contains($for, 'за @Пшеница') && str_contains($for, 'Реплики @Пшеница'), 'инструкция за другого называет заказчика и цель');
ok(str_starts_with(RecapCommand::instruction('CoFFian', '', ''), 'Пользователь @CoFFian командой /штош'), 'без заказчика — прежняя формулировка');

echo "\n== независимость от TZ ==\n";
date_default_timezone_set('UTC');
ok(RecapCommand::digest($rows) === $d, 'дайджест одинаков под America/New_York и UTC');

echo "\n" . ($fail === 0 ? "ALL PASS\n" : "FAILURES: $fail\n");
exit($fail === 0 ? 0 : 1);
