<?php
use LLM\ReplyPolicy;
/**
 * Юнит-тест ReplyPolicy — адаптивный дебаунс/анти-спам бота (без БД).
 * Запуск: php tests/test_reply_policy.php
 */

require_once __DIR__ . '/../src/LLM/ReplyPolicy.php';

$fail = 0;
function check($cond, $label) {
    global $fail;
    echo ($cond ? "  [OK] " : "  [FAIL] ") . $label . "\n";
    if (!$cond) $fail++;
}

$base = ['spam_threshold' => 4, 'reply_min_gap' => 20, 'now' => 1000, 'last_bot_reply_ts' => null];

$m = fn($id, $uid, $u) => ['message_id' => $id, 'user_id' => $uid, 'username' => $u, 'message' => 'hi'];

echo "== Пусто / rate-limit ==\n";
$d = ReplyPolicy::decide([], $base);
check($d['action'] === 'skip' && $d['reason'] === 'no_jobs', 'пусто -> skip/no_jobs');

$d = ReplyPolicy::decide([$m(1, 1, 'A')], ['spam_threshold'=>4,'reply_min_gap'=>20,'now'=>1000,'last_bot_reply_ts'=>990]);
check($d['action'] === 'skip' && $d['reason'] === 'rate_limited', 'ответил 10с назад (gap 20) -> skip/rate_limited');

$d = ReplyPolicy::decide([$m(1, 1, 'A')], ['spam_threshold'=>4,'reply_min_gap'=>20,'now'=>1000,'last_bot_reply_ts'=>970]);
check($d['action'] === 'reply', 'ответил 30с назад -> отвечаем');

echo "\n== Одно упоминание (1:1, с цитатой) ==\n";
$d = ReplyPolicy::decide([$m(42, 1, 'CoFFian')], $base);
check($d['action'] === 'reply' && $d['mode'] === 'single', 'single -> reply/single');
check($d['quote_message_id'] === 42, 'ставит цитату на message_id=42');
check($d['askers'] === ['CoFFian'], 'адресат — автор');

echo "\n== Тихо (<= порога): адресуем всех, без цитаты ==\n";
$d = ReplyPolicy::decide([$m(1,1,'A'), $m(2,2,'B'), $m(3,3,'C')], $base);
check($d['mode'] === 'address_all', '3 упоминания -> address_all');
check($d['quote_message_id'] === null, 'сводный/многоадресный -> без цитаты');
check($d['askers'] === ['A','B','C'], 'адресаты — все спросившие');

echo "\n== Дедуп адресатов ==\n";
$d = ReplyPolicy::decide([$m(1,1,'A'), $m(2,1,'A'), $m(3,2,'B')], $base);
check($d['askers'] === ['A','B'], 'один юзер несколько раз -> один адресат');

echo "\n== Спам (> порога): сводный, без цитаты, максимум 3 адресата ==\n";
$pending = [];
for ($i = 1; $i <= 6; $i++) $pending[] = $m($i, $i, 'U' . $i);
$d = ReplyPolicy::decide($pending, $base);
check($d['mode'] === 'coalesce', '6 упоминаний (>4) -> coalesce');
check($d['quote_message_id'] === null, 'coalesce -> без цитаты');
check(count($d['askers']) === 3, 'адресатов не больше 3');

echo "\n== Граница порога (ровно spam_threshold = тихо) ==\n";
$pending = [];
for ($i = 1; $i <= 4; $i++) $pending[] = $m($i, $i, 'U' . $i);
$d = ReplyPolicy::decide($pending, $base);
check($d['mode'] === 'address_all', '4 упоминания (== порог) -> ещё address_all');

echo "\n== instruction() под режим ==\n";
check(ReplyPolicy::instruction(['mode'=>'single','askers'=>['A']]) === '', 'single -> пустая инструкция');
$ins = ReplyPolicy::instruction(['mode'=>'address_all','askers'=>['A','B']]);
check(mb_strpos($ins, '@A, @B') !== false, 'address_all -> перечисляет @A, @B');
$insC = ReplyPolicy::instruction(['mode'=>'coalesce','askers'=>['A','B','C']]);
check(mb_strpos($insC, 'ОДНО') !== false, 'coalesce -> просит одно общее сообщение');

echo "\n" . ($fail === 0 ? "ALL PASS\n" : "FAILURES: $fail\n");
exit($fail === 0 ? 0 : 1);
