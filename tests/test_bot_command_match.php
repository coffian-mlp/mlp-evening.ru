<?php
use Domain\BotCommandManager;
/**
 * Юнит-тест BotCommandManager::matchCommand() — A3 (MLP-228).
 * Сопоставление сообщения с активной командой бота (pure-логика, вынесена из api.php).
 *
 * BotCommandManager тянет Database.php → config.php: на чистом клоне мягко SKIP.
 *
 * Запуск: php tests/test_bot_command_match.php
 */

if (!file_exists(__DIR__ . '/../.env') && !file_exists(__DIR__ . '/../config.php')) {
    echo "SKIP: нет .env/config.php (нужен для загрузки Database.php)\n";
    exit(0);
}

require_once __DIR__ . '/../autoload.php';

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

echo "\n== stripPrefix (MLP-284, AR7-5) ==\n";
$todo = ['command_prefix' => '/todo'];
ok(BotCommandManager::stripPrefix($todo, '/todo идея про пони', 'todo') === 'идея про пони', 'срез префикса со слешем');
ok(BotCommandManager::stripPrefix($todo, 'todo идея', 'todo') === 'идея', 'срез без слеша');
ok(BotCommandManager::stripPrefix($todo, '/todo', 'todo') === '', 'пустая нагрузка');
ok(BotCommandManager::stripPrefix($todo, '  /TODO  ИДЕЯ  ', 'todo') === 'ИДЕЯ', 'регистронезависимо, трим');
ok(BotCommandManager::stripPrefix(['command_prefix' => '/нарисуй'], '/нарисуй мятного кролика', 'нарисуй') === 'мятного кролика', 'кириллический префикс');
ok(BotCommandManager::stripPrefix([], '/todo идея', 'todo') === 'идея', 'фоллбек-префикс при пустой команде');
ok(BotCommandManager::stripPrefix(['command_prefix' => ''], 'просто текст', '') === 'просто текст', 'пустой префикс — только трим');

echo "\n" . ($fail === 0 ? "ALL PASS\n" : "FAILURES: $fail\n");
exit($fail === 0 ? 0 : 1);
