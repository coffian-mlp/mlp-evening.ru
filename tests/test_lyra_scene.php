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
ok(LyraArtist::sceneFromRaw(null) === null, 'null → null');
ok(LyraArtist::sceneFromRaw('') === null, 'пустой ответ → null');
ok(LyraArtist::sceneFromRaw('[РЕАКЦИЯ: laugh]') === null, 'только реакция → null');
ok(LyraArtist::sceneFromRaw('@Пшеница, "Конечно есть" — и так буднично! Я боюсь открывать этот блокнот...')
    === null, 'русская болтовня вместо сцены (прецедент 24.07) → null');
ok(LyraArtist::sceneFromRaw('Ха-ха :D ну ты даёшь') === null, 'русский со смайлом (латиница < 3 подряд) → null');

echo "\n" . ($fail === 0 ? "ALL PASS" : "FAILED: $fail") . "\n";
exit($fail === 0 ? 0 : 1);
