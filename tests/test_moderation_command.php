<?php
use LLM\ModerationCommand;
/**
 * Юнит-тест команд /бан и /мут (MLP-350): разбор аргументов, вердикт оценщика жалоб,
 * очистка обоснования перед публикацией, выбор модераторов для вызова. Pure, без БД и LLM.
 *
 * Запуск: php tests/test_moderation_command.php
 */
require_once __DIR__ . '/../autoload.php';

$fail = 0;
function ok($cond, $label) {
    global $fail;
    echo ($cond ? "  [OK] " : "  [FAIL] ") . $label . "\n";
    if (!$cond) $fail++;
}

echo "== Аргументы /бан ==\n";
ok(ModerationCommand::parseArgs('@Darbel спамит стикерами', false) === ['target' => 'Darbel', 'minutes' => null, 'reason' => 'спамит стикерами'], '@ник и причина');
ok(ModerationCommand::parseArgs('Пшеница', false) === ['target' => 'Пшеница', 'minutes' => null, 'reason' => ''], 'ник без @ и без причины');
ok(ModerationCommand::parseArgs('   ', false)['target'] === null, 'пусто → цели нет');
ok(ModerationCommand::parseArgs('@x', false)['target'] === null, 'слишком короткий ник → цели нет');
ok(ModerationCommand::parseArgs('@Darbel 30 флуд', false)['reason'] === '30 флуд', 'для /бан число — часть причины');

echo "\n== Аргументы /мут ==\n";
ok(ModerationCommand::parseArgs('@Darbel 30 флуд', true) === ['target' => 'Darbel', 'minutes' => 30, 'reason' => 'флуд'], 'минуты и причина');
ok(ModerationCommand::parseArgs('@Darbel 10 мин капс', true) === ['target' => 'Darbel', 'minutes' => 10, 'reason' => 'капс'], '«10 мин» тоже минуты');
ok(ModerationCommand::parseArgs('@Darbel флуд', true) === ['target' => 'Darbel', 'minutes' => null, 'reason' => 'флуд'], 'без минут — дефолт решает обработчик');
ok(ModerationCommand::parseArgs('@Darbel 99999', true)['minutes'] === ModerationCommand::MUTE_MAX, 'минуты срезаны до суток');
ok(ModerationCommand::parseArgs('@Darbel 0', true)['minutes'] === 1, 'ноль минут → минимум 1');

echo "\n== Вердикт оценщика ==\n";
ok(ModerationCommand::parseVerdict("ЗВАТЬ\nОскорбления в адрес участника, пункт 1.") === ['call' => true, 'why' => 'Оскорбления в адрес участника, пункт 1.'], 'ЗВАТЬ + обоснование');
ok(ModerationCommand::parseVerdict("НЕ ЗВАТЬ\nОбычный спор без оскорблений.") === ['call' => false, 'why' => 'Обычный спор без оскорблений.'], 'НЕ ЗВАТЬ не путается с ЗВАТЬ');
ok(ModerationCommand::parseVerdict("1) **ЗВАТЬ**\n2) Флуд одинаковыми сообщениями.")['call'] === true, 'нумерация и выделение допускаются');
ok(ModerationCommand::parseVerdict("1) **ЗВАТЬ**\n2) Флуд одинаковыми сообщениями.")['why'] === 'Флуд одинаковыми сообщениями.', 'нумерация срезана и в обосновании');
ok(ModerationCommand::parseVerdict("не звать\nшутки") ['call'] === false, 'регистр не важен');
ok(ModerationCommand::parseVerdict("ЗВАТЬ") === ['call' => true, 'why' => ''], 'без обоснования — пустое, подставит обработчик');
ok(ModerationCommand::parseVerdict("Думаю, стоит позвать модераторов") === null, 'не по формату → null (модераторов не пингуем)');
ok(ModerationCommand::parseVerdict('') === null && ModerationCommand::parseVerdict(null) === null, 'пусто и null → null');
ok(ModerationCommand::parseVerdict("ЗВАТЬСЯ\n…") === null, 'слово целиком: «ЗВАТЬСЯ» не вердикт');

echo "\n== Обоснование для чата ==\n";
ok(ModerationCommand::cleanWhy('Флуд, пункт 1', 'x') === 'Флуд, пункт 1.', 'точка в конце');
ok(ModerationCommand::cleanWhy('Пишет @CoFFian @Darbel оскорбления!', 'x') === 'Пишет CoFFian Darbel оскорбления!', 'без @ — Лира не пингует тех, кого выбрала модель');
ok(ModerationCommand::cleanWhy('Смотри ![рисунок](/upload/lyra/x.jpg) и https://evil.example/a.png', 'x') === 'Смотри и.', 'без markdown-картинок и ссылок');
ok(ModerationCommand::cleanWhy('  «**»  ', 'нарушений не видно') === 'нарушений не видно.', 'пусто после очистки → запасная фраза');
$long = ModerationCommand::cleanWhy(str_repeat('а', 500), 'x');
ok(mb_strlen($long) <= ModerationCommand::WHY_MAX_CHARS + 1 && mb_substr($long, -1) === '…', 'длинное обрезано с многоточием');

echo "\n== Кого звать ==\n";
$online = [['id' => 12, 'nickname' => 'TotallyNotAPony'], ['id' => 1, 'nickname' => 'CoFFian'], ['id' => 7, 'nickname' => 'Пшеница'],
           ['id' => 11, 'nickname' => 'Назар'], ['id' => 10, 'nickname' => 'Darbel'], ['id' => 3, 'nickname' => 'Рарити']];
$roles = [1 => 'admin', 7 => 'admin', 10 => 'admin', 11 => 'user', 12 => 'user', 3 => 'moderator'];
ok(ModerationCommand::staffToCall($online, $roles, [12, 11, 10]) === ['CoFFian', 'Пшеница', 'Рарити'], 'админы и модераторы в чате, без бота, обвиняемого и жалобщика');
ok(ModerationCommand::staffToCall($online, $roles, [12, 1, 7, 10, 3]) === [], 'все свои исключены → звать некого');
ok(ModerationCommand::staffToCall([], $roles, []) === [], 'в чате никого');
ok(ModerationCommand::staffToCall([['id' => 1, 'nickname' => 'CoFFian'], ['id' => 1, 'nickname' => 'CoFFian']], $roles, []) === ['CoFFian'], 'две вкладки одного админа — один пинг');

echo "\n== Задание оценщику ==\n";
$task = ModerationCommand::evaluationTask('Назар', 'Darbel', 'спамит', 'ban', "1. Не быть булочкой.", "[21:30] привет", "[21:30] Darbel: привет");
ok(mb_strpos($task, 'Жалоба: @Назар просит забанить @Darbel.') === 0, 'кто на кого и что просит');
ok(mb_strpos($task, 'Причина со слов жалующегося: «спамит».') !== false, 'причина в кавычках — как данные');
ok(mb_strpos($task, "Правила чата:\n1. Не быть булочкой.") !== false, 'правила из дашборда');
ok(mb_strpos(ModerationCommand::evaluationTask('А', 'Б', '', 'mute', '', 'x', ''), 'не заданы — суди по здравому смыслу') !== false, 'без правил — здравый смысл');
ok(mb_strpos(ModerationCommand::evaluationTask('А', 'Б', '', 'mute', '', 'x', ''), 'просит заглушить') !== false, '/мут — «заглушить»');

echo "\n";
if ($fail > 0) { echo "FAIL: $fail\n"; exit(1); }
echo "ALL PASS\n";
