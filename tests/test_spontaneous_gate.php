<?php
use LLM\JobQueue;
use LLM\LLMManager;
/**
 * Юнит-тест гейта спонтанных реплик (MLP-355): спонтанка молчит, пока Лира вот-вот ответит
 * на упоминание, команду, приветствие или стрим-команду (26.09: двойной ответ на «/мятный»).
 * Pure, без БД. Поведение запроса по возрасту — integration_spontaneous_gate.php.
 *
 * Запуск: php tests/test_spontaneous_gate.php
 */
require_once __DIR__ . '/../autoload.php';

$fail = 0;
function ok($cond, $label) {
    global $fail;
    echo ($cond ? "  [OK] " : "  [FAIL] ") . $label . "\n";
    if (!$cond) $fail++;
}

echo "== Кто глушит спонтанку ==\n";
foreach (['mention', 'dynamic_command', 'greeting', 'stream_command'] as $t) {
    ok(in_array($t, JobQueue::ANSWER_TYPES, true), "ждущий {$t} — Лира вот-вот ответит");
}
ok(!in_array('cron_spontaneous', JobQueue::ANSWER_TYPES, true), 'сама спонтанка не в списке — иначе глушила бы себя вечно');
ok(!in_array('memory_scribe', JobQueue::ANSWER_TYPES, true), 'фоновая автопись памяти не глушит');
ok(array_diff(JobQueue::ANSWER_TYPES, JobQueue::REACTIVE_TYPES) === [], 'все типы — реактивные');

echo "\n== Напоминание о языке ==\n";
ok(mb_strpos(LLMManager::LANG_REMINDER, 'только по-русски') !== false, 'требование языка');
ok(mb_strpos(LLMManager::LANG_REMINDER, 'звукоподражаний') !== false, 'и звукоподражаний («sniff-sniff», 26.09)');

echo "\n";
if ($fail > 0) { echo "FAIL: $fail\n"; exit(1); }
echo "ALL PASS\n";
