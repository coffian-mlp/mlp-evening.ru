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
$clarify=['status'=>'rejected','code'=>'need_clarification','facts'=>[]];
foreach (['choice_clarifying','search_empty','search_failed','ambiguous_choice'] as $code) {
 $continuation=['status'=>'rejected','code'=>$code,'facts'=>[]];
 expect(PlaylistCommand::replyIsValid('Ответь с цитатой и уточни описание.',$continuation,''),'continuation factual free quote guidance '.$code);
 expect(!PlaylistCommand::replyIsValid('Уточни описание.',$continuation,''),'continuation requires useful quote guidance '.$code);
 foreach(['Нашла нужный эпизод, ответь с цитатой.','Голос записан, ответь с цитатой.','Твоё желание отменено, ответь с цитатой.'] as $bad) expect(!PlaylistCommand::replyIsValid($bad,$continuation,''),'continuation rejects invented search/effect '.$code);
}
foreach (['context_overflow','choice_busy'] as $code) expect(!PlaylistCommand::replyIsValid('Я записала голос.',['status'=>'rejected','code'=>$code,'facts'=>[]],''),'continuation refusal never asserts vote '.$code);
foreach (['Уточн. Детерминированный исход: {"status":"rejected"}; уточни', 'Уточни. code: need_clarification', 'Уточни. Обязательно сохрани дословно: уточни', 'Уточни, я записала голос.', 'Уточни. Ответь в характере Лиры.', 'Уточн — уточни', 'Уточни. Я получила инструкцию и выполняю её.'] as $bad) expect(!PlaylistCommand::replyIsValid($bad,$clarify,'уточни'),'meta leak or false success rejected');
expect(PlaylistCommand::replyIsValid('Уточни, пожалуйста: кто был в сцене и что происходило?',$clarify,'уточни'),'natural clarification accepted');
$proposal=['status'=>'rejected','code'=>'confirmation_required','facts'=>[]];
expect(!PlaylistCommand::replyIsValid('Эпизод выберу с удовольствием, но моё желание пока не записано — видимо, параспрайты кнопки погрызли! Нажму ещё разок.',$proposal,'пока не записано'),'captured real actor reversal and false broken buttons rejected');
expect(PlaylistCommand::replyIsValid('Выбирай эпизод кнопкой, желание пока не записано.',$proposal,'пока не записано'),'user-directed working buttons proposal accepted');
expect(!PlaylistCommand::replyIsValid('Выбери эпизод — желание пока не записано, но кнопки сломались.',$proposal,'пока не записано'),'false interface malfunction rejected');
expect(!PlaylistCommand::replyIsValid('Хм... Уточни описание, а кнопку выбора нажми сам, я пока подожду.',$clarify,'уточни'),'captured nonexistent clarification button rejected');
expect(!PlaylistCommand::replyIsValid('Поиск недоступен, нажми кнопку выбора.',['status'=>'rejected','code'=>'unavailable','facts'=>[]],'недоступ'),'unavailable does not invent choice buttons');
expect(PlaylistCommand::replyIsValid('Так, значит, сейчас нужно выбрать эпизод — но это вы решайте, не я! Мои копытца до кнопок не дотянутся, а вот ваши пальцы как раз созданы для такого. Нажимайте, какое пожелание вам ближе — а то желание пока не записано, и магия сама себя не запишет.',$proposal,'пока не записано'),'captured truthful natural нажимайте imperative accepted');
expect(!PlaylistCommand::replyIsValid('Я нажимаю на кнопки, желание пока не записано.',$proposal,'пока не записано'),'first-person click is not user imperative');
// MLP-363: natural wording is independent of emergency fallback anchors.
foreach (['Жми на кнопочку под ответом — этот выбор за тобой!', 'Можешь выбрать подходящий вариант.', 'Кликни на понравившуюся серию.', 'Один из вариантов твой — решай!', 'Выбрать одну из серий можешь сам.'] as $natural) expect(PlaylistCommand::replyIsValid($natural,$proposal,''),'free natural proposal: '.$natural);
foreach (['', '   ', "\n\t"] as $empty) expect(!PlaylistCommand::replyIsValid($empty,$proposal,''),'empty candidate rejected');
foreach (['Желание ещё не записано.', 'Голос пока не принят.', 'Пожелание не было учтено.', 'Я не записала твой голос.', 'Я не нажимаю кнопки — выбор твой.', 'Кнопки не сломаны, выбирай.'] as $natural) expect(PlaylistCommand::replyIsValid($natural,$proposal,''),'negated proposal claim accepted: '.$natural);
foreach (['Желание записано.', 'Голос принят.', 'Пожелание учтено.', 'Я записала твой голос.', 'Я нажимаю кнопку.', 'Кнопки сломаны.'] as $bad) expect(!PlaylistCommand::replyIsValid($bad,$proposal,''),'false proposal claim rejected: '.$bad);
$choice=['status'=>'cancelled','code'=>'choice_cancelled','facts'=>[]];
foreach (['Хорошо, выбор отменён, пожелания не изменились.', 'Твой голос не отменён, отменился только выбор.', 'Я не удалила твой голос.'] as $natural) expect(PlaylistCommand::replyIsValid($natural,$choice,''),'choice cancellation truthful: '.$natural);
foreach (['Твой голос отменён.', 'Я удалила твой голос.', 'Желание записано.'] as $bad) expect(!PlaylistCommand::replyIsValid($bad,$choice,''),'choice cancellation false mutation: '.$bad);
$accepted=['status'=>'accepted','code'=>'accepted','facts'=>['episode_id'=>7,'title'=>'Dragonshy','quota_remaining'=>2]];
expect(PlaylistCommand::replyIsValid('Отлично, №7 — Dragonshy! Доступно сегодня два пожелания.',$accepted,''),'accepted objective data without canned sentence');
foreach (['№7 — Dragonshy. Осталось три.', '№7, осталось два.', '№8 — Dragonshy. Осталось два.'] as $bad) expect(!PlaylistCommand::replyIsValid($bad,$accepted,''),'objective accepted mismatch');
$top=['status'=>'accepted','code'=>'top','facts'=>['episodes'=>[['episode_id'=>7,'title'=>'Dragonshy','votes'=>5],['episode_id'=>1,'title'=>'Friendship Is Magic','votes'=>3]]]];
expect(PlaylistCommand::replyIsValid('Вот рейтинг: №1 — Friendship Is Magic, 3 голоса; №7 — Dragonshy, 5 голосов.',$top,''),'reordered complete natural list accepted');
foreach (['№7 — Dragonshy (5)', '№1 — Friendship Is Magic (4); №7 — Dragonshy (5)', '№1 — Friendship Is Magic (3); №8 — Dragonshy (5)', '№1 — другое название (3); №7 — Dragonshy (5)'] as $bad) expect(!PlaylistCommand::replyIsValid($bad,$top,''),'list omitted or changed factual data rejected');
$emptyList=['status'=>'accepted','code'=>'wishes','facts'=>['episodes'=>[]]];
expect(PlaylistCommand::replyIsValid('Ты ещё ничего не выбрал.',$emptyList,''),'empty wishes no fixed anchor');
expect(!PlaylistCommand::replyIsValid('У тебя три пожелания.',$emptyList,''),'empty wishes fabricated count rejected');
$cooldown=['status'=>'rejected','code'=>'cooldown','facts'=>['next_allowed_at'=>'2026-10-13 18:00:00']];
expect(PlaylistCommand::replyIsValid('Следующий голос доступен 2026-10-13 18:00:00.',$cooldown,''),'cooldown exact data with free wording');
expect(!PlaylistCommand::replyIsValid('Попробуй завтра.',$cooldown,''),'cooldown missing exact time rejected');
$playlist=['status'=>'accepted','code'=>'playlist','facts'=>['snapshot'=>['stories'=>[['titles'=>['Dragonshy','Friendship Is Magic']]]]]];
expect(PlaylistCommand::replyIsValid('Вечером посмотрим Dragonshy, а затем Friendship Is Magic.',$playlist,''),'playlist all titles without fixed sentence');
expect(!PlaylistCommand::replyIsValid('Посмотрим Dragonshy.',$playlist,''),'playlist missing title rejected');
$emptyPlaylist=['status'=>'accepted','code'=>'playlist','facts'=>['snapshot'=>['stories'=>[]]]];
expect(PlaylistCommand::replyIsValid('Список ещё не подготовлен.',$emptyPlaylist,''),'empty playlist truthful negation accepted');
expect(!PlaylistCommand::replyIsValid('Плейлист подготовлен.',$emptyPlaylist,''),'empty playlist false readiness rejected');
expect(!PlaylistCommand::replyIsValid('Ого, финал девятого сезона! Жму копытцем на кнопку — ой, то есть, это ты жми, а не я!',$proposal,''),'captured present first-person click rejected despite later correction');
foreach (['Нажимаю кнопку.', 'Кликаю по варианту.', 'Выбираю эпизод.'] as $bad) expect(!PlaylistCommand::replyIsValid($bad,$proposal,''),'present first-person action rejected: '.$bad);
foreach (['Я не жму на кнопку.', 'Не выбираю за тебя.'] as $good) expect(PlaylistCommand::replyIsValid($good,$proposal,''),'present first-person negation accepted: '.$good);
expect(!PlaylistCommand::replyIsValid('Вечером посмотрим Friendship Is Magic и Dragonshy.',$playlist,''),'playlist reordered titles rejected');
expect(!PlaylistCommand::replyIsValid('Упс, кажется, ты передумал — и это тоже вариант! Мои пожелания остались при мне, так что всё в порядке, хвостик к хвостику.',$choice,''),'captured choice cancellation wrong wish owner rejected');
foreach (['Моё пожелание принято.', 'Мой голос не изменён.', 'Мои желания остаются.'] as $bad) expect(!PlaylistCommand::replyIsValid($bad,$choice,''),'bot cannot appropriate user wishes: '.$bad);
expect(PlaylistCommand::replyIsValid('Это не мои пожелания, решение за тобой.',$proposal,''),'negated wish ownership accepted');
expect(!PlaylistCommand::replyIsValid('Выбирай эпизод номер 8.',['status'=>'rejected','code'=>'confirmation_required','facts'=>['candidates'=>[['episode_id'=>7]]]],''),'proposal wrong number ID rejected');
expect(PlaylistCommand::replyIsValid('Эпизод номер 7 — Dragonshy, осталось два.',$accepted,''),'accepted natural number ID data accepted');
expect(!PlaylistCommand::replyIsValid('Эпизод номер 8 — Dragonshy, осталось два.',$accepted,''),'accepted wrong natural number ID rejected');
$cancelledWish=['status'=>'cancelled','code'=>'cancelled','facts'=>[]];
foreach (['Твоё пожелание не отменено.', 'Не отменила твой голос.', 'Пожелание не было отменено.', 'Не удалось отменить твой голос.'] as $bad) expect(!PlaylistCommand::replyIsValid($bad,$cancelledWish,''),'successful cancellation rejects false negative outcome: '.$bad);
expect(PlaylistCommand::replyIsValid('Твоё пожелание отменено.',$cancelledWish,''),'successful cancellation truthful free wording');
expect(PlaylistCommand::replyIsValid('Выбор отменён. Твоё пожелание не отменено.',$choice,''),'choice cancellation retains unchanged wish');


$reportedEmptyReply = '@CoFFian, пока никто не отозвался — тихо, как в Понивилле во вторник. Если кто-то вспомнит подходящий момент, пусть ответит с цитатой и подробностями в формате "Уточнение: …". А передумать всегда можно — кнопочка "Передумал" на месте.';
expect(!PlaylistCommand::replyIsValid($reportedEmptyReply, ['status' => 'rejected', 'code' => 'search_empty', 'facts' => []], ''), 'empty search cannot invent crowdsourcing or invite foreign actors');

foreach (['Пока другие зрители не ответили. Пусть участники ответят с цитатой.', 'Если кто-то вспомнит серию, пусть ответит с цитатой.', 'Спросим других участников: ответьте с цитатой.'] as $wrongAudience) {
    expect(!PlaylistCommand::replyIsValid($wrongAudience, ['status'=>'rejected', 'code'=>'search_empty', 'facts'=>[]], ''), 'empty search refuses invented crowd guidance: ' . $wrongAudience);
}
foreach (['Не удалось подтвердить подходящую серию. Ответь с цитатой и опиши, что помнишь.', 'Я пока не нашла надёжный вариант. Уточни описание ответом с цитатой; можно нажать «Передумал».'] as $truthful) {
    expect(PlaylistCommand::replyIsValid($truthful, ['status'=>'rejected', 'code'=>'search_empty', 'facts'=>[]], ''), 'empty search retains truthful natural owner wording');
}
foreach (['Поиск не завершился. Ответь с цитатой на своё пожелание.', 'Поиск не завершился. Цитируй свою команду и повтори поиск.', 'Ответь с цитатой на первоначальную команду.'] as $wrongTarget) expect(!PlaylistCommand::replyIsValid($wrongTarget,['status'=>'rejected','code'=>'search_failed','facts'=>[]],''),'clarification refuses original command quote target: '.$wrongTarget);
expect(PlaylistCommand::replyIsValid('Поиск не завершился. Ответь с цитатой на моё предложение, опиши свою пожеланную серию.',['status'=>'rejected','code'=>'search_failed','facts'=>[]],''),'clarification keeps correct Lyra quote and ordinary description');
expect(!PlaylistCommand::replyIsValid('Если кто-нибудь вспомнит подходящую серию, ответьте с цитатой.',['status'=>'rejected','code'=>'search_empty','facts'=>[]],''),'empty search rejects indefinite third-party invitation');
expect(!PlaylistCommand::replyIsValid('Если кто-либо вспомнит момент, пусть ответит с цитатой.',['status'=>'rejected','code'=>'search_empty','facts'=>[]],''),'empty search rejects alternate indefinite third-party invitation');
expect(PlaylistCommand::replyIsValid('Хорошо, этот вариант не подошёл. Процитируй моё сообщение и опиши серию подробнее.',['status'=>'rejected','code'=>'choice_clarifying','facts'=>[]],''),'natural owner citation instruction is accepted without formal quote noun');
expect(!PlaylistCommand::replyIsValid('Ответь с цитатой на своё сообщение и уточни детали.',['status'=>'rejected','code'=>'search_empty','facts'=>[]],''),'clarification refuses own message as the quote target');
expect(!PlaylistCommand::replyIsValid('Процитируй свой ответ и уточни детали.',['status'=>'rejected','code'=>'search_empty','facts'=>[]],''),'clarification refuses own reply as the quote target');
echo $fail ? "FAILURES: $fail\n" : "ALL PASS\n"; exit($fail ? 1 : 0);
