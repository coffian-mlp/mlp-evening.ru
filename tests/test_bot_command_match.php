<?php
/**
 * Юнит-тест BotCommandManager::matchCommand() — A3 (MLP-228).
 * Сопоставление сообщения с активной командой бота (pure-логика, вынесена из api.php).
 *
 * BotCommandManager тянет Database.php → config.php: на чистом клоне мягко SKIP.
 *
 * Запуск: php tests/test_bot_command_match.php
 */

if (!file_exists(__DIR__ . '/../config.php')) {
    echo "SKIP: config.php отсутствует (нужен для загрузки Database.php)\n";
    exit(0);
}

require_once __DIR__ . '/../src/BotCommandManager.php';

$fail = 0;
function ok($cond, $label) {
    global $fail;
    echo ($cond ? "  [OK] " : "  [FAIL] ") . $label . "\n";
    if (!$cond) $fail++;
}

$cmds = [
    ['command_prefix' => '/schedule', 'handler_type' => 'schedule'],
    ['command_prefix' => '/расписание', 'handler_type' => 'schedule'],
];

echo "== Совпадения ==\n";
ok(BotCommandManager::matchCommand($cmds, '/schedule tonight')['handler_type'] === 'schedule', '/schedule с аргументом');
ok(BotCommandManager::matchCommand($cmds, 'schedule')['command_prefix'] === '/schedule', 'без слеша тоже команда');
ok(BotCommandManager::matchCommand($cmds, '/расписание')['command_prefix'] === '/расписание', 'кириллический префикс');
ok(BotCommandManager::matchCommand($cmds, '  /schedule  ') !== null, 'ведущие пробелы обрезаются');

echo "\n== Не команда ==\n";
ok(BotCommandManager::matchCommand($cmds, 'scheduler online?') === null, 'scheduler — не команда (граница слова)');
ok(BotCommandManager::matchCommand($cmds, 'привет, лира!') === null, 'обычное сообщение');
ok(BotCommandManager::matchCommand($cmds, 'а что там по schedule') === null, 'префикс не в начале');
ok(BotCommandManager::matchCommand([], '/schedule') === null, 'пустой список команд');

echo "\n" . ($fail === 0 ? "ALL PASS\n" : "FAILURES: $fail\n");
exit($fail === 0 ? 0 : 1);
