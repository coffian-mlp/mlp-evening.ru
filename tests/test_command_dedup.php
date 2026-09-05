<?php
use LLM\CommandDedup;
/**
 * Юнит-тест CommandDedup (MLP-326): ключ дубля, поиск оригинала среди недавних задач,
 * текст уведомления. БД не нужна. Запуск: php tests/test_command_dedup.php
 */
require_once __DIR__ . '/../autoload.php';

$fail = 0;
function ok($cond, $label) {
    global $fail;
    echo ($cond ? "  [OK] " : "  [FAIL] ") . $label . "\n";
    if (!$cond) $fail++;
}
$draw     = ['id' => 26, 'command_prefix' => '/нарисуй', 'handler_type' => 'image'];
$drawChat = ['id' => 27, 'command_prefix' => '/нарисуйчат', 'handler_type' => 'image_chat'];
$sched1   = ['id' => 1, 'command_prefix' => '/schedule', 'handler_type' => 'schedule'];
$sched2   = ['id' => 2, 'command_prefix' => '/расписание', 'handler_type' => 'schedule'];
$bayar    = ['id' => 10, 'command_prefix' => '/баярунас', 'handler_type' => 'text'];
$poke     = ['id' => 7, 'command_prefix' => '/ткнуть', 'handler_type' => 'text'];

echo "== key ==\n";
ok(CommandDedup::key($bayar, '/баярунас') === CommandDedup::key($bayar, '  /Баярунас  '), 'регистр и пробелы не различают вызовы');
ok(CommandDedup::key($poke, '/ткнуть @Darbel') !== CommandDedup::key($poke, '/ткнуть @Пшеница'), 'разные аргументы — разные ключи');
ok(CommandDedup::key($draw, '/нарисуй кота') !== CommandDedup::key($draw, '/нарисуй собаку'), '/нарисуй с разными сюжетами — разные запросы');
ok(CommandDedup::key($draw, '/нарисуй  Кота') === CommandDedup::key($draw, '/нарисуй кота'), '/нарисуй с тем же сюжетом — дубль');
ok(CommandDedup::key($sched1, '/schedule') === CommandDedup::key($sched2, '/расписание пожалуйста'), 'алиасы расписания и аргументы — одна команда');
ok(CommandDedup::key($drawChat, '/нарисуйчат') === CommandDedup::key($drawChat, '/нарисуйчат срочно'), '/нарисуйчат игнорирует аргументы');
foreach (['todo', 'memory_add', 'memory_show', 'memory_forget', 'reminder', 'recap'] as $t) {
    ok(CommandDedup::key(['id' => 99, 'command_prefix' => '/x', 'handler_type' => $t], '/x') === null, "персональный тип $t не дедупится");
}
ok(CommandDedup::key(['command_prefix' => '/правила', 'handler_type' => 'text'], '/правила') === 'cmd:/правила|', 'без id — ключ по префиксу');
ok(CommandDedup::key(['handler_type' => 'text'], 'что-то') === null, 'без id и префикса — не дедупится');

echo "\n== findOriginal ==\n";
$key = CommandDedup::key($draw, '/нарисуй кота');
$jobs = [
    ['status' => 'pending', 'age' => 2,  'data' => ['command' => $draw, 'message' => '/нарисуй кота', 'username' => 'Wellerman', 'dedup_of' => ['username' => 'CoFFian']]],
    ['status' => 'failed',  'age' => 9,  'data' => ['command' => $draw, 'message' => '/нарисуй кота', 'username' => 'Darbel']],
    ['status' => 'done',    'age' => 30, 'data' => ['command' => $draw, 'message' => '/нарисуй собаку', 'username' => 'Пшеница']],
    ['status' => 'done',    'age' => 41, 'data' => ['command' => $draw, 'message' => '/нарисуй Кота', 'username' => 'CoFFian']],
];
$o = CommandDedup::findOriginal($jobs, $key);
ok($o === ['username' => 'CoFFian', 'status' => 'done', 'age' => 41], 'оригинал: не уведомление, не failed, тот же ключ: ' . json_encode($o, JSON_UNESCAPED_UNICODE));
ok(CommandDedup::findOriginal($jobs, CommandDedup::key($draw, '/нарисуй лису')) === null, 'нет совпадения → null');
$pending = CommandDedup::findOriginal([['status' => 'processing', 'age' => 5, 'data' => ['command' => $sched2, 'message' => '/расписание', 'username' => 'Darbel']]], CommandDedup::key($sched1, '/schedule'));
ok($pending !== null && $pending['status'] === 'processing', 'processing по алиасу — оригинал найден');
ok(CommandDedup::findOriginal([], $key) === null, 'пустой список → null');

echo "\n== notice ==\n";
[$ins, $fb] = CommandDedup::notice($draw, ['username' => 'CoFFian', 'status' => 'done', 'age' => 41], 'Darbel');
ok(str_contains($ins, '@Darbel') && str_contains($ins, '/нарисуй') && str_contains($ins, '@CoFFian') && str_contains($ins, 'рисунок уже готов'), 'done+image: кто, что, готово');
ok(str_contains($fb, 'смотри чуть выше'), 'запасная фраза для done');
[$ins2, $fb2] = CommandDedup::notice($sched2, ['username' => 'Darbel', 'status' => 'pending', 'age' => 3], 'Darbel');
ok(str_contains($ins2, '@Darbel сам(а)') && str_contains($ins2, 'расписание уже готовится'), 'тот же пользователь + pending');
ok(str_contains($fb2, 'уже в работе'), 'запасная фраза для pending');
[$ins3] = CommandDedup::notice($sched1, ['username' => '', 'status' => 'done', 'age' => 20], 'Пшеница');
ok(str_contains($ins3, 'я сама (анонс по расписанию)'), 'оригинал без пользователя — анонс воркера');
ok(str_contains($ins, 'Повторно ничего не делай'), 'запрет повторной работы в инструкции');

echo "\n" . ($fail === 0 ? "ALL PASS\n" : "FAILURES: $fail\n");
exit($fail === 0 ? 0 : 1);
