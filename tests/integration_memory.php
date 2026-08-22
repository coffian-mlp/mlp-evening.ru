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

} finally {
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
