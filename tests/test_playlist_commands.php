<?php
require_once __DIR__ . '/../autoload.php';
use LLM\PlaylistCommand;
use Domain\BotCommandManager;
$fail = 0;
function expect($ok, $label) { global $fail; echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; }
foreach (['!хочу 7', '/хочу 7', '/передумала Dragonshy', '!желания', '/топ', '!плейлист'] as $text) expect(PlaylistCommand::match($text) !== null, $text);
foreach (['хочу 7', '!хочуу 7', 'привет !хочу 7', '!бан test'] as $text) expect(PlaylistCommand::match($text) === null, 'not playlist ' . $text);
$active = [['command_prefix' => '/хочу', 'handler_type' => 'playlist']];
expect(PlaylistCommand::matchActive($active, '!хочу 7') !== null, 'active scoped bang');
expect(PlaylistCommand::matchActive([], '!хочу 7') === null, 'disabled does not run');
expect(BotCommandManager::matchCommand([['command_prefix' => '/штош', 'handler_type' => 'recap']], '!штош') === null, 'old command slash only');
$reject = ['status' => 'rejected', 'code' => 'daily_limit', 'facts' => ['quota_remaining' => 0]];
expect(!PlaylistCommand::replyIsValid('Сегодня уже использованы три пожелания. Я записала голос!', $reject, 'Сегодня уже использованы три пожелания.'), 'false success rejected');
expect(PlaylistCommand::replyIsValid('Сегодня уже использованы три пожелания. Не записала новый голос.', $reject, 'Сегодня уже использованы три пожелания.'), 'truthful rejection');
$accept = ['status' => 'accepted', 'code' => 'accepted', 'facts' => ['episode_id' => 7, 'quota_remaining' => 2]];
expect(!PlaylistCommand::replyIsValid('№7. Серия 8 записана.', $accept, '№7'), 'wrong episode rejected');
expect(!PlaylistCommand::replyIsValid('№7. Осталось 3.', $accept, '№7'), 'wrong quota rejected');
expect(!PlaylistCommand::replyIsValid('№7 [[command:99]]', $accept, '№7'), 'model cannot create marker');
expect(!PlaylistCommand::replyIsValid('№7. Желание отклонено.', $accept, '№7'), 'false refusal after accepted rejected');
expect(!PlaylistCommand::replyIsValid('№7. Можешь выбрать ещё 3 желания.', $accept, '№7'), 'wrong quota alternate wording rejected');
foreach (['999999', 'серию 999999', 'S99E99', 'с4э1'] as $query) expect(PlaylistCommand::isExactReference($query), 'deterministic reference '.$query);
foreach (['серия про дракона', 'где серия 7 с драконом', 'Dragonshy'] as $query) expect(!PlaylistCommand::isExactReference($query), 'description/title not numeric reference '.$query);
expect(!PlaylistCommand::actionEnabled([], 'wish'), 'no active aliases deny pending wish');
expect(PlaylistCommand::actionEnabled([['command_prefix'=>'/передумала','handler_type'=>'playlist']], 'cancel'), 'one active cancel alias allows cancellation');
expect(!PlaylistCommand::actionEnabled([['command_prefix'=>'/хочу','handler_type'=>'text']], 'wish'), 'wrong handler does not enable action');
expect(!PlaylistCommand::replyIsValid('Пожелания не изменены. Твоё желание отменено.', ['status'=>'cancelled','code'=>'choice_cancelled','facts'=>[]], 'пожелания не изменены'), 'cancel choice does not claim wish cancellation');
echo $fail ? "FAILURES: $fail\n" : "ALL PASS\n"; exit($fail ? 1 : 0);
