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
$long = str_repeat('pony ', 200);
ok(mb_strlen((string)LyraArtist::sceneFromRaw($long)) === 400, 'длина ограничена 400');
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
ok(str_contains($h, 'Darbel — ' . str_repeat('лего ', 15) . 'лег…') || preg_match('/Darbel — .{79}…\./u', $h), 'длинный факт усечён до 80');
ok(!str_contains($h, 'Назар —'), 'без досье — только в списке присутствующих');
ok(LyraArtist::sceneHints([['id' => 12, 'nickname' => 'TotallyNotAPony']], [], 12) === '', 'только бот → пусто');
ok(LyraArtist::sceneHints([], $doss, 12) === '', 'никого онлайн → пусто');
$tight = LyraArtist::sceneHints($online, $doss, 12, 60);
ok($tight === 'В чате сейчас: Пшеница, Darbel, Назар.', 'бюджет не вмещает приметы → только список');
ok(mb_strlen(LyraArtist::sceneHints($online, $doss, 12, 120)) <= 120, 'бюджет соблюдается');

ok(LyraArtist::sceneFromRaw(null) === null, 'null → null');
ok(LyraArtist::sceneFromRaw('') === null, 'пустой ответ → null');
ok(LyraArtist::sceneFromRaw('[РЕАКЦИЯ: laugh]') === null, 'только реакция → null');
ok(LyraArtist::sceneFromRaw('@Пшеница, "Конечно есть" — и так буднично! Я боюсь открывать этот блокнот...')
    === null, 'русская болтовня вместо сцены (прецедент 24.07) → null');
ok(LyraArtist::sceneFromRaw('Ха-ха :D ну ты даёшь') === null, 'русский со смайлом (латиница < 3 подряд) → null');

echo "\n" . ($fail === 0 ? "ALL PASS" : "FAILED: $fail") . "\n";
exit($fail === 0 ? 0 : 1);
