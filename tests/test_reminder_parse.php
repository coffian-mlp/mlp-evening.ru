<?php
/**
 * MLP-318 (юнит, pure): ReminderCommand::parseReply — строгий разбор ответа
 * LLM-парсера напоминаний; humanTimeMsk — человеческое время.
 *
 * Запуск: php tests/test_reminder_parse.php
 */

require_once __DIR__ . '/../autoload.php';

use LLM\ReminderCommand;

$fail = 0;
function ok($cond, string $label): void {
    global $fail;
    if ($cond) { echo "  [OK] $label\n"; }
    else { echo "  [FAIL] $label\n"; $fail++; }
}

$p = ReminderCommand::parseReply('REMIND|3600|достать колу из холодильника');
ok($p && $p['intent'] === 'remind' && $p['seconds'] === 3600 && $p['text'] === 'достать колу из холодильника', 'REMIND: секунды и текст');

$p = ReminderCommand::parseReply('REMIND_AT|2026-08-23 19:30|про стрим');
ok($p && $p['intent'] === 'remind_at' && $p['at'] === '2026-08-23 19:30' && $p['text'] === 'про стрим', 'REMIND_AT: дата и текст');

ok(ReminderCommand::parseReply('LIST')['intent'] === 'list', 'LIST');
$p = ReminderCommand::parseReply('CANCEL|№7');
ok($p && $p['intent'] === 'cancel' && $p['id'] === 7, 'CANCEL с №');
$p = ReminderCommand::parseReply('CANCEL| 12');
ok($p && $p['id'] === 12, 'CANCEL с пробелом');

ok(ReminderCommand::parseReply('NONE') === null, 'NONE -> null');
ok(ReminderCommand::parseReply('') === null, 'пусто -> null');
ok(ReminderCommand::parseReply(null) === null, 'null -> null');
ok(ReminderCommand::parseReply('Конечно! Вот: как скажешь') === null, 'болтовня без формата -> null');
ok(ReminderCommand::parseReply('REMIND|abc|текст') === null, 'кривые секунды -> null');
ok(ReminderCommand::parseReply('REMIND|60|') === null, 'пустой текст -> null');

$p = ReminderCommand::parseReply("Хорошо, разобрала:\nREMIND|120|позвонить Бон-Бон\nНадеюсь, помогла!");
ok($p && $p['intent'] === 'remind' && $p['seconds'] === 120, 'валидная строка среди болтовни находится');

// humanTimeMsk: 12:00 UTC = 15:00 МСК
$now = strtotime('2026-08-22 10:00:00 UTC');
ok(ReminderCommand::humanTimeMsk('2026-08-22 12:00:00', $now) === 'сегодня в 15:00', 'humanTimeMsk: сегодня');
ok(ReminderCommand::humanTimeMsk('2026-08-23 06:30:00', $now) === 'завтра в 09:30', 'humanTimeMsk: завтра');
ok(ReminderCommand::humanTimeMsk('2026-08-25 07:00:00', $now) === '25.08 в 10:00', 'humanTimeMsk: дата');

// wants — только триггер-слово без обращения не срабатывает (pure-часть проверяется интеграционно с опцией)
if ($fail === 0) { echo "ALL PASS\n"; exit(0); }
echo "FAILED: $fail\n"; exit(1);
