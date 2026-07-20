<?php
use Infra\Env;
/**
 * Юнит-тест Infra\Env::parse() — разбор .env (MLP-252). БД не нужна.
 * Запуск: php tests/test_env_parse.php
 */

require_once __DIR__ . '/../src/Infra/Env.php';

$fail = 0;
function eq($got, $want, $label) {
    global $fail;
    if ($got === $want) { echo "  [OK] $label\n"; }
    else { echo "  [FAIL] $label (want " . var_export($want, true) . ", got " . var_export($got, true) . ")\n"; $fail++; }
}

$vars = Env::parse(<<<ENV
# комментарий
DB_HOST=db
DB_PASS="p@ss=word#1"
CHAT_DRIVER='sse'
EMPTY=
  SPACED  =  значение с пробелами
БЕЗ_РАВНО_строка
=без_ключа
ENV);

eq($vars['DB_HOST'] ?? null, 'db', 'простое значение');
eq($vars['DB_PASS'] ?? null, 'p@ss=word#1', 'кавычки сняты, = и # внутри сохранены');
eq($vars['CHAT_DRIVER'] ?? null, 'sse', 'одинарные кавычки сняты');
eq($vars['EMPTY'] ?? null, '', 'пустое значение — пустая строка');
eq($vars['SPACED'] ?? null, 'значение с пробелами', 'пробелы вокруг ключа/значения обрезаны');
eq(array_key_exists('БЕЗ_РАВНО_строка', $vars), false, 'строка без = игнорируется');
eq(array_key_exists('', $vars), false, 'пустой ключ игнорируется');
eq(count(Env::parse('')), 0, 'пустой файл — пустой массив');

echo "\n" . ($fail === 0 ? "ALL PASS\n" : "FAILURES: $fail\n");
exit($fail === 0 ? 0 : 1);
