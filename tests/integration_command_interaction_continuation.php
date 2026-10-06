<?php
require_once __DIR__ . '/integration_helpers.php';
if ((it_config()['db']['host'] ?? '') !== 'db') it_skip('Continuation requires isolated db');
it_require_db();
$db = Infra\Database::getInstance()->getConnection();
$config = Infra\ConfigManager::getInstance();
$users = new Domain\UserManager(); $chat = new Domain\ChatManager();
$savedBot = $config->getOption('ai_bot_user_id', null);
$suffix = bin2hex(random_bytes(6)); $ids = []; $messages = []; $sources = [];
$effects = 0;
$handler = ['visibility' => 'public', 'permission' => static fn() => true,
    'execute' => function ($actor, $payload) use (&$effects) { $effects++; return ['status' => 'accepted', 'code' => 'synthetic_done', 'facts' => $payload]; },
    'continuation' => [
        'permission' => static fn() => true,
        'classify' => static fn($text, $state) => ['intent' => 'clarification', 'text' => $text],
        'prepare' => static function ($context, $input) {
            if (mb_strlen($input['text'] ?? '') > 300) throw new Core\UserError('overflow');
            $context['refinements'][] = $input['text'] ?? '';
            return $context;
        },
        'resolve' => static fn($context, $deadline) => ['status' => 'candidates', 'options' => [['key' => 'yes', 'label' => 'Synthetic candidate', 'payload' => ['value' => 2]]]],
        'format' => static fn($state, $result) => ['status' => 'accepted', 'code' => 'synthetic', 'facts' => []],
    ]];
$manager = new Domain\CommandInteractionManager(['synthetic' => $handler, 'other' => $handler]);
$denied = static function ($call, $label) { try { $call(); check(false, $label); } catch (Core\UserError $error) { check(true, $label); } };
$contextOf = static function ($id) use ($db) { $json = $db->query('SELECT context_json FROM command_interactions WHERE id=' . (int)$id)->fetch_assoc()['context_json']; return $json === null ? null : json_decode($json, true); };
$setContext = static function ($id, $context) use ($db) { $json = json_encode($context); $stmt = $db->prepare('UPDATE command_interactions SET context_json=? WHERE id=?'); $stmt->bind_param('si', $json, $id); $stmt->execute(); };
try {
    foreach (['owner','foreign','bot'] as $role) $ids[$role] = $users->createUser('it_mlp364_' . $role . '_' . $suffix, bin2hex(random_bytes(20)), 'user');
    $config->setOption('ai_bot_user_id', (string)$ids['bot']);
    $message = function ($text, $actor = null) use ($chat, &$messages, $ids, $suffix) {
        $id = $chat->addMessage($actor ?? $ids['owner'], 'Synthetic', $text . ' ' . $suffix . ' ' . bin2hex(random_bytes(3))); $messages[] = $id; return $id;
    };
    $make = function ($state = 'pending', $type = 'synthetic') use ($manager, $message, &$sources, $ids) {
        $source = $message('source'); $sources[] = $source;
        $id = $manager->createContinuation($type, $ids['owner'], $source, $state === 'pending' ? [['key' => 'yes', 'label' => 'Candidate', 'payload' => ['value' => 1]]] : [], ['original' => 'synthetic', 'refinements' => []], $state);
        $bot = $message('proposal [[command:' . $id . ']]', $ids['bot']); $manager->bindMessage($id, $bot);
        return [$id, $source, $bot];
    };
    foreach (['"Уточнение"', 'literal &quot;', 'A & B', "<plot> 'pony'"] as $bindingText) {
        $source = $message('source ' . $bindingText); $sources[] = $source;
        $id = $manager->createContinuation('synthetic', $ids['owner'], $source, [], ['original' => $bindingText], 'clarifying');
        $bot = $message('proposal ' . $bindingText . ' [[command:' . $id . ']]', $ids['bot']);
        $manager->bindMessage($id, $bot);
        check($manager->readPublic($id, $ids['owner'], $bot)['state'] === 'clarifying', 'unchanged escaped source and proposal binding remains readable: ' . $bindingText);
    }
    $source = $message('edited before acceptance'); $sources[] = $source;
    $chat->editMessage($source, $ids['owner'], 'edited before acceptance');
    $id = $manager->createContinuation('synthetic', $ids['owner'], $source, [], ['original' => 'edited'], 'clarifying');
    $bot = $message('edited-source proposal [[command:' . $id . ']]', $ids['bot']); $manager->bindMessage($id, $bot);
    check($manager->readPublic($id, $ids['owner'], $bot)['state'] === 'clarifying', 'SQL and public ISO edited_at preserve the same message version');
    $source = $message('quoted accepted source "pony" & literal &quot;'); $sources[] = $source;
    $chat->editMessage($source, $ids['owner'], 'quoted accepted source "pony" & literal &quot;');
    $snapshot = $chat->getMessageById($source); $version = Domain\ChatManager::interactionSourceVersion($snapshot);
    $localAccepts = 0; $acceptance = function () use (&$localAccepts) { $localAccepts++; return ['kind'=>'search']; };
    $manager->acceptNewCommand('synthetic', $ids['owner'], $source, $acceptance, $version);
    $manager->acceptNewCommand('synthetic', $ids['owner'], $source, $acceptance, $version);
    check($localAccepts === 1, 'quoted edited-before-accept source uses exact canonical replay once');
    $id = $manager->createContinuation('synthetic', $ids['owner'], $source, [['key'=>'yes','label'=>'Candidate','payload'=>[]]], ['original'=>'quoted']);
    $bot = $manager->publishProposal($id, 'proposal "pony" & literal &quot; [[command:' . $id . ']]'); $messages[] = $bot;
    check($manager->readPublic($id, $ids['owner'], $bot)['can_act'], 'quoted proposal owner remains actionable after acceptance upgrade');
    $locked = Infra\Transaction::run($db, fn() => $chat->lockInteractionMessages([['id'=>$source,'user_id'=>$ids['owner'],'version'=>$version]])[$source]);
    check($snapshot['interaction_source_version'] === $locked['interaction_source_version'], 'authoritative read and locked snapshots expose equal canonical versions');
    $control = $message('quoted refine "pony" & literal &quot;'); $controlVersion = Domain\ChatManager::interactionSourceVersion($chat->getMessageById($control));
    $promptOp = $manager->requestRefinement($id, $ids['owner'], ['id'=>$control,'version'=>$controlVersion]);
    $promptWork = $manager->claimWork($id, $promptOp['revision'], $promptOp['operation_key']);
    $question = $manager->publishWork($id, $promptWork['revision'], $promptWork['operation_key'], $promptWork['lease_token'], 'question "pony" & literal &quot;', false); $messages[]=$question;
    check($manager->lookupTarget($ids['owner'], [$question], ['intent'=>'clarification'])['status']==='target', 'quoted question binding remains authoritative for followup');
    $input = $message('ordinary description "pony" & literal &quot;'); $inputVersion=Domain\ChatManager::interactionSourceVersion($chat->getMessageById($input));
    $op=$manager->submitClarification($id,$ids['owner'],$input,['intent'=>'clarification','text'=>'ordinary facts','source_version'=>$inputVersion]);
    $work=$manager->claimWork($id,$op['revision'],$op['operation_key']);
    $manager->saveVerifiedResult($id,$work['revision'],$work['operation_key'],$work['lease_token'],['status'=>'candidates','options'=>[['key'=>'yes','label'=>'Updated','payload'=>[]]]]);
    $child=$manager->stageChild($id,$work['revision'],$work['operation_key'],$work['lease_token']);
    $childBot=$manager->publishWork($id,$work['revision'],$work['operation_key'],$work['lease_token'],'child "pony" & literal &quot; [[command:'.$child.']]',true); $messages[]=$childBot;
    check($manager->readPublic($child,$ids['owner'],$childBot)['can_act'] && $contextOf($child)['source_version_encoding']==='storage_v2','canonical child row and inherited binding provenance remain actionable');
    $cancel=$message('quoted cancel "pony" & literal &quot;');$cancelVersion=Domain\ChatManager::interactionSourceVersion($chat->getMessageById($cancel));
    $manager->cancelActive($child,$ids['owner'],['id'=>$cancel,'version'=>$cancelVersion]);
    $resultBot=$manager->publishResult($child,$ids['owner'],'terminal "pony" & literal &quot;');$messages[]=$resultBot;
    check($resultBot>0 && $manager->readPublic($child,$ids['owner'],$childBot)['state']==='cancelled','quoted terminal control and guarded publication retain terminal state');
    $chat->editMessage($cancel,$ids['owner'],'changed control');
    check($manager->publishResult($child,$ids['owner'],'late result')===null,'edited canonical terminal control denies replay publication');

    $toLegacy = function ($interaction) use ($chat,$contextOf,$setContext,$db) {
        $context=$contextOf($interaction); unset($context['source_version_encoding']);
        foreach ($context['source_bindings'] as $index=>&$binding) {
            $oldKey='input:'.$binding['id'].':'.$binding['version'];
            $encoding=$index===0 || isset($context['operations'][$oldKey]) ? 'lock_decoded_v1' : 'getter_read_v1';
            $binding['version']=Domain\ChatManager::interactionSourceVersion($chat->getMessageById($binding['id']),$encoding);unset($binding['version_encoding']);
            if(isset($context['operations'][$oldKey])) {
                $newKey='input:'.$binding['id'].':'.$binding['version'];$context['operations'][$newKey]=$context['operations'][$oldKey];$context['operations'][$newKey]['operation_key']=$newKey;
                if($newKey!==$oldKey)unset($context['operations'][$oldKey]);
                if(($context['pending_work']['operation_key']??null)===$oldKey)$context['pending_work']['operation_key']=$newKey;
            }
        } unset($binding);
        foreach ($context['question_bindings'] as &$binding) {$binding['version']=Domain\ChatManager::interactionSourceVersion($chat->getMessageById($binding['id']),'lock_decoded_v1');unset($binding['version_encoding']);} unset($binding);
        if(isset($context['proposal_binding'])) {$binding=&$context['proposal_binding'];$binding['version']=Domain\ChatManager::interactionSourceVersion($chat->getMessageById($binding['id']),'lock_decoded_v1');unset($binding['version_encoding']);unset($binding);}
        if(isset($context['operations']['cancel_source'])) {$binding=&$context['operations']['cancel_source']['source_binding'];$binding['version']=Domain\ChatManager::interactionSourceVersion($chat->getMessageById($binding['id']),'getter_read_v1');unset($binding['version_encoding']);unset($binding);}
        $sourceId=(int)$db->query('SELECT source_message_id FROM command_interactions WHERE id='.(int)$interaction)->fetch_assoc()['source_message_id'];
        $version=Domain\ChatManager::interactionSourceVersion($chat->getMessageById($sourceId),$context['parent_id']===null?'getter_read_v1':'lock_decoded_v1');
        $stmt=$db->prepare('UPDATE command_interactions SET source_version=? WHERE id=?');$stmt->bind_param('si',$version,$interaction);$stmt->execute();$setContext($interaction,$context);
    };
    $source=$message('v1 acceptance "pony" & literal &quot;');$sources[]=$source;$chat->editMessage($source,$ids['owner'],'v1 acceptance "pony" & literal &quot;');
    $version=Domain\ChatManager::interactionSourceVersion($chat->getMessageById($source));
    $manager->acceptNewCommand('synthetic',$ids['owner'],$source,fn()=>['kind'=>'search'],$version);
    $legacyId=(int)$db->query('SELECT id FROM command_interactions WHERE source_message_id='.(int)$source)->fetch_assoc()['id'];$toLegacy($legacyId);
    check($manager->acceptNewCommand('synthetic',$ids['owner'],$source,fn()=>['kind'=>'wrong'],$version)['kind']==='search','v1 acceptance marker root column replay uses historical getter provenance');
    $legacyId=$manager->createContinuation('synthetic',$ids['owner'],$source,[],['original'=>'legacy'],'clarifying');
    $legacyBot=$manager->publishProposal($legacyId,'legacy "pony" & literal &quot; [[command:'.$legacyId.']]');$messages[]=$legacyBot;
    check($contextOf($legacyId)['source_version_encoding']==='storage_v2','v1 acceptance upgrade atomically tags canonical row column');
    $toLegacy($legacyId);
    check($manager->readPublic($legacyId,$ids['owner'],$legacyBot)['state']==='clarifying','untagged v1 root and quoted proposal remain readable without rewriting versions');
    $legacyInput=$message('v1 input "pony" & literal &quot;');$legacyOp=$manager->submitClarification($legacyId,$ids['owner'],$legacyInput,['intent'=>'clarification','text'=>'facts']);
    $toLegacy($legacyId);$legacyContext=$contextOf($legacyId);
    $legacyInputKey='input:'.$legacyInput.':'.$legacyContext['source_bindings'][1]['version'];
    $legacyReplay=$manager->submitClarification($legacyId,$ids['owner'],$legacyInput,['intent'=>'clarification','text'=>'different reparsed facts','source_version'=>Domain\ChatManager::interactionSourceVersion($chat->getMessageById($legacyInput))]);
    check($legacyReplay===$legacyContext['operations'][$legacyInputKey] && $contextOf($legacyId)===$legacyContext,'v1 quoted input replay returns exact saved operation without another revision or context change');
    $legacyWork=$manager->claimWork($legacyId,$legacyContext['revision'],$legacyContext['pending_work']['operation_key']);
    check($legacyWork!==null,'v1 decoded input operation provenance supports durable work claim');
    $manager->saveVerifiedResult($legacyId,$legacyWork['revision'],$legacyWork['operation_key'],$legacyWork['lease_token'],['status'=>'candidates','options'=>[['key'=>'yes','label'=>'Legacy child','payload'=>[]]]]);
    $legacyChild=$manager->stageChild($legacyId,$legacyWork['revision'],$legacyWork['operation_key'],$legacyWork['lease_token']);
    $legacyChildBot=$manager->publishWork($legacyId,$legacyWork['revision'],$legacyWork['operation_key'],$legacyWork['lease_token'],'legacy child "pony" [[command:'.$legacyChild.']]',true);$messages[]=$legacyChildBot;$toLegacy($legacyChild);
    check($manager->readPublic($legacyChild,$ids['owner'],$legacyChildBot)['can_act'],'v1 child column and copied source/input bindings keep their decoded provenance');
    $manager->cancelActive($legacyChild,$ids['owner']);
    $legacyResult=$manager->publishResult($legacyChild,$ids['owner'],'legacy terminal');$messages[]=$legacyResult;
    check($legacyResult>0,'v1 child guarded terminal delivery succeeds without repeating effect');
    [$legacyControlId,$legacyControlSource,$legacyControlBot]=$make();
    $legacyControl=$message('legacy refine "pony" & literal &quot;');$chat->editMessage($legacyControl,$ids['owner'],'legacy refine "pony" & literal &quot;');
    $legacyControlVersion=Domain\ChatManager::interactionSourceVersion($chat->getMessageById($legacyControl));
    $legacyPrompt=$manager->requestRefinement($legacyControlId,$ids['owner'],['id'=>$legacyControl,'version'=>$legacyControlVersion]);
    $legacyPromptWork=$manager->claimWork($legacyControlId,$legacyPrompt['revision'],$legacyPrompt['operation_key']);
    $legacyQuestion=$manager->publishWork($legacyControlId,$legacyPromptWork['revision'],$legacyPromptWork['operation_key'],$legacyPromptWork['lease_token'],'legacy question "pony" & literal &quot;',false);$messages[]=$legacyQuestion;
    $toLegacy($legacyControlId);
    check($manager->lookupTarget($ids['owner'],[$legacyQuestion],['intent'=>'clarification'])['status']==='target','v1 mixed decoded question and escaped ISO refinement control remain valid');
    $legacyCancel=$message('legacy cancel "pony" & literal &quot;');$chat->editMessage($legacyCancel,$ids['owner'],'legacy cancel "pony" & literal &quot;');
    $manager->cancelActive($legacyControlId,$ids['owner'],['id'=>$legacyCancel,'version'=>Domain\ChatManager::interactionSourceVersion($chat->getMessageById($legacyCancel))]);$toLegacy($legacyControlId);
    $legacyControlResult=$manager->publishResult($legacyControlId,$ids['owner'],'legacy control terminal');$messages[]=$legacyControlResult;
    check($legacyControlResult>0,'v1 cancellation source historical getter provenance guards accepted terminal publication');
    $chat->editMessage($legacyQuestion,$ids['bot'],'changed question');
    check($manager->publishResult($legacyControlId,$ids['owner'],'late legacy result')===null,'edited historical question suppresses terminal replay with no effect');
    [$unknownId,$unknownSource,$unknownBot]=$make('clarifying');$unknownContext=$contextOf($unknownId);
    $unknownControl=$message('unknown old source');$unknownContext['source_bindings'][]=['id'=>$unknownControl,'user_id'=>$ids['owner'],'version'=>Domain\ChatManager::interactionSourceVersion($chat->getMessageById($unknownControl),'getter_read_v1')];$setContext($unknownId,$unknownContext);
    check($manager->readPublic($unknownId,$ids['owner'],$unknownBot)['state']==='unavailable','untagged non-input source without recorded refine provenance fails closed');
    [$id, $source, $bot] = $make();
    check($contextOf($id)['version'] === 1 && $manager->readPublic($id, $ids['owner'], $bot)['capabilities']['refine'], 'generic non-playlist envelope and public controls');
    $public = json_encode($manager->readPublic($id, $ids['owner'], $bot));
    check(!str_contains($public, 'handler_context') && !str_contains($public, 'source_bindings'), 'private envelope is not public');
    $denied(fn() => $manager->requestRefinement($id, $ids['foreign']), 'foreign refine rejected');
    check($manager->lookupTarget($ids['owner'], [$bot], ['intent' => 'refine'])['status'] === 'target', 'exact own bound quote targets leaf');
    check($manager->lookupTarget($ids['foreign'], [$bot], ['intent' => 'refine'])['status'] === 'invalid', 'foreign quote never falls back');
    check($manager->lookupTarget($ids['owner'], [], ['intent' => 'refine'])['status'] === 'none', 'no quote requires explicit bot address');
    $first = $manager->requestRefinement($id, $ids['owner']);
    check($manager->requestRefinement($id, $ids['owner']) === $first, 'refine API replay is exact persisted result');
    check($manager->readPublic($id, $ids['owner'], $bot)['state'] === 'clarifying' && $effects === 0, 'refine closes candidates without effect');
    $denied(fn() => $manager->consume($id, 'yes', $ids['owner']), 'clarifying candidate cannot execute');
    $prompt = $manager->claimWork($id, $first['revision'], $first['operation_key']);
    $input = $message('follow-up');
    $accepted = $manager->submitClarification($id, $ids['owner'], $input, ['intent' => 'clarification', 'text' => 'new facts']);
    check($manager->submitClarification($id, $ids['owner'], $input, ['intent' => 'clarification', 'text' => 'different']) === $accepted, 'input replay retains accepted context and revision');
    check(!$manager->completeWork($id, $prompt['revision'], $prompt['operation_key'], $prompt['lease_token']), 'late prompt lease cannot overwrite resolving');
    check($manager->lookupTarget($ids['owner'], [$bot], ['intent' => 'clarification'])['status'] === 'target', 'original quote works before prompt delivery');
    check($manager->listRecoverableWork()[0]['operation_key'] === $accepted['operation_key'], 'commit-before-enqueue recoverable intent');
    $work = $manager->claimWork($id, $accepted['revision'], $accepted['operation_key']);
    check($manager->claimWork($id, $accepted['revision'], $accepted['operation_key']) === null, 'active lease suppresses duplicate worker');
    $verified = ['status' => 'candidates', 'options' => [['key' => 'yes', 'label' => 'Updated', 'payload' => ['value' => 2]]]];
    check($manager->saveVerifiedResult($id, $work['revision'], $work['operation_key'], $work['lease_token'], $verified), 'verified result durable');
    check($manager->saveVerifiedResult($id, $work['revision'], $work['operation_key'], $work['lease_token'], ['status' => 'error']), 'verified result cannot be overwritten');
    $child = $manager->stageChild($id, $work['revision'], $work['operation_key'], $work['lease_token']);
    $denied(fn() => $manager->consume($child, 'yes', $ids['owner']), 'unbound staged child cannot consume');
    check($contextOf($child)['root_expires_at'] === $contextOf($id)['root_expires_at'], 'child expiry remains original immutable TTL');
    $proposal = $manager->publishWork($id, $work['revision'], $work['operation_key'], $work['lease_token'], 'updated [[command:' . $child . ']]', true);
    $messages[] = $proposal;
    check(!str_contains($chat->getMessageById($proposal)['message'], 'command-delivery:'), 'private work delivery marker is removed from public rendered message');
    check($proposal > 0 && $manager->readPublic($id, $ids['owner'], $bot)['state'] === 'superseded' && $manager->readPublic($child, $ids['owner'], $proposal)['state'] === 'pending', 'atomic child binding and parent supersede');
    check($manager->lookupTarget($ids['owner'], [$bot], ['intent' => 'cancel'])['status'] === 'invalid', 'old parent quote cannot redirect to active child');
    check($manager->consume($child, 'yes', $ids['owner']) === $manager->consume($child, 'cancel', $ids['owner']) && $effects === 1, 'one terminal effect and exact replay');
    $childContext=$contextOf($child);$childLease=$manager->claimWork($child,$childContext['revision'],$childContext['pending_work']['operation_key']);
    $childReply=$manager->publishTerminalWork($child,$childLease['revision'],$childLease['operation_key'],$childLease['lease_token'],'accepted child result');$messages[]=$childReply;
    check($childReply>0 && $childLease['outcome']['code']==='synthetic_done' && $contextOf($child)['pending_work']===null && $effects===1,'accepted child terminal recovery delivers persisted result without a second effect');

    [$id, $source, $bot] = $make(); $first = $manager->requestRefinement($id, $ids['owner']);
    $input = $message('cancelable work'); $accepted = $manager->submitClarification($id, $ids['owner'], $input, ['intent' => 'clarification', 'text' => 'facts']);
    $work = $manager->claimWork($id, $accepted['revision'], $accepted['operation_key']);
    $manager->saveVerifiedResult($id, $work['revision'], $work['operation_key'], $work['lease_token'], $verified);
    $child = $manager->stageChild($id, $work['revision'], $work['operation_key'], $work['lease_token']);
    $cancelled = $manager->cancelActive($id, $ids['owner']);
    check($cancelled['code'] === 'choice_cancelled' && $manager->cancelActive($id, $ids['owner']) === $cancelled && $contextOf($child)['pending_work'] === null, 'cancel parent invalidates staged child and replays');
    check(!$manager->bindAndActivateChild($id, $work['revision'], $work['operation_key'], $work['lease_token'], $bot), 'stale worker cannot activate after cancel');

    [$id, $source, $bot] = $make('clarifying');
    $before = $contextOf($id); $input = $message('too long');
    $denied(fn() => $manager->submitClarification($id, $ids['owner'], $input, ['intent' => 'clarification', 'text' => str_repeat('x',301)]), 'overflow refused before mutation');
    check($contextOf($id) === $before, 'overflow retains previously accepted context');
    $chat->editMessage($source, $ids['owner'], 'changed');
    $denied(fn() => $manager->cancelActive($id, $ids['owner']), 'edited root blocks cancel');
    [$id, $source, $bot] = $make(); $chat->deleteMessage($bot, $ids['bot']);
    $denied(fn() => $manager->requestRefinement($id, $ids['owner']), 'deleted proposal blocks refine');
    [$id, $source, $bot] = $make(); $chat->editMessage($bot, $ids['bot'], 'edited [[command:' . $id . ']]');
    $denied(fn() => $manager->requestRefinement($id, $ids['owner']), 'edited proposal with retained marker blocks refine');
    [$id, $source, $bot] = $make('clarifying');
    $input = $message('followup edit'); $accepted = $manager->submitClarification($id, $ids['owner'], $input, ['intent' => 'clarification', 'text' => 'facts']);
    $chat->editMessage($input, $ids['owner'], 'changed followup');
    $denied(fn() => $manager->claimWork($id, $accepted['revision'], $accepted['operation_key']), 'edited followup blocks resolver');

    [$id, $source, $bot] = $make(); $first = $manager->requestRefinement($id, $ids['owner']);
    $old = $manager->claimWork($id, $first['revision'], $first['operation_key']);
    $context = $contextOf($id); $context['pending_work']['lease_until'] = time()-1; $setContext($id,$context);
    $fresh = $manager->claimWork($id, $first['revision'], $first['operation_key']);
    check(!$manager->completeWork($id, $old['revision'], $old['operation_key'], $old['lease_token']), 'expired old token rejected after recovery claim');
    $question = $manager->publishWork($id, $fresh['revision'], $fresh['operation_key'], $fresh['lease_token'], 'clarify'); $messages[] = $question;
    check($manager->lookupTarget($ids['owner'], [$question], ['intent' => 'clarification'])['status'] === 'target', 'persisted exact question quote binds same parent');

    [$id, $source, $bot] = $make();
    $db->query('UPDATE command_interactions SET expires_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE id=' . $id);
    $denied(fn() => $manager->requestRefinement($id, $ids['owner']), 'immutable expired TTL rejects refinement');
    check($manager->readPublic($id, $ids['owner'], $bot)['state'] === 'expired', 'valid expired original proposal displays expired instead of unavailable');
    $older = $message('accepted slow command'); $sources[] = $older;
    $calls = 0;
    $acceptance = function () use (&$calls) { $calls++; return ['accepted' => true, 'kind' => 'description']; };
    $first = $manager->acceptNewCommand('synthetic', $ids['owner'], $older, $acceptance);
    check($manager->acceptNewCommand('synthetic', $ids['owner'], $older, $acceptance) === $first && $calls === 1, 'durable command acceptance replay does not repeat callback effect');
    $newer = $message('accepted direct command'); $sources[] = $newer;
    $manager->acceptNewCommand('synthetic', $ids['owner'], $newer, static fn() => ['accepted' => true, 'kind' => 'direct']);
    $denied(fn() => $manager->createContinuation('synthetic', $ids['owner'], $older, [['key'=>'yes','label'=>'late','payload'=>[]]], []), 'C1 delayed search cannot publish after C2 direct acceptance');
    $denied(fn() => $manager->acceptNewCommand('synthetic', $ids['owner'], $older, $acceptance), 'old direct command retry cannot supersede newer accepted source');
    $freshSource = $message('current descriptive acceptance'); $sources[] = $freshSource;
    $manager->acceptNewCommand('synthetic', $ids['owner'], $freshSource, static fn() => ['accepted' => true]);
    $upgraded = $manager->createContinuation('synthetic', $ids['owner'], $freshSource, [['key'=>'yes','label'=>'fresh','payload'=>[]]], ['original'=>'fresh']);
    check(empty($contextOf($upgraded)['acceptance_only']) && $db->query('SELECT state FROM command_interactions WHERE id=' . $upgraded)->fetch_assoc()['state'] === 'pending', 'same unique acceptance marker upgrades to current proposal');
    [$race, $raceSource, $raceBot] = $make();
    $program = <<<'PHP'
require 'tests/integration_helpers.php';
if ((it_config()['db']['host'] ?? '') !== 'db') exit(2);
$cap = array_fill_keys(['classify','prepare','resolve','format','permission'], static fn() => true);
$registry = ['synthetic' => ['permission' => static fn() => true, 'execute' => static fn() => ['status'=>'accepted','code'=>'race_effect','facts'=>[]], 'continuation'=>$cap]];
$manager = new Domain\CommandInteractionManager($registry);
try { $result=$manager->consume((int)$argv[1],$argv[2],(int)$argv[3]); echo json_encode(['ok'=>true,'result'=>$result]); }
catch (Core\UserError $error) { echo json_encode(['ok'=>false]); }
PHP;
    $processes = [];
    foreach (['yes','refine','cancel'] as $choice) {
        $process = proc_open([PHP_BINARY, '-r', $program, (string)$race, $choice, (string)$ids['owner']], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        fclose($pipes[0]); $processes[] = [$process,$pipes];
    }
    $results = [];
    foreach ($processes as [$process,$pipes]) {
        $output=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        check(proc_close($process) === 0, 'competing control process exits without DB failure: ' . $errors);
        $results[] = json_decode($output,true);
    }
    $outcome=$manager->getResult($race,$ids['owner'])['outcome'];
    check(count(array_filter($results, static fn($r)=>($r['result']['code']??'')==='race_effect')) === 0 || $outcome['code']==='race_effect', 'parallel accept/refine/cancel has one persisted terminal decision, no stale candidate effect');
    check(in_array($db->query('SELECT state FROM command_interactions WHERE id=' . $race)->fetch_assoc()['state'], ['consumed','cancelled','clarifying'], true), 'parallel controls preserve legal state machine');

    [$recover, $recoverSource, $recoverBot] = $make('clarifying');
    $input = $message('recovery result');
    $accepted=$manager->submitClarification($recover,$ids['owner'],$input,['intent'=>'clarification','text'=>'recover']);
    $lease=$manager->claimWork($recover,$accepted['revision'],$accepted['operation_key']);
    $manager->saveVerifiedResult($recover,$lease['revision'],$lease['operation_key'],$lease['lease_token'],$verified);
    check($manager->completeWork($recover,$lease['revision'],$lease['operation_key'],$lease['lease_token'],false), 'delivery failure releases lease without discarding verified result');
    $context=$contextOf($recover); $context['pending_work']['available_at']=time()-1; $setContext($recover,$context);
    $lease=$manager->claimWork($recover,$accepted['revision'],$accepted['operation_key']);
    check($lease['context']['resolver_result']['status']==='candidates' && $lease['work']['attempts']===1, 'saved verified result recovery never consumes another external resolver attempt');
    $manager->completeWork($recover,$lease['revision'],$lease['operation_key'],$lease['lease_token'],false);
    $context=$contextOf($recover); $context['pending_work']['available_at']=time()-1; $setContext($recover,$context);
    $lease=$manager->claimWork($recover,$accepted['revision'],$accepted['operation_key']);
    $manager->completeWork($recover,$lease['revision'],$lease['operation_key'],$lease['lease_token'],false);
    check($contextOf($recover)['pending_work']['kind']==='prompt' && $manager->readPublic($recover,$ids['owner'],$recoverBot)['state']==='clarifying', 'three delivery failures become retryable clarification without another search');
    [$recover,$recoverSource,$recoverBot]=$make('clarifying');
    $input=$message('abandoned search'); $accepted=$manager->submitClarification($recover,$ids['owner'],$input,['intent'=>'clarification','text'=>'abandoned']);
    for($attempt=0;$attempt<3;$attempt++) {
        $lease=$manager->claimWork($recover,$accepted['revision'],$accepted['operation_key']);
        $context=$contextOf($recover);$context['pending_work']['lease_until']=time()-1;$setContext($recover,$context);
    }
    check($manager->claimWork($recover,$accepted['revision'],$accepted['operation_key'])===null && $contextOf($recover)['pending_work']['kind']==='prompt', 'three abandoned external executions exhaust into durable factual prompt');
    $source=$message('parsed original command');$sources[]=$source;
    $original=$chat->getMessageById($source);$version=hash('sha256',$original['raw_message'].'|'.($original['edited_at']??''));
    $chat->editMessage($source,$ids['owner'],'changed command');$callbackCalls=0;
    $denied(fn()=>$manager->acceptNewCommand('synthetic',$ids['owner'],$source,function()use(&$callbackCalls){$callbackCalls++;return ['accepted'=>true];},$version),'changed source between command parse and acceptance lock refuses old parsed effect');
    check($callbackCalls===0,'stale parsed command never invokes acceptance callback');
    [$race,$raceSource,$raceBot]=$make('clarifying');
    $followup=$message('parsed original clarification');$original=$chat->getMessageById($followup);$version=hash('sha256',$original['raw_message'].'|'.($original['edited_at']??''));
    $chat->editMessage($followup,$ids['owner'],'changed followup');$before=$contextOf($race);
    $denied(fn()=>$manager->submitClarification($race,$ids['owner'],$followup,['intent'=>'clarification','text'=>'old facts','source_version'=>$version]),'changed followup between parse and lock refuses old parsed context');
    check($contextOf($race)===$before,'stale parsed clarification does not mutate context or revision');
    $denied(fn()=>$manager->cancelActive($race,$ids['owner'],['id'=>$followup,'version'=>$version]),'text cancel validates parsed followup version atomically');
    $control=$message('refine control');$original=$chat->getMessageById($control);$version=hash('sha256',$original['raw_message'].'|'.($original['edited_at']??''));
    [$pending,$pendingSource,$pendingBot]=$make();
    $chat->editMessage($control,$ids['owner'],'changed refine control');
    $denied(fn()=>$manager->requestRefinement($pending,$ids['owner'],['id'=>$control,'version'=>$version]),'text refine validates parsed followup version atomically');
    $source=$message('guarded initial proposal');$sources[]=$source;
    $proposalId=$manager->createContinuation('synthetic',$ids['owner'],$source,[['key'=>'yes','label'=>'initial','payload'=>[]]],[]);
    $published=$manager->publishProposal($proposalId,'initial [[command:'.$proposalId.']]');$messages[]=$published;
    check($published>0 && $manager->publishProposal($proposalId,'another [[command:'.$proposalId.']]')===$published,'initial proposal publication recovers same exact live message without duplicate insert');
    $source=$message('guarded stale initial');$sources[]=$source;
    $staleId=$manager->createContinuation('synthetic',$ids['owner'],$source,[['key'=>'yes','label'=>'stale','payload'=>[]]],[]);
    $chat->editMessage($source,$ids['owner'],'changed initial');
    check($manager->publishProposal($staleId,'stale [[command:'.$staleId.']]')===null,'source edit before guarded initial publication suppresses insert and binding');
    foreach (['edit','delete'] as $mutation) {
        $source=$message('accepted replay '.$mutation);$sources[]=$source;$callbackCalls=0;
        $callback=function()use(&$callbackCalls){$callbackCalls++;return ['accepted'=>true,'code'=>'recorded_before_mutation'];};
        $original=$chat->getMessageById($source);$oldVersion=hash('sha256',$original['raw_message'].'|'.($original['edited_at']??''));
        $manager->acceptNewCommand('synthetic',$ids['owner'],$source,$callback,$oldVersion);
        if($mutation==='edit')$chat->editMessage($source,$ids['owner'],'edited after acceptance');
        else $chat->deleteMessage($source,$ids['owner']);
        $current=$chat->getMessageById($source);$currentVersion=hash('sha256',($current['raw_message']??'').'|'.($current['edited_at']??''));
        $denied(fn()=>$manager->acceptNewCommand('synthetic',$ids['owner'],$source,$callback,$currentVersion),'saved acceptance replay refuses '.$mutation.' even with fresh caller version');
        check($callbackCalls===1,'stale saved acceptance replay never performs second effect after '.$mutation);
    }
    [$terminal,$terminalSource,$terminalBot]=$make();
    $control=$message('terminal control');$original=$chat->getMessageById($control);$version=hash('sha256',$original['raw_message'].'|'.($original['edited_at']??''));
    $manager->cancelActive($terminal,$ids['owner'],['id'=>$control,'version'=>$version]);
    $reply=$manager->publishResult($terminal,$ids['owner'],'choice cancelled');$messages[]=$reply;
    check($reply>0 && $manager->publishResult($terminal,$ids['owner'],'different late wording')===$reply,'terminal publication exact live marker replay binds one persisted reply');
    check($manager->getResult($terminal,$ids['owner'])['reply_message_id']===$reply,'terminal result binding commits atomically with reply');
    foreach(['edit','delete'] as $mutation) {
        [$terminal,$terminalSource,$terminalBot]=$make();
        $control=$message('terminal control '.$mutation);$original=$chat->getMessageById($control);$version=hash('sha256',$original['raw_message'].'|'.($original['edited_at']??''));
        $manager->cancelActive($terminal,$ids['owner'],['id'=>$control,'version'=>$version]);
        if($mutation==='edit')$chat->editMessage($control,$ids['owner'],'changed terminal input');else $chat->deleteMessage($control,$ids['owner']);
        check($manager->publishResult($terminal,$ids['owner'],'late stale cancellation')===null && $manager->getResult($terminal,$ids['owner'])['reply_message_id']===null,'terminal source '.$mutation.' suppresses late reply and leaves immutable outcome intact');
    }
    $source=$message('legacy result publication');$sources[]=$source;
    $legacyResult=$manager->create('synthetic',$ids['owner'],$source,[['key'=>'yes','label'=>'legacy','payload'=>[]]]);
    $bot=$message('legacy [[command:'.$legacyResult.']]', $ids['bot']);$manager->bindMessage($legacyResult,$bot);
    $manager->consume($legacyResult,'yes',$ids['owner']);
    $db->query('UPDATE command_interactions SET expires_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE id='.$legacyResult);
    $reply=$manager->publishResult($legacyResult,$ids['owner'],'committed legacy outcome');$messages[]=$reply;
    check($reply>0 && $contextOf($legacyResult)===null,'legacy NULL terminal publication remains compatible and expiry never undoes committed effect');
    // R2: historical rows must not invoke permission/source guards on the HTTP target path.
    $permissionChecks=0;$budgetHandler=$handler;
    $budgetHandler['continuation']['permission']=static function()use(&$permissionChecks){$permissionChecks++;return true;};
    $budgetManager=new Domain\CommandInteractionManager(['budget'=>$budgetHandler]);
    $budgetRows=[];
    for($index=0;$index<25;$index++) {
        $source=$message('budget history '.$index);$sources[]=$source;
        $rowId=$budgetManager->createContinuation('budget',$ids['owner'],$source,[['key'=>'yes','label'=>'budget','payload'=>[]]],[]);
        $bot=$message('budget [[command:'.$rowId.']]', $ids['bot']);$budgetManager->bindMessage($rowId,$bot);
        $budgetRows[]=[$rowId,$source,$bot];
        $db->query("UPDATE command_interactions SET state='consumed',outcome_json=JSON_OBJECT('status','accepted','code','historical','facts',JSON_OBJECT()) WHERE id=".$rowId);
    }
    $source=$message('budget active');$sources[]=$source;
    $budgetActive=$budgetManager->createContinuation('budget',$ids['owner'],$source,[['key'=>'yes','label'=>'active','payload'=>[]]],[]);
    $bot=$message('budget active [[command:'.$budgetActive.']]', $ids['bot']);$budgetManager->bindMessage($budgetActive,$bot);
    $permissionChecks=0;
    $target=$budgetManager->lookupTarget($ids['owner'],[],['intent'=>'cancel','addressesBot'=>true,'type'=>'budget']);
    check($target['target']['id']===$budgetActive && $permissionChecks===1,'25 historical consumed rows perform zero permission/source guards before unique active noquote target');
    check($budgetManager->lookupTarget($ids['owner'],[$budgetRows[0][2]],['intent'=>'cancel'])['status']==='invalid','quoted historical consumed proposal remains invalid rather than falling back');

    // R3: eligibility precedes LIMIT20; scheduling stamps survive fresh manager instances.
    $fairRows=[];$fairManager=new Domain\CommandInteractionManager(['fair'=>$handler]);
    for($index=0;$index<26;$index++) {
        $source=$message('fair recovery '.$index);$sources[]=$source;
        $rowId=$fairManager->createContinuation('fair',$ids['owner'],$source,[['key'=>'yes','label'=>'fair','payload'=>[]]],[]);
        $bot=$message('fair [[command:'.$rowId.']]', $ids['bot']);$fairManager->bindMessage($rowId,$bot);
        $operation=$fairManager->requestRefinement($rowId,$ids['owner']);
        $fairRows[]=[$rowId,$source,$bot,$operation,$contextOf($rowId)];
    }
    $db->query("UPDATE command_interactions SET state='superseded' WHERE owner_id=".$ids['owner']." AND type NOT IN ('fair','budget') AND state IN ('clarifying','resolving','consumed','cancelled')");
    foreach($fairRows as $index=>[$rowId,$source,$bot,$operation,$context]) {
        if($index<25) {
            if($index%3===0){$context['pending_work']['lease_until']=time()+90;$context['pending_work']['lease_token']=str_repeat('a',32);}
            elseif($index%3===1)$context['pending_work']['available_at']=time()+90;
            else $context['staged']=true;
        }
        $setContext($rowId,$context);$db->query("UPDATE command_interactions SET state='clarifying' WHERE id=".$rowId);
    }
    $selected=$fairManager->listRecoverableWork(20);
    check(count($selected)===1 && $selected[0]['interaction_id']===$fairRows[25][0],'25 leased/backoff/staged head rows cannot exclude eligible tail before LIMIT20');
    foreach($fairRows as $index=>[$rowId,$source,$bot,$operation,$context]) {
        if($index<25)$chat->editMessage($source,$ids['owner'],'permanently stale head '.$index);
        $setContext($rowId,$context);$db->query("UPDATE command_interactions SET state='clarifying' WHERE id=".$rowId);
    }
    $firstPage=(new Domain\CommandInteractionManager(['fair'=>$handler]))->listRecoverableWork(20);
    check(count($firstPage)===20,'reconciliation page remains bounded20 including invalid-source candidates');
    $secondPage=(new Domain\CommandInteractionManager(['fair'=>$handler]))->listRecoverableWork(20);
    $tailItems=array_values(array_filter($secondPage,static fn($item)=>$item['interaction_id']===$fairRows[25][0]));
    check(count($tailItems)===1,'durable fair scan advances beyond 25 invalid-source heads across fresh manager instances');
    $tail=$tailItems[0];$claimed=$fairManager->claimWork($tail['interaction_id'],$tail['revision'],$tail['operation_key']);
    check($claimed!==null,'eligible fair tail is actually claimable after invalid heads');
    $db->query("UPDATE command_interactions SET state='superseded' WHERE owner_id=".$ids['owner']." AND type='fair'");

    // R4: terminal delivery intent is durable before any queue enqueue, without repeating the effect.
    [$terminal,$terminalSource,$terminalBot]=$make();
    $manager->cancelActive($terminal,$ids['owner']);$terminalContext=$contextOf($terminal);
    check($terminalContext['pending_work']['kind']==='terminal' && $terminalContext['pending_work']['delivery_expires_at']<=time()+900,'cancel outcome atomically persists bounded terminal delivery intent');
    $freshManager=new Domain\CommandInteractionManager(['synthetic'=>$handler]);
    $items=$freshManager->listRecoverableWork();$terminalItems=array_values(array_filter($items,static fn($item)=>$item['interaction_id']===$terminal));
    $item=$terminalItems[0];$lease=$freshManager->claimWork($terminal,$item['revision'],$item['operation_key']);
    check($lease['outcome']['code']==='choice_cancelled','fresh recovery snapshot uses persisted terminal outcome without executing handler');
    $reply=$freshManager->publishTerminalWork($terminal,$lease['revision'],$lease['operation_key'],$lease['lease_token'],'durable cancelled');$messages[]=$reply;
    check($reply>0 && $contextOf($terminal)['pending_work']===null && $freshManager->publishTerminalWork($terminal,$lease['revision'],$lease['operation_key'],$lease['lease_token'],'duplicate')===null,'commit-before-enqueue terminal recovery publishes once and clears intent atomically');
    [$terminal,$terminalSource,$terminalBot]=$make();$beforeEffects=$effects;$manager->consume($terminal,'yes',$ids['owner']);
    $context=$contextOf($terminal);$lease=$manager->claimWork($terminal,$context['revision'],$context['pending_work']['operation_key']);
    check($lease['work']['kind']==='terminal' && $lease['outcome']['code']==='synthetic_done' && $effects===$beforeEffects+1,'accepted candidate also persists terminal intent with exactly one effect');
    for($attempt=0;$attempt<3;$attempt++) {
        if($attempt>0){$context=$contextOf($terminal);$context['pending_work']['available_at']=0;$setContext($terminal,$context);$lease=$manager->claimWork($terminal,$context['revision'],$context['pending_work']['operation_key']);}
        $manager->completeWork($terminal,$lease['revision'],$lease['operation_key'],$lease['lease_token'],false);
    }
    check($contextOf($terminal)['pending_work']===null && $manager->getResult($terminal,$ids['owner'])['outcome']['code']==='synthetic_done' && $db->query('SELECT state FROM command_interactions WHERE id='.$terminal)->fetch_assoc()['state']==='consumed','three terminal delivery failures preserve immutable consumed outcome without reactivation');
    [$terminal,$terminalSource,$terminalBot]=$make();$manager->cancelActive($terminal,$ids['owner']);$context=$contextOf($terminal);$context['pending_work']['delivery_expires_at']=time()-1;$setContext($terminal,$context);
    check($manager->claimWork($terminal,$context['revision'],$context['pending_work']['operation_key'])===null,'expired private delivery budget never creates a new terminal lease or extends choice TTL');
    [$terminal,$terminalSource,$terminalBot]=$make();$manager->cancelActive($terminal,$ids['owner']);$context=$contextOf($terminal);
    $users->muteUser($ids['owner'],1);
    $denied(fn()=>$manager->claimWork($terminal,$context['revision'],$context['pending_work']['operation_key']),'terminal recovery rechecks current actor grant before lease');
    $users->unmuteUser($ids['owner']);$chat->editMessage($terminalSource,$ids['owner'],'changed cancelled source');
    $denied(fn()=>$manager->claimWork($terminal,$context['revision'],$context['pending_work']['operation_key']),'terminal recovery rechecks persisted source version without repeating effect');
    // Retained-column application rollback: old reader selects the original columns, NULL legacy unchanged.
    $source = $message('legacy'); $sources[] = $source;
    $legacy = $manager->create('synthetic', $ids['owner'], $source, [['key'=>'yes','label'=>'legacy','payload'=>[]]]);
    $snapshot = $db->query('SELECT id,type,owner_id,source_message_id,options_json,state FROM command_interactions WHERE id=' . $legacy)->fetch_assoc();
    check($contextOf($legacy) === null && $snapshot['state'] === 'pending', 'old application reader works with retained nullable column and legacy NULL');
    $db->query('CREATE TEMPORARY TABLE mlp364_rollback_fixture LIKE command_interactions');
    $db->query('INSERT INTO mlp364_rollback_fixture SELECT * FROM command_interactions WHERE id=' . $legacy);
    $db->query('ALTER TABLE mlp364_rollback_fixture DROP COLUMN context_json');
    $rollback = $db->query('SELECT id,type,owner_id,source_message_id,options_json,state FROM mlp364_rollback_fixture')->fetch_assoc();
    $db->query('ALTER TABLE mlp364_rollback_fixture ADD COLUMN context_json JSON NULL AFTER options_json');
    check($snapshot === $rollback && $db->query('SELECT context_json FROM mlp364_rollback_fixture')->fetch_assoc()['context_json'] === null, 'disposable empty-context fixture forward/down/forward retains all original legacy data');
} finally {
    foreach ($ids as $id) $db->query('DELETE FROM command_interactions WHERE owner_id=' . (int)$id);
    foreach ($messages as $id) if ($id) $db->query('DELETE FROM chat_messages WHERE id=' . (int)$id);
    foreach ($ids as $id) $users->deleteUser($id);
    if ($savedBot === null) $db->query("DELETE FROM site_options WHERE key_name='ai_bot_user_id'");
    else $config->setOption('ai_bot_user_id', (string)$savedBot);
    $config->flushCache();
}
it_done();
