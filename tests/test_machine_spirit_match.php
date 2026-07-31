<?php
use LLM\MachineSpirit;
/**
 * Юнит-тест MachineSpirit::matches() — MLP-300.
 * Чистый матчер обращения «Клод ...» (без БД и опций — это wants()).
 *
 * Запуск: php tests/test_machine_spirit_match.php
 */

require_once __DIR__ . '/../autoload.php';

$fail = 0;
function ok($cond, $label) {
    global $fail;
    echo ($cond ? "  [OK] " : "  [FAIL] ") . $label . "\n";
    if (!$cond) $fail++;
}

echo "== Совпадения ==\n";
ok(MachineSpirit::matches('Клод включи перерыв'), 'базовое обращение');
ok(MachineSpirit::matches('клод, следующая серия'), 'нижний регистр + запятая');
ok(MachineSpirit::matches('КЛОД включи кино!'), 'верхний регистр');
ok(MachineSpirit::matches('  Клод: статус'), 'ведущие пробелы и двоеточие');
ok(MachineSpirit::matches('Клод'), 'одно слово-обращение');

echo "\n== Не обращение ==\n";
ok(!MachineSpirit::matches('говорят, Клод включил перерыв'), 'обращение не в начале');
ok(!MachineSpirit::matches('Клодовик пришёл'), 'префикс чужого слова');
ok(!MachineSpirit::matches('привет, лира!'), 'обычное сообщение');
ok(!MachineSpirit::matches(''), 'пустая строка');
ok(!MachineSpirit::matches('claude включи перерыв'), 'латиница — не триггер (демон и дух слушают кириллицу)');

echo "\n== Справка (MLP-301) ==\n";
ok(MachineSpirit::isHelpRequest('Клод, команды'), 'слово «команды»');
ok(MachineSpirit::isHelpRequest('Клод помощь'), 'слово «помощь»');
ok(MachineSpirit::isHelpRequest('Клод, что ты умеешь?'), '«что ты умеешь»');
ok(MachineSpirit::isHelpRequest('клод help'), 'help латиницей');
ok(MachineSpirit::isHelpRequest('Клод, ?'), 'одинокий знак вопроса');
ok(MachineSpirit::isHelpRequest('Клод ???'), 'несколько знаков вопроса');
ok(!MachineSpirit::isHelpRequest('Клод включи перерыв'), 'обычная команда — не справка');
ok(!MachineSpirit::isHelpRequest('Клод, включишь кино?'), 'команда-вопрос — не справка');

echo "\n== Известные команды (MLP-302) ==\n";
ok(MachineSpirit::isKnownCommand('Клод, включи перерыв'), 'перерыв');
ok(MachineSpirit::isKnownCommand('Клод следующая серия'), 'следующая');
ok(MachineSpirit::isKnownCommand('Клод, врубай киношку'), 'кино (словоформа)');
ok(MachineSpirit::isKnownCommand('Клод, включи конец'), 'конец');
ok(!MachineSpirit::isKnownCommand('Клод, как дела?'), 'болтовня — не команда');
ok(!MachineSpirit::isKnownCommand('Клод, спой песню'), 'произвольная просьба — не команда');

echo $fail ? "\nFAIL ($fail)\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
