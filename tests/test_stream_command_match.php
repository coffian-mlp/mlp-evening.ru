<?php
use LLM\StreamCommand;
/**
 * Юнит-тест матчеров StreamCommand — MLP-307.
 * Чистые методы: обращение к боту, известная команда, запрос перечня команд.
 * (wants() читает опции из БД — проверяется интеграционным тестом.)
 *
 * Запуск: php tests/test_stream_command_match.php
 */

if (!file_exists(__DIR__ . '/../.env') && !file_exists(__DIR__ . '/../config.php')) {
    echo "SKIP: нет .env/config.php (ConfigManager нужен для алиасов)\n";
    exit(0);
}

require_once __DIR__ . '/../autoload.php';

$fail = 0;
function ok($cond, $label) {
    global $fail;
    echo ($cond ? "  [OK] " : "  [FAIL] ") . $label . "\n";
    if (!$cond) $fail++;
}

echo "== Обращение к боту ==\n";
ok(StreamCommand::addressesBot('Лира, включи перерыв'), 'по имени');
ok(StreamCommand::addressesBot('лира кино'), 'нижний регистр без запятой');
ok(StreamCommand::addressesBot('@Лира, кино'), 'с собакой');
ok(StreamCommand::addressesBot('Клод, включи кино'), 'legacy-триггер «Клод»');
ok(!StreamCommand::addressesBot('а Лира уже включила кино'), 'обращение не в начале');
ok(!StreamCommand::addressesBot('Лирика какая-то'), 'префикс чужого слова');
ok(!StreamCommand::addressesBot(''), 'пустая строка');

echo "\n== Известные команды ==\n";
ok(StreamCommand::isKnownCommand('Лира, включи перерыв'), 'перерыв');
ok(StreamCommand::isKnownCommand('Лира следующая серия'), 'следующая');
ok(StreamCommand::isKnownCommand('Лира, врубай киношку'), 'кино (словоформа)');
ok(StreamCommand::isKnownCommand('Лира, включи конец'), 'конец');
ok(StreamCommand::isKnownCommand('Лира, включи начало'), 'начало (заставка)');
ok(!StreamCommand::isKnownCommand('Лира, как дела?'), 'болтовня — не команда');
ok(!StreamCommand::isKnownCommand('Лира, спой песню'), 'произвольная просьба — не команда');
ok(!StreamCommand::isKnownCommand('Лира, ты начала рисовать?'), '«начала» — не команда «начало»');
ok(!StreamCommand::isKnownCommand('Лира, а ты закончила?'), '«закончила» — не команда «конец»');

echo "\n== Перечень команд ==\n";
ok(StreamCommand::isHelpRequest('Лира, команды'), 'слово «команды»');
ok(StreamCommand::isHelpRequest('Лира, что ты умеешь?'), '«что ты умеешь»');
ok(StreamCommand::isHelpRequest('Лира, ?'), 'одинокий знак вопроса');
ok(StreamCommand::isHelpRequest('Лира ???'), 'несколько знаков вопроса');
ok(!StreamCommand::isHelpRequest('Лира, включишь кино?'), 'команда-вопрос — не перечень');
ok(!StreamCommand::isHelpRequest('Лира, помоги с задачей'), '«помоги» — обычная просьба, не перечень');
ok(!StreamCommand::isHelpRequest('Лира, включи перерыв'), 'обычная команда — не перечень');

echo "\n== Приказ против болтовни (боевая находка 2026-08-08) ==\n";
ok(StreamCommand::looksLikeCommand('включи перерыв'), 'глагол «включи»');
ok(StreamCommand::looksLikeCommand('кино'), 'одно слово — телеграфный приказ');
ok(StreamCommand::looksLikeCommand('следующая серия'), 'два слова');
ok(StreamCommand::looksLikeCommand('а давай перерыв'), 'глагол «давай»');
ok(!StreamCommand::looksLikeCommand('а ты что думаешь? мы про 18 серию 7 сезона SG-1'),
    'обсуждение серии — НЕ приказ');
ok(!StreamCommand::looksLikeCommand('что думаешь про эту серию'), 'вопрос без глагола — не приказ');
ok(!StreamCommand::looksLikeCommand('классное кино мы сегодня смотрим правда'), 'длинная реплика — не приказ');

echo $fail ? "\nFAIL ($fail)\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
