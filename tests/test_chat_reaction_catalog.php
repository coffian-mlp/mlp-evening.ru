<?php
/** Проверка согласованности допустимых реакций UI/backend/Лиры (MLP-360). */
require_once __DIR__ . '/../autoload.php';

use LLM\ReactionParser;

$fail = 0;
function check($condition, $label) {
    global $fail;
    echo ($condition ? '[OK] ' : '[FAIL] ') . $label . "\n";
    if (!$condition) $fail++;
}

$root = dirname(__DIR__);
$js = file_get_contents($root . '/src/Components/Chat/assets/chat-core.js');
preg_match('/const REACTION_ICONS = \{([^}]+)\}/s', $js, $icons);
preg_match_all('/([a-z]+):\s*\x27([^\x27]+)\x27/u', $icons[1] ?? '', $pairs, PREG_SET_ORDER);
$map = [];
foreach ($pairs as $pair) $map[$pair[1]] = $pair[2];

$manager = file_get_contents($root . '/src/Domain/ChatManager.php');
preg_match('/function toggleReaction\([^)]*\).*?\$allowed = \[([^]]+)\]/s', $manager, $allowed);
preg_match_all('/\x27([a-z]+)\x27/', $allowed[1] ?? '', $keys);
$backend = $keys[1];
$parser = ReactionParser::ALLOWED;
$frontend = array_keys($map);
sort($backend); sort($parser); sort($frontend);
check(count($frontend) === 16, 'UI содержит 16 уникальных реакций');
check($backend === $frontend && $parser === $frontend, 'UI/backend/parser имеют одинаковый набор');

$old = ['like'=>'👍','heart'=>'❤️','laugh'=>'😂','wow'=>'😮','fire'=>'🔥','party'=>'🎉',
    'cool'=>'😎','think'=>'🤔','neutral'=>'😐','cry'=>'😢','eyes'=>'👀','dislike'=>'👎'];
foreach ($old as $key => $icon) check(($map[$key] ?? '') === $icon, "старый ключ $key и иконка сохранены");
$prompt = file_get_contents($root . '/src/LLM/LLMManager.php');
preg_match('/где X одно из: ([a-z, ]+)\./', $prompt, $list);
$promptKeys = array_map('trim', explode(',', $list[1] ?? ''));
sort($promptKeys);
check($promptKeys === $frontend, 'инструкция Лиры содержит тот же набор');
foreach (['skull'=>'💀','clown'=>'🤡','hundred'=>'💯','poop'=>'💩'] as $key => $icon) {
    check(($map[$key] ?? '') === $icon, "новая реакция $key отображается корректно");
    $parsed = ReactionParser::extract("[РЕАКЦИЯ: $key] ответ");
    check($parsed === ['reaction'=>$key, 'text'=>'ответ'], "маркер $key распознаётся и не протекает");
}
echo $fail ? "FAIL: $fail\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
