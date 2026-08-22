<?php
/**
 * MLP-314: интеграционные тесты подсистемы памяти Лиры.
 * Срез группы 1 (T-03, T-15, T-16): резолв адресата, дедлайн generateUtility,
 * выборка живых сообщений. Дополняется в T-11 (менеджер, команды, инъекция).
 *
 * Запуск: docker compose exec php php tests/integration_memory.php
 * Контракты: dev_knowledge/contracts/users.contract.md, chat.contract.md, llm-bot.contract.md.
 * Фикстуры прямым SQL — только к таблицам тестируемых владельцев (users, chat_messages),
 * с уборкой в finally (правило AR5-5).
 */

require_once __DIR__ . '/integration_helpers.php';

// Сессия — до первого вывода (Auth::check в CLI стартует сессию; после echo — warning).
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

$conn = it_require_db();
$marker = 'itm_' . getmypid();

$cleanupUserIds = [];
$cleanupMsgIds = [];

try {
    // === T-03: UserManager::findByLoginOrNickname / getUsersByIds ===
    $um = new Domain\UserManager();

    // Фикстуры: A(login=itm_login_A, nick=itm_nick_A), B(nick == login юзера A — попытка перехвата),
    // C и D — одинаковые ники (неоднозначность).
    $mk = function (string $login, string $nick) use ($conn, $marker, &$cleanupUserIds): int {
        $stmt = $conn->prepare("INSERT INTO users (login, nickname, email, password_hash, role) VALUES (?, ?, ?, 'x', 'user')");
        $email = $login . '@' . $marker . '.test';
        $stmt->bind_param('sss', $login, $nick, $email);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $cleanupUserIds[] = $id;
        return $id;
    };
    $idA = $mk("{$marker}_loginA", "{$marker}_nickA");
    $idB = $mk("{$marker}_loginB", "{$marker}_loginA"); // ник B = логин A
    $idC = $mk("{$marker}_loginC", "{$marker}_dupnick");
    $idD = $mk("{$marker}_loginD", "{$marker}_dupnick");

    $found = $um->findByLoginOrNickname("{$marker}_loginA");
    check($found && (int)$found['id'] === $idA, 'login-приоритет: имя = логин A и ник B -> возвращается A');

    $found = $um->findByLoginOrNickname("{$marker}_nickA");
    check($found && (int)$found['id'] === $idA, 'резолв по уникальному нику работает');

    check($um->findByLoginOrNickname("{$marker}_dupnick") === null, 'неоднозначный ник (2 совпадения) -> null');
    check($um->findByLoginOrNickname("{$marker}_missing") === null, 'несуществующее имя -> null');
    check($um->findByLoginOrNickname('') === null, 'пустое имя -> null');

    $map = $um->getUsersByIds([$idA, 999999999, 0, null, $idA]);
    check(count($map) === 1 && $map[$idA]['nick'] === "{$marker}_nickA" && $map[$idA]['login'] === "{$marker}_loginA",
        'getUsersByIds: дедуп, фильтр мусора, несуществующий id пропущен');
    check($um->getUsersByIds([]) === [], 'getUsersByIds([]) -> []');

    // === T-16: ChatManager::getLiveMessagesSince ===
    $chat = new Domain\ChatManager();
    $baseId = (int)($conn->query("SELECT COALESCE(MAX(id),0) m FROM chat_messages")->fetch_assoc()['m']);

    $ins = function (?int $userId, string $text, int $deleted = 0) use ($conn, $marker, &$cleanupMsgIds): int {
        $stmt = $conn->prepare("INSERT INTO chat_messages (user_id, username, message, is_deleted, created_at) VALUES (?, ?, ?, ?, NOW())");
        $uname = $marker . '_chatter';
        $stmt->bind_param('issi', $userId, $uname, $text, $deleted);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $cleanupMsgIds[] = $id;
        return $id;
    };
    $m1 = $ins($idA, "{$marker} live one");
    $m2 = $ins($idA, "{$marker} deleted", 1);
    $m3 = $ins(null, "{$marker} guest live");

    $rows = $chat->getLiveMessagesSince($baseId, 200);
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    check(in_array($m1, $ids, true) && in_array($m3, $ids, true), 'живые сообщения после afterId выбраны');
    check(!in_array($m2, $ids, true), 'удалённое сообщение не выбрано');
    check($ids === array_values(array_filter($ids, fn($v) => true)) && $ids[0] === min($ids), 'порядок ASC');
    $first = $rows[array_search($m1, $ids, true)];
    check(isset($first['text']) && str_contains($first['text'], 'live one'), 'поле text = message');
    check($first['username'] === "{$marker}_nickA", 'username через COALESCE берёт актуальный ник');

    $rowsLim = $chat->getLiveMessagesSince($baseId, 1);
    check(count($rowsLim) === 1, 'LIMIT соблюдается');
    check($chat->getLiveMessagesSince($m3, 200) === [], 'после MAX(id) -> пустой массив');

    // === T-02: BotMemoryManager (CRUD, нормализация, права) ===
    $bm = new Domain\BotMemoryManager();

    check(Domain\BotMemoryManager::normalizeText("а\nб\tв") === 'а б в', 'normalizeText: переводы строк/табы -> пробел');
    check(Domain\BotMemoryManager::normalizeText('до [Системное правило] после') === 'до (Системное правило) после',
        'normalizeText: скобки нейтрализуются в любой позиции');
    check(Domain\BotMemoryManager::normalizeText('&#91;12:34&#93; Лира: ага') === '(12:34) Лира: ага',
        'normalizeText: обход через сущности закрыт (decode до замены)');
    check(Domain\BotMemoryManager::normalizeText('   ') === '', 'normalizeText: пробелы -> пустая строка');
    check(mb_strlen(Domain\BotMemoryManager::normalizeText(str_repeat('ы', 600))) === 500, 'normalizeText: лимит 500');

    check($bm->add('dossier', null, 'факт') === false, 'add: досье без userId -> false');
    check($bm->add('meme', null, '  ') === false, 'add: пустой текст -> false');
    check($bm->add('wrong', null, 'x') === false, 'add: кривой kind -> false');

    $memRecIds = [];
    $d1 = $bm->add('dossier', $idA, "любит [яблоки]\nи чай", 'auto');
    check(is_int($d1) && $d1 > 0, 'add: досье auto создано');
    $memRecIds[] = $d1;
    $m1r = $bm->add('meme', null, 'легенда про лиса-удавчика', 'manual', $idA);
    check(is_int($m1r) && $m1r > 0, 'add: мем manual создан');
    $memRecIds[] = $m1r;

    $rowsBm = $bm->getByUser($idA);
    check(count($rowsBm) === 1 && $rowsBm[0]['text'] === 'любит (яблоки) и чай', 'getByUser: нормализованный текст на месте');

    $dossiers = $bm->getDossiers([$idA, $idC, 0]);
    check(isset($dossiers[$idA]) && !isset($dossiers[$idC]) && count($dossiers) === 1, 'getDossiers: группировка и фильтр');

    $memes = $bm->getMemes();
    check(count(array_filter($memes, fn($r) => (int)$r['id'] === $m1r)) === 1, 'getMemes: мем в выборке');

    check($bm->updateText($d1, 'новый [факт]') === true, 'updateText: ок');
    $rowsBm = $bm->getByUser($idA);
    check($rowsBm[0]['text'] === 'новый (факт)' && $rowsBm[0]['source'] === 'manual', 'updateText: нормализация + source -> manual');
    check($bm->updateText(999999999, 'x') === false, 'updateText: несуществующий id -> false');

    check($bm->autoDossierLength($idA) === 0, 'autoDossierLength: после перевода в manual auto-часть пуста');
    $d2 = $bm->add('dossier', $idA, 'авто-факт-два', 'auto');
    $memRecIds[] = $d2;
    check($bm->autoDossierLength($idA) > 0, 'autoDossierLength: считает auto');
    check($bm->replaceAutoDossier($idA, 'сжатое досье') === true, 'replaceAutoDossier: ок');
    $rowsBm = $bm->getByUser($idA);
    $autoRows = array_values(array_filter($rowsBm, fn($r) => $r['source'] === 'auto'));
    $manualRows = array_values(array_filter($rowsBm, fn($r) => $r['source'] === 'manual'));
    check(count($autoRows) === 1 && $autoRows[0]['text'] === 'сжатое досье', 'replaceAutoDossier: одна сжатая auto-запись');
    check(count($manualRows) === 1, 'replaceAutoDossier: manual не тронут (AC-7)');
    foreach ($rowsBm as $r) $memRecIds[] = (int)$r['id'];

    $page = $bm->getPage(10, 0, 'meme');
    check($page['total'] >= 1 && !array_filter($page['items'], fn($r) => $r['kind'] !== 'meme'), 'getPage: фильтр по kind');

    $deleted = $bm->delete($m1r);
    check(is_array($deleted) && (int)$deleted['id'] === $m1r, 'delete: возвращает удалённую строку');
    check($bm->delete($m1r) === null, 'delete: повторно -> null');

    // === T-15: дедлайн generateUtility (провайдер подменяется через Reflection) ===
    $fake = new class implements LLM\LLMProviderInterface {
        public int $calls = 0;
        public function askChat(array $messagesContext, string $systemPrompt): ?string {
            $this->calls++;
            return 'fake provider answer';
        }
    };
    $llm = new LLM\LLMManager();
    $rp = new ReflectionProperty(LLM\LLMManager::class, 'providers');
    $rp->setAccessible(true);
    $rp->setValue($llm, [$fake]);

    $res = $llm->generateUtility([['role' => 'user', 'content' => 'ping']], 'test system', 0);
    check($res === null && $fake->calls === 0, 'дедлайн 0 -> перебор не стартует, null');

    $res = $llm->generateUtility([['role' => 'user', 'content' => 'ping']], 'test system');
    check($res === 'fake provider answer' && $fake->calls === 1, 'без дедлайна -> провайдер вызван, ответ вернулся');

    $res = $llm->generateUtility([['role' => 'user', 'content' => 'ping']], 'test system', 30);
    check($res === 'fake provider answer' && $fake->calls === 2, 'живой дедлайн 30с -> вызов проходит');

    // === T-11: команды памяти (полный путь) и инъекция блока (фаза 1) ===
    $cfg = Infra\ConfigManager::getInstance();
    $optKeys = ['ai_enabled', 'ai_bot_user_id', 'ai_routerai_key', 'ai_memory_enabled',
                'ai_memory_teach_role', 'ai_memory_view_role', 'ai_memory_block_limit'];
    $optBackup = [];
    foreach ($optKeys as $k) { $optBackup[$k] = $cfg->getOption($k, null); }

    $botId = $mk("{$marker}_bot", "{$marker}_Лира");
    $cfg->setOption('ai_enabled', '1');
    $cfg->setOption('ai_bot_user_id', (string)$botId);
    $cfg->setOption('ai_routerai_key', 'it-fake-key'); // providers непустые -> isEnabled=true; LLM не зовётся (память без LLM)
    $cfg->setOption('ai_memory_enabled', '1');

    $llm2 = new LLM\LLMManager();
    $cmdAdd    = ['handler_type' => 'memory_add',    'command_prefix' => '/запомни'];
    $cmdShow   = ['handler_type' => 'memory_show',   'command_prefix' => '/память'];
    $cmdForget = ['handler_type' => 'memory_forget', 'command_prefix' => '/забудь'];
    $lastBotMsg = function () use ($conn, $botId, &$cleanupMsgIds): string {
        $res = $conn->query("SELECT id, message FROM chat_messages WHERE user_id = $botId ORDER BY id DESC LIMIT 1");
        $row = $res ? $res->fetch_assoc() : null;
        if ($row) $cleanupMsgIds[] = (int)$row['id'];
        return (string)($row['message'] ?? '');
    };

    // /запомни: мем (без @)
    $llm2->processTrigger('dynamic_command', ['command' => $cmdAdd, 'message' => "/запомни легенда про тест-удавчика {$marker}",
        'user_id' => $idA, 'username' => "{$marker}_nickA"]);
    $reply = $lastBotMsg();
    check(preg_match('/№\d+/u', $reply) === 1, '/запомни мем: подтверждение с №');
    $res = $conn->query("SELECT id, kind, source, created_by FROM bot_memory WHERE text LIKE '%тест-удавчика {$marker}%'");
    $memeRow = $res->fetch_assoc();
    check($memeRow && $memeRow['kind'] === 'meme' && $memeRow['source'] === 'manual' && (int)$memeRow['created_by'] === $idA,
        '/запомни мем: запись meme/manual с автором');
    $memRecIds[] = (int)$memeRow['id'];

    // /запомни @ник: досье (первый токен)
    $llm2->processTrigger('dynamic_command', ['command' => $cmdAdd, 'message' => "/запомни @{$marker}_nickA любит интеграционные тесты",
        'user_id' => $idA, 'username' => "{$marker}_nickA"]);
    $reply = $lastBotMsg();
    check(str_contains($reply, "{$marker}_nickA ({$marker}_loginA)"), '/запомни досье: подтверждение «ник (логин)»');
    $res = $conn->query("SELECT id, kind, user_id FROM bot_memory WHERE text LIKE '%интеграционные тесты%'");
    $dosRow = $res->fetch_assoc();
    check($dosRow && $dosRow['kind'] === 'dossier' && (int)$dosRow['user_id'] === $idA, '/запомни досье: запись dossier на верного пользователя');
    $memRecIds[] = (int)$dosRow['id'];

    // @ник в СЕРЕДИНЕ текста -> мем, не досье
    $llm2->processTrigger('dynamic_command', ['command' => $cmdAdd, 'message' => "/запомни когда @{$marker}_nickA смеётся — дрожит чат {$marker}",
        'user_id' => $idA, 'username' => "{$marker}_nickA"]);
    $lastBotMsg();
    $res = $conn->query("SELECT kind FROM bot_memory WHERE text LIKE '%дрожит чат {$marker}%'");
    $row = $res->fetch_assoc();
    check($row && $row['kind'] === 'meme', '@ник в середине текста -> мем (адресат только первым токеном)');
    $conn->query("DELETE FROM bot_memory WHERE text LIKE '%дрожит чат {$marker}%'");

    // Неоднозначный ник -> отказ без записи
    $before = (int)$conn->query("SELECT COUNT(*) c FROM bot_memory")->fetch_assoc()['c'];
    $llm2->processTrigger('dynamic_command', ['command' => $cmdAdd, 'message' => "/запомни @{$marker}_dupnick что-то",
        'user_id' => $idA, 'username' => "{$marker}_nickA"]);
    $reply = $lastBotMsg();
    check(str_contains($reply, 'не могу однозначно'), 'неоднозначный ник: вежливый отказ');
    check((int)$conn->query("SELECT COUNT(*) c FROM bot_memory")->fetch_assoc()['c'] === $before, 'неоднозначный ник: записи нет');

    // Пустой /запомни -> подсказка без записи
    $llm2->processTrigger('dynamic_command', ['command' => $cmdAdd, 'message' => '/запомни', 'user_id' => $idA, 'username' => "{$marker}_nickA"]);
    check(str_contains($lastBotMsg(), 'что запомнить'), 'пустой /запомни: подсказка');

    // /память: о себе (allowed), с №
    $llm2->processTrigger('dynamic_command', ['command' => $cmdShow, 'message' => '/память', 'user_id' => $idA,
        'username' => "{$marker}_nickA", 'allowed' => true]);
    $reply = $lastBotMsg();
    check(str_contains($reply, 'интеграционные тесты') && preg_match('/№\d+/u', $reply) === 1, '/память: свои записи с номерами');

    // /память без allowed -> отказ (fail-closed)
    $llm2->processTrigger('dynamic_command', ['command' => $cmdShow, 'message' => '/память', 'user_id' => $idA, 'username' => "{$marker}_nickA"]);
    check(str_contains($lastBotMsg(), 'не для твоей роли'), '/память без allowed: fail-closed отказ');

    // /память @чужой -> отказ (AC-4)
    $llm2->processTrigger('dynamic_command', ['command' => $cmdShow, 'message' => "/память @{$marker}_loginB", 'user_id' => $idA,
        'username' => "{$marker}_nickA", 'allowed' => true]);
    check(str_contains($lastBotMsg(), 'чужие досье не выдаю'), '/память о другом: отказ (AC-4)');

    // /память гостем -> отказ
    $llm2->processTrigger('dynamic_command', ['command' => $cmdShow, 'message' => '/память', 'user_id' => 0, 'username' => 'Гость', 'allowed' => true]);
    check(str_contains($lastBotMsg(), 'Гостям'), '/память гостем: отказ');

    // /память у пустого пользователя -> дружелюбная фраза
    $llm2->processTrigger('dynamic_command', ['command' => $cmdShow, 'message' => '/память', 'user_id' => $idC,
        'username' => "{$marker}_dupnick", 'allowed' => true]);
    check(str_contains($lastBotMsg(), 'ничего не записано'), '/память пусто: дружелюбный ответ (edge AC-4)');

    // /забудь: подтверждение БЕЗ текста записи + аудит
    $memeId = (int)$memeRow['id'];
    $llm2->processTrigger('dynamic_command', ['command' => $cmdForget, 'message' => "/забудь №{$memeId}", 'user_id' => $idA, 'username' => "{$marker}_nickA"]);
    $reply = $lastBotMsg();
    check(str_contains($reply, "№{$memeId}") && !str_contains($reply, 'тест-удавчика'), '/забудь: подтверждение без текста записи');
    check((int)$conn->query("SELECT COUNT(*) c FROM bot_memory WHERE id = {$memeId}")->fetch_assoc()['c'] === 0, '/забудь: запись удалена');
    $res = $conn->query("SELECT id, user_id, target_id, details FROM audit_logs WHERE action = 'memory_forget' ORDER BY id DESC LIMIT 1");
    $audit = $res->fetch_assoc();
    check($audit && (int)$audit['user_id'] === $idA && $audit['target_id'] === null
        && str_contains((string)$audit['details'], 'тест-удавчика'), '/забудь: аудит в audit_logs (мем: target_id NULL, текст в details)');
    if ($audit) $conn->query("DELETE FROM audit_logs WHERE id = " . (int)$audit['id']);

    // /забудь несуществующую
    $llm2->processTrigger('dynamic_command', ['command' => $cmdForget, 'message' => "/забудь №{$memeId}", 'user_id' => $idA, 'username' => "{$marker}_nickA"]);
    check(str_contains($lastBotMsg(), 'не нашла'), '/забудь несуществующую: отказ');

    // --- Инъекция блока (AC-1, AC-8, регрессы MLP-260/311) ---
    // Подтверждения бота вытеснили старые реплики из окна — добавляем свежую реплику
    // участника idA, чтобы он гарантированно был в окне контекста.
    $ins($idA, "{$marker} свежая реплика для окна");
    $strayId = $bm->add('dossier', $idC, 'посторонний факт вне окна', 'manual');
    $memRecIds[] = $strayId;

    $ctx = $llm2->buildReplyContext(10);
    $joined = implode("\n---\n", array_column($ctx, 'content'));
    check(str_contains($joined, LLM\LyraMemory::BLOCK_MARKER), 'AC-1: блок памяти в контексте');
    check(str_contains($joined, 'интеграционные тесты'), 'AC-1: досье участника окна в блоке');
    check(!str_contains($joined, 'посторонний факт'), 'AC-1: досье постороннего (не в окне) НЕ в блоке');

    $ctx = $llm2->buildReplyContext(10, null, null, false);
    check(!str_contains(implode('', array_column($ctx, 'content')), LLM\LyraMemory::BLOCK_MARKER),
        'регресс MLP-260: includePinned=false -> блока нет (наследование)');

    $ctx = $llm2->buildReplyContext(10, null, null, true, false);
    check(!str_contains(implode('', array_column($ctx, 'content')), LLM\LyraMemory::BLOCK_MARKER),
        'регресс MLP-311/опросы: явный includeMemory=false -> блока нет');

    // AC-5: правка видна следующей сборке
    $bm->updateText((int)$dosRow['id'], 'правленый факт про тесты');
    $ctx = $llm2->buildReplyContext(10);
    check(str_contains(implode('', array_column($ctx, 'content')), 'правленый факт'), 'AC-5: правка видна следующей сборке');

    // AC-8: выключатель гасит блок и права команд
    $cfg->setOption('ai_memory_enabled', '0');
    $ctx = $llm2->buildReplyContext(10);
    check(!str_contains(implode('', array_column($ctx, 'content')), LLM\LyraMemory::BLOCK_MARKER), 'AC-8: enabled=0 -> блока нет');
    $_SESSION['user_id'] = $idA; $_SESSION['role'] = 'moderator';
    check(Domain\BotMemoryManager::canTeach() === false && Domain\BotMemoryManager::canViewOwn() === false,
        'AC-8: enabled=0 -> canTeach/canViewOwn = false даже для модератора');
    $cfg->setOption('ai_memory_enabled', '1');
    check(Domain\BotMemoryManager::canTeach() === true && Domain\BotMemoryManager::canViewOwn() === true,
        'enabled=1: модератору можно учить и смотреть (дефолтные роли)');
    $_SESSION['role'] = 'user';
    check(Domain\BotMemoryManager::canTeach() === false, 'роль user: canTeach = false (teach_role=moderator)');
    check(Domain\BotMemoryManager::canViewOwn() === true, 'роль user: canViewOwn = true (view_role=all)');
    $cfg->setOption('ai_memory_teach_role', 'мусор');
    check(Domain\BotMemoryManager::canTeach() === false, 'мусорная teach_role -> fail-closed (moderator)');
    $cfg->setOption('ai_memory_view_role', 'мусор');
    check(Domain\BotMemoryManager::canViewOwn() === false, 'мусорная view_role -> fail-closed (admin)');
    unset($_SESSION['user_id'], $_SESSION['role']);

} finally {
    if (isset($optBackup)) {
        $cfgFin = Infra\ConfigManager::getInstance();
        foreach ($optBackup as $k => $v) {
            if ($v === null) {
                $conn->query("DELETE FROM site_options WHERE key_name = '" . $conn->real_escape_string($k) . "'");
            } else {
                $cfgFin->setOption($k, (string)$v);
            }
        }
    }
    if (!empty($memRecIds)) {
        $conn->query("DELETE FROM bot_memory WHERE id IN (" . implode(',', array_map('intval', array_filter($memRecIds))) . ")");
    }
    if (!empty($cleanupUserIds)) {
        $conn->query("DELETE FROM bot_memory WHERE user_id IN (" . implode(',', array_map('intval', $cleanupUserIds)) . ")");
    }
    if ($cleanupMsgIds) {
        $conn->query("DELETE FROM chat_messages WHERE id IN (" . implode(',', array_map('intval', $cleanupMsgIds)) . ")");
    }
    if ($cleanupUserIds) {
        $idsStr = implode(',', array_map('intval', $cleanupUserIds));
        $conn->query("DELETE FROM user_options WHERE user_id IN ($idsStr)");
        $conn->query("DELETE FROM users WHERE id IN ($idsStr)");
    }
    $conn->query("DELETE FROM llm_debug_log WHERE request LIKE '%test system%'");
}

it_done();
