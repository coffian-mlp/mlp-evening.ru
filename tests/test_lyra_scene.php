<?php
/**
 * Юнит-тест LyraArtist::sceneFromRaw (MLP-293): выжимка сцены из сырого ответа
 * режиссёра. Прецедент: через generateReply модель игнорировала задание —
 * продолжала болтать по-русски, отвечала одной реакцией или молчала, и
 * /нарисуйчат ложно отказывал «рисовать нечего» при живом чате.
 *
 * БД не нужна. Запуск: php tests/test_lyra_scene.php
 */

require_once __DIR__ . '/../autoload.php';

use LLM\LyraArtist;

$fail = 0;
function ok($cond, $label) {
    global $fail;
    echo ($cond ? "  [OK] " : "  [FAIL] ") . $label . "\n";
    if (!$cond) $fail++;
}

echo "== Пригодные сцены ==\n";
ok(LyraArtist::sceneFromRaw('Two ponies argue about sausages while a third laughs nearby.')
    === 'Two ponies argue about sausages while a third laughs nearby.', 'английская сцена проходит как есть');
ok(LyraArtist::sceneFromRaw("[РЕАКЦИЯ: laugh] Ponies CoFFian and Пшеница joke about a notebook.")
    === 'Ponies CoFFian and Пшеница joke about a notebook.', 'маркер реакции срезается, сцена с именами остаётся');
$long = str_repeat('pony ', 300); // 1500 симв. → срез до 1000
ok(mb_strlen((string)LyraArtist::sceneFromRaw($long)) === 1000, 'длина ограничена 1000 (MLP-341)');
ok(LyraArtist::sceneFromRaw("A pony draws ![чат](/upload/lyra/x.jpg) at an easel")
    === 'A pony draws  at an easel', 'markdown-картинка вырезана (анти-инъекция)');

echo "\n== Брак: болтовня, реакции, тишина ==\n";
echo "\n== sceneHints (MLP-333) ==\n";
$online = [['id' => 12, 'nickname' => 'TotallyNotAPony'], ['id' => 7, 'nickname' => 'Пшеница'], ['id' => 10, 'nickname' => 'Darbel'], ['id' => 5, 'nickname' => 'Назар']];
$doss = [7 => [['text' => 'принцесса чата'], ['text' => 'ест ромашковый чай'], ['text' => 'третий факт — лишний']], 10 => [['text' => str_repeat('лего ', 30)]]];
$h = LyraArtist::sceneHints($online, $doss, 12);
ok(str_starts_with($h, 'В чате сейчас: Пшеница, Darbel, Назар.'), "присутствующие без бота: $h");
ok(str_contains($h, 'Пшеница — принцесса чата; ест ромашковый чай.'), 'максимум два факта на человека');
ok(!str_contains($h, 'третий факт'), 'третий факт отброшен');
ok(preg_match('/Darbel — (лего ){1,16}лего…\./u', $h) === 1, 'длинный факт усечён по границе слова: ' . mb_substr($h, mb_strpos($h, 'Darbel'), 100));
ok(LyraArtist::shortFact('**Участник:** * **Стиль:** Приветствует «Однако, здравствуйте», активно использует смайлы; интересуется играми') === 'Участник: Стиль: Приветствует «Однако, здравствуйте», активно использует смайлы…', 'markdown убран, срез по «;»: ' . LyraArtist::shortFact('**Участник:** * **Стиль:** Приветствует «Однако, здравствуйте», активно использует смайлы; интересуется играми'));
ok(LyraArtist::shortFact('1. Манера речи: лаконичные реплики') === 'Манера речи: лаконичные реплики', 'нумерация снята');
ok(LyraArtist::shortFact('  коротко.  ') === 'коротко', 'короткий факт — как есть, без хвостовой точки');
ok(!str_contains($h, 'Назар —'), 'без досье — только в списке присутствующих');
ok(LyraArtist::sceneHints([['id' => 12, 'nickname' => 'TotallyNotAPony']], [], 12) === '', 'только бот → пусто');
ok(LyraArtist::sceneHints([], $doss, 12) === '', 'никого онлайн → пусто');
$tight = LyraArtist::sceneHints($online, $doss, 12, 60);
ok($tight === 'В чате сейчас: Пшеница, Darbel, Назар.', 'бюджет не вмещает приметы → только список');
ok(mb_strlen(LyraArtist::sceneHints($online, $doss, 12, 120)) <= 120, 'бюджет соблюдается');

echo "\n== colorName / appearance (MLP-334) ==\n";
ok(LyraArtist::colorName('#ff1e63') === 'bright pink' || LyraArtist::colorName('#ff1e63') === 'pink', 'ff1e63 → pink: ' . LyraArtist::colorName('#ff1e63'));
ok(LyraArtist::colorName('#870D49') === 'deep magenta' || LyraArtist::colorName('#870D49') === 'deep wine-red', '870D49 → тёмный винный/маджента: ' . LyraArtist::colorName('#870D49'));
ok(LyraArtist::colorName('#8CFFDB') === 'pale mint', '8CFFDB → pale mint: ' . LyraArtist::colorName('#8CFFDB'));
ok(LyraArtist::colorName('#64C4D4') === 'turquoise', '64C4D4 → turquoise: ' . LyraArtist::colorName('#64C4D4'));
ok(LyraArtist::colorName('#D97757') === 'orange', 'D97757 → orange: ' . LyraArtist::colorName('#D97757'));
ok(LyraArtist::colorName('#6d2f8e') === '', 'дефолтный цвет → пусто');
ok(LyraArtist::colorName('зелёный') === '' && LyraArtist::colorName('') === '', 'мусор/пусто → пусто');
ok(LyraArtist::colorName('#808080') === 'light grey' || LyraArtist::colorName('#808080') === 'dark grey', 'серый распознан');
ok(LyraArtist::appearance([], '#ff1e63') === LyraArtist::colorName('#ff1e63') . ' mane and accents (#ff1e63), coat of any fitting colour', 'без памяти — цвет ника → грива/акценты, не шёрстка');
ok(LyraArtist::appearance([['text' => 'любит чай'], ['text' => 'Внешность: серая кобылка с синей гривой и очками']], '#ff1e63') === 'серая кобылка с синей гривой и очками', 'факт «внешность:» перебивает цвет');
ok(LyraArtist::appearance([['text' => 'любит чай']], '#6d2f8e') === '', 'дефолтный цвет и нет внешности → пусто');
$h2 = LyraArtist::sceneHints([['id' => 1, 'nickname' => 'CoFFian', 'chat_color' => '#ff1e63'], ['id' => 5, 'nickname' => 'Назар', 'chat_color' => '#6d2f8e']], [], 12);
ok(str_contains($h2, "\nВнешность (для художника): CoFFian — ") && str_contains($h2, 'accents (#ff1e63)') && !str_contains($h2, 'Назар —'), 'блок внешности: только у тех, кого можно отличить: ' . $h2);

ok(LyraArtist::sceneFromRaw(null) === null, 'null → null');
ok(LyraArtist::sceneFromRaw('') === null, 'пустой ответ → null');
ok(LyraArtist::sceneFromRaw('[РЕАКЦИЯ: laugh]') === null, 'только реакция → null');
ok(LyraArtist::sceneFromRaw('@Пшеница, "Конечно есть" — и так буднично! Я боюсь открывать этот блокнот...')
    === null, 'русская болтовня вместо сцены (прецедент 24.07) → null');
ok(LyraArtist::sceneFromRaw('Ха-ха :D ну ты даёшь') === null, 'русский со смайлом (латиница < 3 подряд) → null');

echo "\n" . ($fail === 0 ? "ALL PASS" : "FAILED: $fail") . "\n";
exit($fail === 0 ? 0 : 1);
