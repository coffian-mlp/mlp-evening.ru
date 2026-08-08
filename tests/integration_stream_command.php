<?php
use Domain\UserManager;
use Domain\ChatManager;
use Infra\ConfigManager;
use LLM\BotDispatch;
use LLM\StreamCommand;
/**
 * Интеграционный тест команд стрима (MLP-307) с реальной БД.
 *
 * Сценарии: гейт wants() по опции и по наличию команды (AC-2: болтовня не
 * перехватывается); подтверждение владельцу от имени бота с цитатой (AC-1);
 * перечень команд (AC-3); отказ чужому + кулдаун (AC-4); постановка задачи
 * stream_command в очередь без задержки и её выборка воркером.
 * LLM стабится через $ask — реальные провайдеры не вызываются.
 *
 * Запуск: docker compose exec php php tests/integration_stream_command.php
 */

require_once __DIR__ . '/integration_helpers.php';

$conn = it_require_db();

$config = ConfigManager::getInstance();
$um = new UserManager();
$cm = new ChatManager();

$saved = [];
foreach (['stream_command_enabled', 'stream_command_owner_id', 'stream_command_cooldown',
          'stream_command_last_refusal', 'ai_use_queue', 'ai_worker_mode',
          'ai_reply_min_gap', 'ai_bot_user_id', 'ai_reactions'] as $k) {
    $saved[$k] = $config->getOption($k, null);
}

$pid = getmypid();
$ownerId = $um->createUser("it_owner_$pid", 'password123', 'user', 'Ит. Владелец');
$guestId = $um->createUser("it_guest_$pid", 'password123', 'user', 'Ит. Гость');
$botId   = $um->createUser("it_bot_$pid", 'password123', 'user', 'Ит. Лира');
check($ownerId > 0 && $guestId > 0 && $botId > 0, 'тестовые пользователи созданы');

$config->setOption('ai_bot_user_id', (string)$botId);
$config->setOption('stream_command_owner_id', (string)$ownerId);
$config->setOption('stream_command_cooldown', '120');
$config->setOption('stream_command_last_refusal', '0');
$config->setOption('ai_reply_min_gap', '0');
$config->setOption('ai_reactions', '0');

$cleanupMsgIds = [];

try {
    // --- Гейт wants(): опция + наличие команды (AC-2) ---
    $config->setOption('stream_command_enabled', '0');
    $config->flushCache();
    check(StreamCommand::wants('Лира, включи перерыв') === false, 'enabled=0: wants() = false');

    $config->setOption('stream_command_enabled', '1');
    $config->flushCache();
    check(StreamCommand::wants('Лира, включи перерыв') === true, 'enabled=1 + команда: wants() = true');
    check(StreamCommand::wants('Лира, как дела?') === false, 'обращение без команды НЕ перехватывается (AC-2)');
    check(StreamCommand::wants('включи перерыв') === false, 'команда без обращения не перехватывается');
    check(StreamCommand::wants('Лира, команды') === true, 'запрос перечня перехватывается');

    // --- handle(): enabled=0 → тишина ---
    $config->setOption('stream_command_enabled', '0');
    $config->flushCache();
    $called = false;
    (new StreamCommand())->handle(
        ['message' => 'Лира, кино', 'user_id' => $ownerId, 'username' => "it_owner_$pid"],
        function () use (&$called) { $called = true; return 'не должно попасть в чат'; }
    );
    check($called === false, 'enabled=0: handle() не вызывает LLM');

    // --- Подтверждение владельцу от имени бота, с цитатой (AC-1) ---
    $config->setOption('stream_command_enabled', '1');
    $config->flushCache();
    $cmdMsgId = $cm->addMessage($ownerId, 'Ит. Владелец', 'Лира, включи перерыв (ит-тест)');
    check(is_int($cmdMsgId) && $cmdMsgId > 0, 'сообщение-команда владельца сохранено');
    $cleanupMsgIds[] = $cmdMsgId;

    $ownerInstruction = null;
    (new StreamCommand())->handle(
        ['message' => 'Лира, включи перерыв (ит-тест)', 'message_id' => $cmdMsgId,
         'user_id' => $ownerId, 'username' => "it_owner_$pid"],
        function ($ctx, $instruction) use (&$ownerInstruction) {
            $ownerInstruction = $instruction;
            return 'Копытами неудобно, но переключила! (ит-тест 1)';
        }
    );
    check(is_string($ownerInstruction) && strpos($ownerInstruction, 'владелец трансляции') !== false,
        'инструкция владельца: подтвердить исполнение');
    check(strpos((string)$ownerInstruction, 'своими словами') !== false, 'инструкция требует ответа в характере');

    $res = $conn->query("SELECT id, message, quoted_msg_ids FROM chat_messages WHERE user_id = $botId ORDER BY id DESC LIMIT 1");
    $reply = $res ? $res->fetch_assoc() : null;
    check(is_array($reply) && strpos($reply['message'], 'Копытами неудобно') !== false, 'ответ опубликован от имени бота');
    if ($reply) $cleanupMsgIds[] = (int)$reply['id'];
    $quoted = json_decode($reply['quoted_msg_ids'] ?? '[]', true) ?: [];
    check(in_array($cmdMsgId, array_map('intval', $quoted), true), 'ответ цитирует сообщение-команду');

    // --- Перечень команд (AC-3) ---
    $helpInstruction = null;
    (new StreamCommand())->handle(
        ['message' => 'Лира, команды', 'user_id' => $ownerId, 'username' => "it_owner_$pid"],
        function ($ctx, $instruction) use (&$helpInstruction) {
            $helpInstruction = $instruction;
            return 'Вот что я умею. (ит-тест 2)';
        }
    );
    check(is_string($helpInstruction) && strpos($helpInstruction, 'включи перерыв') !== false,
        'инструкция перечня содержит список команд');
    $res = $conn->query("SELECT id FROM chat_messages WHERE user_id = $botId ORDER BY id DESC LIMIT 1");
    if ($row = $res->fetch_assoc()) $cleanupMsgIds[] = (int)$row['id'];

    // --- Отказ чужому + кулдаун (AC-4) ---
    $g1 = $cm->addMessage($guestId, 'Ит. Гость', 'Лира, включи кино (ит-тест, гость)');
    if (is_int($g1)) $cleanupMsgIds[] = $g1;
    $guestInstruction = null;
    (new StreamCommand())->handle(
        ['message' => 'Лира, включи кино', 'user_id' => $guestId, 'username' => "it_guest_$pid"],
        function ($ctx, $instruction) use (&$guestInstruction) {
            $guestInstruction = $instruction;
            return 'Ой, это только хозяин вечера может! (ит-тест 3)';
        }
    );
    check(is_string($guestInstruction) && strpos($guestInstruction, 'только её владелец') !== false,
        'инструкция чужому: мягкий отказ');
    check(strpos((string)$guestInstruction, 'включи кино') === false,
        'текст чужого сообщения в инструкцию НЕ попадает (анти-инъекция)');
    $res = $conn->query("SELECT id, message FROM chat_messages WHERE user_id = $botId ORDER BY id DESC LIMIT 1");
    $refusal = $res ? $res->fetch_assoc() : null;
    check(is_array($refusal) && strpos($refusal['message'], 'только хозяин') !== false, 'отказ опубликован');
    if ($refusal) $cleanupMsgIds[] = (int)$refusal['id'];

    $countBefore = (int)$conn->query("SELECT COUNT(*) c FROM chat_messages WHERE user_id = $botId")->fetch_assoc()['c'];
    $g2 = $cm->addMessage($guestId, 'Ит. Гость', 'ну пожалуйста (ит-тест)');
    if (is_int($g2)) $cleanupMsgIds[] = $g2;
    (new StreamCommand())->handle(
        ['message' => 'Лира, включи кино', 'user_id' => $guestId, 'username' => "it_guest_$pid"],
        fn() => 'второй отказ, которого быть не должно'
    );
    $countAfter = (int)$conn->query("SELECT COUNT(*) c FROM chat_messages WHERE user_id = $botId")->fetch_assoc()['c'];
    check($countAfter === $countBefore, 'повторная чужая команда в кулдауне — тишина');

    // Владелец в кулдауне отказов НЕ ограничен
    (new StreamCommand())->handle(
        ['message' => 'Лира, следующая серия', 'user_id' => $ownerId, 'username' => "it_owner_$pid"],
        fn() => 'Уже включаю следующую! (ит-тест 4)'
    );
    $countOwner = (int)$conn->query("SELECT COUNT(*) c FROM chat_messages WHERE user_id = $botId")->fetch_assoc()['c'];
    check($countOwner === $countBefore + 1, 'кулдаун отказов не влияет на владельца');
    $res = $conn->query("SELECT id FROM chat_messages WHERE user_id = $botId ORDER BY id DESC LIMIT 1");
    if ($row = $res->fetch_assoc()) $cleanupMsgIds[] = (int)$row['id'];

    // --- Очередь: тип stream_command без задержки + выборка воркером ---
    $config->setOption('ai_use_queue', '1');
    $config->setOption('ai_worker_mode', 'daemon');
    $config->flushCache();
    BotDispatch::dispatch('stream_command', [
        'message' => 'Лира, включи перерыв (queue-тест)', 'message_id' => null,
        'user_id' => $ownerId, 'username' => "it_owner_$pid",
    ]);
    $res = $conn->query("SELECT id, run_after <= NOW() AS due FROM llm_jobs WHERE type = 'stream_command' ORDER BY id DESC LIMIT 1");
    $job = $res ? $res->fetch_assoc() : null;
    check(is_array($job), 'dispatch поставил задачу stream_command в llm_jobs');
    check($job && (int)$job['due'] === 1, 'задача без lifelike-задержки (run_after <= now)');

    // Регрессия MLP-300: новый тип обязан попадать в фильтр claimDue
    $claimed = (new \LLM\JobQueue())->claimDue(50);
    $claimedIds = array_map(static fn($j) => (int)$j['id'], $claimed);
    check($job && in_array((int)$job['id'], $claimedIds, true), 'claimDue() забирает задачи stream_command');
    if ($job) $conn->query("DELETE FROM llm_jobs WHERE id = " . (int)$job['id']);
} finally {
    if ($cleanupMsgIds) {
        $ids = implode(',', array_map('intval', array_unique($cleanupMsgIds)));
        $conn->query("DELETE FROM chat_messages WHERE id IN ($ids)");
    }
    foreach ([$ownerId, $guestId, $botId] as $uid) {
        if ($uid) $conn->query("DELETE FROM users WHERE id = " . (int)$uid);
    }
    foreach ($saved as $k => $v) {
        if ($v === null) {
            $conn->query("DELETE FROM site_options WHERE key_name = '" . $conn->real_escape_string($k) . "'");
        } else {
            $config->setOption($k, (string)$v);
        }
    }
    $config->flushCache();
}

it_done();
