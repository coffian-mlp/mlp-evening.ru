<?php
use LLM\ChatKnowledge;
/**
 * Юнит-тест знаний Лиры о чате (MLP-349, прод-беклог №22 и №24): блок правил,
 * строка ролей, подстановка правил в /правила и склейка блока. Pure, без БД.
 *
 * Запуск: php tests/test_chat_knowledge.php
 */
require_once __DIR__ . '/../autoload.php';

$fail = 0;
function ok($cond, $label) {
    global $fail;
    echo ($cond ? "  [OK] " : "  [FAIL] ") . $label . "\n";
    if (!$cond) $fail++;
}
$u = fn(int $id, string $nick, string $role, string $login = '') => ['id' => $id, 'nickname' => $nick, 'login' => $login ?: "l$id", 'role' => $role];

echo "== Правила ==\n";
ok(ChatKnowledge::rulesBlock('') === null, 'пустые правила → нет блока');
ok(ChatKnowledge::rulesBlock("  \n ") === null, 'пробелы → нет блока');
$rb = (string)ChatKnowledge::rulesBlock("1. Не быть булочкой.\n2. Модер всегда прав.\n");
ok(strpos($rb, '[Правила чата]') === 0, 'блок начинается с заголовка');
ok(strpos($rb, "1. Не быть булочкой.\n2. Модер всегда прав.") !== false, 'правила внутри блока как есть');
ok(mb_strpos($rb, 'не обвиняй участников') !== false, 'Лира не объявляет нарушений сама');

echo "\n== Роли: как на проде ==\n";
$prod = [$u(1, 'CoFFian', 'admin'), $u(7, 'Пшеница', 'admin'), $u(10, 'Darbel', 'admin'),
         $u(11, 'Назар', 'user'), $u(12, 'TotallyNotAPony', 'user'), $u(19, 'Клод', 'user')];
$line = (string)ChatKnowledge::rolesLine($prod, 1, 12);
ok(strpos($line, '[Роли в чате]: CoFFian — владелец сайта и админ; Пшеница, Darbel — админы.') === 0, "владелец первым, админы списком: " . mb_substr($line, 0, 90));
ok(mb_strpos($line, 'Назар') === false && mb_strpos($line, 'Клод') === false, 'обычные участники не перечисляются');
ok(mb_strpos($line, 'Остальные — обычные участники.') !== false, 'пояснение про остальных');
$noOwner = (string)ChatKnowledge::rolesLine($prod, 0, 12);
ok(strpos($noOwner, '[Роли в чате]: CoFFian, Пшеница, Darbel — админы.') === 0, 'владелец не задан → все админы одним списком');

echo "\n== Роли: формы и края ==\n";
ok(strpos((string)ChatKnowledge::rolesLine([$u(2, 'Твайлайт', 'admin')], 0, 12), '[Роли в чате]: Твайлайт — админ.') === 0, 'один админ → «админ»');
$mods = (string)ChatKnowledge::rolesLine([$u(3, 'Рарити', 'moderator'), $u(4, 'Эпплджек', 'moderator')], 0, 12);
ok(mb_strpos($mods, 'Рарити, Эпплджек — модераторы') !== false, 'модераторы во множественном');
ok(mb_strpos((string)ChatKnowledge::rolesLine([$u(3, 'Рарити', 'moderator')], 0, 12), 'Рарити — модератор.') !== false, 'один модератор');
ok(mb_strpos((string)ChatKnowledge::rolesLine([$u(5, 'Спайк', 'moderator')], 5, 12), 'Спайк — владелец сайта и модератор') !== false, 'владелец-модератор');
ok(mb_strpos((string)ChatKnowledge::rolesLine([$u(6, 'Селестия', 'user')], 6, 12), 'Селестия — владелец сайта.') !== false, 'владелец без роли — просто владелец');
ok(ChatKnowledge::rolesLine([$u(12, 'TotallyNotAPony', 'admin')], 0, 12) === null, 'бот исключается даже с ролью admin');
ok(mb_strpos((string)ChatKnowledge::rolesLine([$u(8, '', 'admin', 'WheatTail')], 0, 12), 'WheatTail — админ') !== false, 'пустой ник → логин');
ok(ChatKnowledge::rolesLine([$u(11, 'Назар', 'user')], 0, 12) === null, 'нет персонала и владельца → null');
ok(ChatKnowledge::rolesLine([], 0, 12) === null, 'пустой список → null');
ok(ChatKnowledge::rolesLine([$u(11, 'Назар', 'user')], 99, 12) === null, 'владелец не найден среди пользователей → null');

echo "\n== /правила: подстановка {rules} ==\n";
$cmd = "Нужно описать правила чата.\n{rules}\n\nПостарайся перечислить близко к списку.";
ok(ChatKnowledge::withRules($cmd, "1. Раз.\n2. Два.") === "Нужно описать правила чата.\n1. Раз.\n2. Два.\n\nПостарайся перечислить близко к списку.", 'правила подставлены');
ok(mb_strpos(ChatKnowledge::withRules($cmd, '  '), 'правила пока не заданы') !== false, 'правил нет → честная пометка, модель не сочиняет');
ok(ChatKnowledge::withRules('Расскажи анекдот.', '1. Раз.') === 'Расскажи анекдот.', 'без плейсхолдера — промпт как есть');
ok(ChatKnowledge::withRules('', '1. Раз.') === '', 'пустой промпт команды остаётся пустым (дальше — дефолтная инструкция)');

echo "\n== Склейка блока ==\n";
ok(ChatKnowledge::block(null, null) === null, 'ничего → null');
ok(ChatKnowledge::block('П', null) === 'П', 'только правила');
ok(ChatKnowledge::block(null, 'Р') === 'Р', 'только роли');
ok(ChatKnowledge::block('П', 'Р') === "П\n\nР", 'правила, затем роли через пустую строку');

echo "\n";
if ($fail > 0) { echo "FAIL: $fail\n"; exit(1); }
echo "ALL PASS\n";
