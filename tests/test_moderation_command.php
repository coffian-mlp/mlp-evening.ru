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

echo "== Аргументы: цель и причина ==\n";
ok(ModerationCommand::parseArgs('@Darbel спамит стикерами') === ['target' => 'Darbel', 'minutes' => null, 'reason' => 'спамит стикерами'], '@ник и причина, срок не указан');
ok(ModerationCommand::parseArgs('Пшеница') === ['target' => 'Пшеница', 'minutes' => null, 'reason' => ''], 'ник без @ и без причины');
ok(ModerationCommand::parseArgs('   ')['target'] === null, 'пусто → цели нет');
ok(ModerationCommand::parseArgs('@x')['target'] === null, 'слишком короткий ник → цели нет');

echo "\n== Аргументы: срок (MLP-352) ==\n";
ok(ModerationCommand::parseArgs('@Wellerman 1 чтоб не пытался банить админов)') === ['target' => 'Wellerman', 'minutes' => 1, 'reason' => 'чтоб не пытался банить админов)'], 'прецедент 26.09: «1» — минута, «ч» в «чтоб» не часы');
ok(ModerationCommand::parseArgs('@Darbel 30 флуд') === ['target' => 'Darbel', 'minutes' => 30, 'reason' => 'флуд'], 'голое число — минуты');
ok(ModerationCommand::parseArgs('@Darbel 30м флуд')['minutes'] === 30, '30м');
ok(ModerationCommand::parseArgs('@Darbel 30 мин. флуд') === ['target' => 'Darbel', 'minutes' => 30, 'reason' => 'флуд'], '«30 мин.» с точкой');
ok(ModerationCommand::parseArgs('@Darbel 2ч капс')['minutes'] === 120, '2ч');
ok(ModerationCommand::parseArgs('@Darbel 2 часа капс') === ['target' => 'Darbel', 'minutes' => 120, 'reason' => 'капс'], '«2 часа»');
ok(ModerationCommand::parseArgs('@Darbel 1д спам')['minutes'] === 1440, '1д');
ok(ModerationCommand::parseArgs('@Darbel 3 дня')['minutes'] === 4320, '«3 дня» без причины');
ok(ModerationCommand::parseArgs('@Darbel 1 день')['minutes'] === 1440, '«1 день»');
ok(ModerationCommand::parseArgs('@Darbel 5 h')['minutes'] === 300, 'латинская единица h');
ok(ModerationCommand::parseArgs('@Darbel 999999д')['minutes'] === ModerationCommand::BAN_MAX, 'срок срезан до года');
ok(ModerationCommand::parseArgs('@Darbel 0')['minutes'] === 1, 'ноль → минимум 1 минута');
ok(ModerationCommand::parseArgs('@Darbel флуд 30')['minutes'] === null, 'число после причины — часть причины');

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

echo "\n== Живые ответы: инструкции (MLP-352) ==\n";
$ban = ModerationCommand::sanctionInstruction('ban', 'CoFFian', 'Wellerman', 1, 'чтоб не пытался банить админов)');
ok(mb_strpos($ban, 'По решению модератора @CoFFian: бан для @Wellerman на 1 мин.') === 0, 'бан на срок: кто, кому, сколько');
ok(mb_strpos($ban, '«чтоб не пытался банить админов)»') !== false, 'причина в кавычках — как данные');
ok(mb_strpos($ban, 'не высмеивай') !== false && mb_strpos($ban, 'пол участников не выдумывай') !== false, 'ограничения тона');
ok(mb_strpos(ModerationCommand::sanctionInstruction('ban', 'A', 'B', null, ''), 'бан для @B навсегда. Причина: не указана.') !== false, 'бессрочный бан без причины');
ok(mb_strpos(ModerationCommand::sanctionInstruction('mute', 'A', 'B', 90, 'капс'), 'мут для @B на 1 ч 30 мин') !== false, 'мут со сроком по-человечески');
ok(mb_strpos(ModerationCommand::refusalInstruction('Darbel', 'Администратор неприкосновенен!'), '«Администратор неприкосновенен!»') !== false, 'отказ передаёт текст политики');
ok(ModerationCommand::lowerFirst('Загляните, пожалуйста') === 'загляните, пожалуйста', 'после пингов — со строчной');
ok(ModerationCommand::lowerFirst('@Назар просит…') === '@Назар просит…', 'упоминание в начале не трогаем');

echo "\n== /разбан (MLP-353) ==\n";
ok(ModerationCommand::parseArgs('@Wellerman') === ['target' => 'Wellerman', 'minutes' => null, 'reason' => ''], 'цель без срока и причины');
ok(ModerationCommand::liftedLabel(true, false) === 'бан', 'снят только бан');
ok(ModerationCommand::liftedLabel(false, true) === 'мут', 'снят только мут');
ok(ModerationCommand::liftedLabel(true, true) === 'бан и мут', 'сняты оба');
$lift = ModerationCommand::liftInstruction('CoFFian', 'Wellerman', 'бан');
ok(mb_strpos($lift, 'По решению модератора @CoFFian с @Wellerman снят бан: снова можно писать в чат.') === 0, 'объявление: кто, с кого, что');
ok(mb_strpos(ModerationCommand::liftInstruction('A', 'B', 'бан и мут'), 'с @B сняты бан и мут') !== false, 'согласование числа: «сняты»');
ok(mb_strpos(ModerationCommand::refusalInstruction('Darbel', 'Администратор неприкосновенен!', 'снять санкцию с участника'), '@Darbel хочет снять санкцию с участника') === 0, 'отказ при снятии — своими словами');
ok(mb_strpos(ModerationCommand::refusalInstruction('Darbel', 'x'), '@Darbel хочет наказать участника') === 0, 'отказ при санкции — как раньше');

echo "\n";
if ($fail > 0) { echo "FAIL: $fail\n"; exit(1); }
echo "ALL PASS\n";
