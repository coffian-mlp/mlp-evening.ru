<?php
namespace LLM {
    // Replace only the external HTTP transport; manager and provider remain real.
    function curl_init($url) { return new \stdClass(); }
    function curl_setopt($handle, $option, $value) {
        $GLOBALS['playlist_scoped_options'][$option] = $value;
        return true;
    }
    function curl_exec($handle) {
        $GLOBALS['playlist_scoped_calls']++;
        $GLOBALS['playlist_scoped_payloads'][] = json_decode($GLOBALS['playlist_scoped_options'][CURLOPT_POSTFIELDS],true);
        if (isset($GLOBALS['playlist_scoped_responses']) && $GLOBALS['playlist_scoped_responses']) $GLOBALS['playlist_scoped_text']=array_shift($GLOBALS['playlist_scoped_responses']);
        if ($GLOBALS['playlist_scoped_throw'] ?? false) throw new \RuntimeException('Fixture transport failure');
        return json_encode(['choices' => [['finish_reason' => 'stop', 'message' => [
            'content' => $GLOBALS['playlist_scoped_text'],
            'annotations' => $GLOBALS['playlist_scoped_annotations'],
        ]]]]);
    }
    function curl_getinfo($handle, $option) { return 200; }
    function curl_error($handle) { return ''; }
    function curl_close($handle) {}
}
namespace {
    require_once __DIR__ . '/integration_helpers.php';
    if ((it_config()['db']['host'] ?? '') !== 'db') it_skip('requires isolated db');
    $probe = it_require_db();
    $probe->close();
    $db = Infra\Database::getInstance()->getConnection();
    $GLOBALS['playlist_scoped_calls'] = 0;
    $GLOBALS['playlist_scoped_text'] = '{"candidates":[]}';
    $GLOBALS['playlist_scoped_annotations'] = [['url_citation' => [
        'url' => 'https://example.org/episode', 'title' => 'Fixture source',
    ]]];
    try {
        Infra\Transaction::run($db, static function () {
            $config = Infra\ConfigManager::getInstance();
            foreach (['ai_enabled' => '1', 'ai_live_confirm' => '1', 'ai_bot_user_id' => '1',
                'ai_primary_provider' => 'routerai', 'ai_routerai_key' => 'fixture-key',
                'ai_routerai_model' => 'fixture-main', 'ai_fast_model' => 'fixture-fast',
                'ai_proxy_url' => '', 'ai_openai_key' => '', 'ai_openrouter_key' => '',
                'ai_yandex_key' => '', 'ai_gigachat_key' => ''] as $key => $value) {
                $config->setOption($key, $value);
            }
            $manager = new LLM\LLMManager();
            $search = json_decode($manager->generateSearchUtility([], 'Fixture query', time() + 12), true);
            check($search['sources'][0]['url'] === 'https://example.org/episode', 'manager returns actual transport citations');
            $payload = json_decode($GLOBALS['playlist_scoped_options'][CURLOPT_POSTFIELDS], true);
            check($payload['plugins'][0]['max_results'] === 3 && $payload['reasoning']['effort'] === 'low', 'search manager applies scoped web and reasoning');
            check($GLOBALS['playlist_scoped_options'][CURLOPT_TIMEOUT] <= 12, 'search timeout respects remaining deadline');
            $calls = $GLOBALS['playlist_scoped_calls'];
            check($manager->generateSearchUtility([], 'Expired query', time() + 4) === null && $GLOBALS['playlist_scoped_calls'] === $calls, 'search insufficient budget makes no HTTP call');
            $GLOBALS['playlist_scoped_text']='Twilight Sparkle gets wings';
            check($manager->generateSearchQueryUtility([['role'=>'user','content'=>'Твайлайт получила крылья']],'Translate query',time()+8)==='Twilight Sparkle gets wings','actual fast normalizer executes external transport');
            $normalizerPayload=json_decode($GLOBALS['playlist_scoped_options'][CURLOPT_POSTFIELDS],true);
            check($normalizerPayload['model']==='fixture-fast' && !isset($normalizerPayload['plugins']) && $GLOBALS['playlist_scoped_options'][CURLOPT_TIMEOUT]<=8,'normalizer fast clone no web plugin timeout8');
            $calls=$GLOBALS['playlist_scoped_calls'];
            check($manager->generateSearchQueryUtility([],'Expired query',time()+4)===null && $GLOBALS['playlist_scoped_calls']===$calls,'normalizer insufficient remaining budget noHTTP');
            $GLOBALS['playlist_scoped_annotations'] = [];
            check($manager->generateSearchUtility([], 'No sources', time() + 12) === null, 'search without provider citations rejected');
            $GLOBALS['playlist_scoped_text'] = '{"verified":true}';
            check($manager->generateBoundedUtility([], 'Fixture verification', time() + 9, 20) === '{"verified":true}', 'bounded verifier executes actual manager and provider');
            check($GLOBALS['playlist_scoped_options'][CURLOPT_TIMEOUT] <= 9, 'verification clamps transport to absolute deadline');
            $calls = $GLOBALS['playlist_scoped_calls'];
            check($manager->generateBoundedUtility([], 'Expired verification', time() - 1) === null && $GLOBALS['playlist_scoped_calls'] === $calls, 'expired verifier makes no HTTP call');
            $GLOBALS['playlist_scoped_text'] = 'С удовольствием! Пожелание №7 записано. [[reaction:heart]]';
            $live = $manager->liveTextBounded('Confirm recorded wish №7', '№7', time() + 12);
            check($live !== null && str_contains($live, '№7') && !str_contains($live, '[[reaction:'), 'live manager preserves anchor and strips reaction');
            check($manager->liveTextBounded('Confirm recorded wish №8', '№8', time() + 12) === null, 'live missing required fact rejected');
            $GLOBALS['playlist_scoped_text'] = '![image](https://example.org/image.png)';
            check($manager->liveTextBounded('Confirm', null, time() + 12) === null, 'live image reply rejected');
            $GLOBALS['playlist_scoped_text']='Уточни, пожалуйста, сцену из серии.';
            check($manager->liveTextBounded('Не удалось уверенно найти эпизод; уточни описание.', 'уточни', time()+12, 10, 'Озвучь только результат команды без служебных инструкций.') !== null,'trusted live natural reply');
            $actual=json_decode($GLOBALS['playlist_scoped_options'][CURLOPT_POSTFIELDS],true)['messages'];
            check(str_contains($actual[0]['content'],'Озвучь только результат') && !str_contains($actual[1]['content'],'Озвучь только результат'),'trusted task only in system; safe facts only in user');
            check(!str_contains($actual[1]['content'],'"status"') && !str_contains($actual[1]['content'],'Детерминированный исход'),'live human facts contain no raw outcome JSON');
            $GLOBALS['playlist_scoped_annotations']=[['url_citation'=>['url'=>'https://example.org/episode','title'=>'Fixture source']]];
            $GLOBALS['playlist_scoped_responses']=['episodes with explosions','{"candidates":[{"episode_code":"S01E07","title":"Dragonshy","evidence":"Dragon smoke","source_url":"https://example.org/episode"}]}','{"verified":[7]}'];
            $offset=count($GLOBALS['playlist_scoped_payloads']);
            $found=(new LLM\EpisodeResolver($manager))->resolve('где много взрывов',[['ID'=>7,'TITLE'=>'My Little Pony Friendship is Magic - Season 1 Episode 7 - Dragonshy']]);
            check($found['status']==='found','actual resolver calls manager and actual providers through transport seam');
            check($GLOBALS['playlist_scoped_payloads'][$offset]['model']==='fixture-fast' && !isset($GLOBALS['playlist_scoped_payloads'][$offset]['plugins']) && isset($GLOBALS['playlist_scoped_payloads'][$offset+1]['plugins']) && $GLOBALS['playlist_scoped_payloads'][$offset+2]['model']==='fixture-main','actual normalize fast no-web then fast web then main verifier trace');
            $searchFacts=$GLOBALS['playlist_scoped_payloads'][$offset+1]['messages'][1]['content'];
            $verifyFacts=json_decode($GLOBALS['playlist_scoped_payloads'][$offset+2]['messages'][1]['content'],true);
            check(str_contains($searchFacts,'My Little Pony') && !str_contains($searchFacts,'Lyra Heartstrings') && str_contains($searchFacts,'explosions'),'actual search scope includes subject identity query');
            check(str_contains($verifyFacts['subject'],'My Little Pony') && $verifyFacts['original_query']==='где много взрывов' && $verifyFacts['search_query']===$searchFacts && !isset($verifyFacts['recipient_identity']),'actual verifier preserves thematic scope');
            $actor=(new Domain\UserManager())->createUser('it_mlp362_'.bin2hex(random_bytes(6)),bin2hex(random_bytes(24)),'user');
            $bot=(new Domain\UserManager())->createUser('it_mlp362_bot_'.bin2hex(random_bytes(6)),bin2hex(random_bytes(24)),'user');
            $config->setOption('ai_bot_user_id',(string)$bot);
            $manager=new LLM\LLMManager();
            $db=Infra\Database::getInstance()->getConnection();
            $db->query("UPDATE bot_commands SET is_active=1 WHERE command_prefix='/хочу'");
            $chat=new Domain\ChatManager();
            $run=static function($query) use($chat,$actor,$manager,$db){
                $db->query('UPDATE chat_messages SET created_at=UTC_TIMESTAMP()-INTERVAL 5 SECOND WHERE user_id='.(int)$actor);
                $text='!хочу '.$query;
                $mid=$chat->addMessage($actor,'fixture',$text);
                check($manager->processTrigger('dynamic_command',['message_id'=>$mid,'user_id'=>$actor,'message'=>$text,'command'=>['handler_type'=>'playlist']]),'actual dispatcher command handled');
                return $chat->findBotReplyTo($mid);
            };
            $GLOBALS['playlist_scoped_text']='Уточни. Детерминированный исход: {"status":"rejected"}';
            $GLOBALS['playlist_scoped_responses']=['episodes with explosions','{"candidates":[]}', $GLOBALS['playlist_scoped_text']];
            $reply=$run('где много взрывов');
            check(str_contains($reply['raw_message'],'уточни описание') && !str_contains($reply['raw_message'],'Детерминированный'),'actual dispatcher rejects meta leak and publishes clean fallback');
            $GLOBALS['playlist_scoped_responses']=['episodes with explosions','{"candidates":[]}','Хм... Уточни описание, а кнопку выбора нажми сам, я пока подожду.'];
            $missingButtons=$run('историю с очень многими взрывами');
            check(str_contains($missingButtons['raw_message'],'уточни описание') && !str_contains($missingButtons['raw_message'],'кноп'),'actual clarification rejects captured nonexistent button instruction');
            $clarificationSystem=json_decode($GLOBALS['playlist_scoped_options'][CURLOPT_POSTFIELDS],true)['messages'][0]['content'];
            check(str_contains($clarificationSystem,'кнопок выбора нет') && !str_contains($clarificationSystem,'Кнопки исправны'),'clarification trusted task scoped to no choices instead of proposal instructions');
            $GLOBALS['playlist_scoped_text']='Жми на кнопочку под ответом — этот выбор за тобой!';
            $calls=$GLOBALS['playlist_scoped_calls'];
            $reply=$run('самую первую серию');
            check(str_contains($reply['raw_message'],'Жми на кнопочку') && str_contains($reply['raw_message'],'[[command:') && $GLOBALS['playlist_scoped_calls']===$calls+1,'actual semantic dispatcher creates proposal and calls live only');
            check(count((new Domain\EpisodeManager())->getUserWishes($actor))===0,'semantic dispatcher spends no vote before confirmation');
            check($GLOBALS['playlist_scoped_options'][CURLOPT_TIMEOUT] <= 10,'actual proposal one live call retains bounded timeout');
            $human=json_decode($GLOBALS['playlist_scoped_options'][CURLOPT_POSTFIELDS],true)['messages'][1]['content'];
            check(!str_contains($human,'"status"') && !str_contains($human,'confirmation_required'),'actual command producer passes human facts only');
            check(!str_contains($human,'Выбери эпизод кнопкой') && !str_contains($human,'желание пока не записано') && str_contains($human,'Выбор делает пользователь'),'USER state is independent of emergency fallback phrasing');
            $livePayload=json_decode($GLOBALS['playlist_scoped_options'][CURLOPT_POSTFIELDS],true);
            check(str_contains($livePayload['messages'][0]['content'],'без обязательных слов') && !str_contains($livePayload['messages'][0]['content'],'Сохрани дословно'),'SYSTEM keeps free phrasing without lexical anchors');
            preg_match('/\[\[command:(\d+)\]\]/',$reply['raw_message'],$proposalMarker);
            $proposalId=(int)$proposalMarker[1];
            $sourceId=(int)$db->query('SELECT source_message_id FROM command_interactions WHERE id='.$proposalId)->fetch_assoc()['source_message_id'];
            $calls=$GLOBALS['playlist_scoped_calls'];
            $manager->processTrigger('dynamic_command',['message_id'=>$sourceId,'user_id'=>$actor,'message'=>'!хочу самую первую серию','command'=>['handler_type'=>'playlist']]);
            check($GLOBALS['playlist_scoped_calls']===$calls && $chat->findBotReplyTo($sourceId)['id']===$reply['id'],'delivery retry does not regenerate or duplicate natural proposal');
            (new Domain\CommandInteractionManager(LLM\PlaylistCommand::interactionRegistry()))->consume($proposalId,'cancel',$actor);
            $GLOBALS['playlist_scoped_text']='Упс, кажется, ты передумал! Мои пожелания остались при мне.';
            check($manager->processTrigger('dynamic_command',['interaction_id'=>$proposalId,'user_id'=>$actor,'command'=>['handler_type'=>'command_interaction_reply']]),'actual choice cancellation reply dispatcher handled');
            $cancelReply=$chat->findBotReplyTo((int)$reply['id']);
            check(str_contains($cancelReply['raw_message'],'пожелания не изменены') && !str_contains($cancelReply['raw_message'],'Мои пожелания'),'actual choice cancellation rejects captured wrong wish owner');
            $GLOBALS['playlist_scoped_text']='Твой голос принят.';
            $falsePassive=$run('самую первую серию');
            check(str_contains($falsePassive['raw_message'],'Выбери эпизод кнопкой') && !str_contains($falsePassive['raw_message'],'голос принят'),'actual producer rejects affirmative passive mutation before selection');
            $config->setOption('ai_live_confirm','0');
            $calls=$GLOBALS['playlist_scoped_calls'];
            $disabledReply=$run('самую первую серию');
            check(str_contains($disabledReply['raw_message'],'Выбери эпизод кнопкой') && $GLOBALS['playlist_scoped_calls']===$calls,'actual producer disabled live fallback without transport');
            $config->setOption('ai_live_confirm','1');
            $GLOBALS['playlist_scoped_throw']=true;
            $outageReply=$run('самую первую серию');
            check(str_contains($outageReply['raw_message'],'Выбери эпизод кнопкой'),'actual producer live transport outage uses truthful fallback');
            $GLOBALS['playlist_scoped_throw']=false;
            $GLOBALS['playlist_scoped_text']='Ого, финал девятого сезона! Жму копытцем на кнопку — ой, то есть, это ты жми, а не я!';
            $presentActor=$run('первый эпизод');
            check(str_contains($presentActor['raw_message'],'Выбери эпизод кнопкой') && !str_contains($presentActor['raw_message'],'Жму копытцем'),'actual producer rejects captured present first-person click');
            $GLOBALS['playlist_scoped_text']='Эпизод выберу с удовольствием, но моё желание пока не записано — видимо, параспрайты кнопки погрызли! Нажму ещё разок.';
            $actorReversal=$run('первую серию');
            check(str_contains($actorReversal['raw_message'],'Выбери эпизод кнопкой') && !str_contains($actorReversal['raw_message'],'моё желание') && !str_contains($actorReversal['raw_message'],'погрызли'),'actual command rejects captured reversed actor false UI failure and uses clean fallback');
            $pairIds=[];
            foreach ([25,26] as $number) {
                $part=$number-24;$title='My Little Pony Friendship is Magic - Season 91 Episode '.$number.' - Fixture Two Story, Part '.$part;
                $stmt=$db->prepare('INSERT INTO episode_list(TITLE,LENGTH) VALUES(?,1)');$stmt->bind_param('s',$title);$stmt->execute();$pairIds[]=(int)$db->insert_id;
            }
            $db->query('UPDATE episode_list SET TWOPART_ID='.$pairIds[1].' WHERE ID='.$pairIds[0]);
            $db->query('UPDATE episode_list SET TWOPART_ID='.$pairIds[0].' WHERE ID='.$pairIds[1]);
            $GLOBALS['playlist_scoped_responses']=['episode two-part story',json_encode(['candidates'=>[['episode_code'=>'S91E25-S91E26','title'=>'Fixture Two Story','evidence'=>'Two-part story','source_url'=>'https://example.org/episode']]]),json_encode(['verified'=>$pairIds]),'Выбери эпизод кнопкой — желание пока не записано.'];
            $pairReply=$run('историю с двумя частями');
            preg_match('/\[\[command:(\d+)\]\]/',$pairReply['raw_message'],$pairMarker);
            $pairInteraction=(int)($pairMarker[1]??0);
            check($pairInteraction>0,'actual command cited range creates bound pair options');
            $pairManager=new Domain\CommandInteractionManager(LLM\PlaylistCommand::interactionRegistry());
            $pairOptions=$pairManager->readPublic($pairInteraction,$actor,(int)$pairReply['id']);
            check(count($pairOptions['options'])===3 && str_contains($pairOptions['options'][0]['label'],'Part 1') && str_contains($pairOptions['options'][1]['label'],'Part 2'),'actual range UI contract contains two ordered parts and cancellation');
            $pairResult=$pairManager->consume($pairInteraction,'episode_'.$pairIds[0],$actor);
            check($pairResult['status']==='accepted','one range button confirms its separate wish');
            $wishes=(new Domain\EpisodeManager())->getUserWishes($actor);
            check(count($wishes)===1 && (int)$wishes[0]['episode_id']===$pairIds[0],'pair selection never creates hidden second vote');
            $db->query("UPDATE bot_commands SET is_active=1 WHERE command_prefix IN('/передумал','/передумала')");
            $GLOBALS['playlist_scoped_text']='Не отменила твой голос.';
            $cancelText='!передумал '.$pairIds[0];$cancelSource=$chat->addMessage($actor,'fixture',$cancelText);
            check($manager->processTrigger('dynamic_command',['message_id'=>$cancelSource,'user_id'=>$actor,'message'=>$cancelText,'command'=>['handler_type'=>'playlist']]),'actual successful wish cancellation dispatcher handled');
            $falseCancelReply=$chat->findBotReplyTo($cancelSource);
            check(str_contains($falseCancelReply['raw_message'],'Желание отменено.') && !str_contains($falseCancelReply['raw_message'],'Не отменила'),'actual successful cancellation false negative LLM uses truthful fallback');
            check(count((new Domain\EpisodeManager())->getUserWishes($actor))===0,'actual cancelled wish removed independently of invalid model wording');
            (new Domain\EpisodeManager())->wish($actor,$pairIds[1],'fixture-restore-worker-count');
            $db->query("UPDATE llm_jobs SET run_after=UTC_TIMESTAMP()+INTERVAL 1 DAY WHERE status='pending'");
            $db->query("INSERT INTO episode_list(TITLE,LENGTH) VALUES('My Little Pony: The Movie (2017)',4)");
            $workerText='!хочу полнометражку';$workerMid=$chat->addMessage($actor,'fixture',$workerText);
            $jobId=(new LLM\JobQueue())->enqueue('dynamic_command',['message_id'=>$workerMid,'user_id'=>$actor,'message'=>$workerText,'command'=>['handler_type'=>'playlist']],0);
            $worker=new LLM\BotWorker();(new ReflectionMethod($worker,'reactive'))->invoke($worker);
            $workerReply=$chat->findBotReplyTo($workerMid);
            check($workerReply && str_contains($workerReply['raw_message'],'[[command:'),'actual BotWorker reactive queue delivers semantic movie proposal');
            check($db->query('SELECT status FROM llm_jobs WHERE id='.(int)$jobId)->fetch_assoc()['status']==='done','actual worker marks command job done');
            check(count((new Domain\EpisodeManager())->getUserWishes($actor))===1,'actual worker proposal does not mutate existing votes');
            $config->setOption('ai_live_confirm', '0');
            $calls = $GLOBALS['playlist_scoped_calls'];
            check($manager->liveTextBounded('Confirm', null, time() + 12) === null && $GLOBALS['playlist_scoped_calls'] === $calls, 'disabled live makes no HTTP call');
            $config->setOption('ai_live_confirm', '1');
            $GLOBALS['playlist_scoped_throw'] = true;
            check($manager->generateSearchQueryUtility([], 'Transport failure', time()+8) === null, 'normalizer transport failure returns fallback signal');
            check($manager->generateSearchUtility([], 'Transport failure', time() + 12) === null, 'search transport failure returns unavailable');
            check($manager->generateBoundedUtility([], 'Transport failure', time() + 12) === null, 'verification transport failure returns unavailable');
            $GLOBALS['playlist_scoped_throw'] = false;
            $config->setOption('ai_enabled', '0');
            $calls = $GLOBALS['playlist_scoped_calls'];
            check($manager->generateSearchUtility([], 'Disabled AI', time() + 12) === null
                && $manager->generateBoundedUtility([], 'Disabled AI', time() + 12) === null
                && $manager->generateSearchQueryUtility([], 'Disabled AI', time()+8) === null
                && $GLOBALS['playlist_scoped_calls'] === $calls, 'disabled AI skips search and verifier transport');
            throw new RuntimeException('scoped fixture rollback');
        });
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'scoped fixture rollback') throw $error;
    } finally {
        Infra\ConfigManager::getInstance()->flushCache();
    }
    it_done();
}
