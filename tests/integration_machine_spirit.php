<?php
use Domain\UserManager;
use Domain\ChatManager;
use Infra\ConfigManager;
use LLM\BotDispatch;
use LLM\MachineSpirit;
/**
 * Интеграционный тест «духа машины» (MLP-300) с реальной БД.
 *
 * Сценарии: гейт wants() по опции; квитанция владельцу с цитатой (AC-1);
 * отказ чужому + кулдаун (AC-2); тишина при enabled=0 (AC-3); постановка
 * задачи machine_spirit в очередь без задержки (FR-1, FR-7).
 * LLM стабится через $ask — реальные провайдеры не вызываются.
 *
 * Запуск: docker compose exec php php tests/integration_machine_spirit.php
 */

require_once __DIR__ . '/integration_helpers.php';

$conn = it_require_db();

$config = ConfigManager::getInstance();
$um = new UserManager();
$cm = new ChatManager();

// --- Сохранение затрагиваемых опций ---
$saved = [];
foreach (['machine_spirit_enabled', 'machine_spirit_user_login', 'machine_spirit_owner_id',
          'machine_spirit_cooldown', 'machine_spirit_last_refusal',
          'ai_use_queue', 'ai_worker_mode', 'bot_worker_heartbeat'] as $k) {
    $saved[$k] = $config->getOption($k, null);
}

$pid = getmypid();
$spiritLogin = "it_spirit_$pid";
$ownerLogin  = "it_owner_$pid";
$guestLogin  = "it_guest_$pid";

$ownerId = $um->createUser($ownerLogin, 'password123', 'user', 'Ит. Магос');
$guestId = $um->createUser($guestLogin, 'password123', 'user', 'Ит. Гость');
check($ownerId > 0 && $guestId > 0, 'тестовые пользователи созданы');

$config->setOption('machine_spirit_user_login', $spiritLogin);
$config->setOption('machine_spirit_owner_id', (string)$ownerId);
$config->setOption('machine_spirit_cooldown', '120');
$config->setOption('machine_spirit_last_refusal', '0');

$cleanupMsgIds = [];
$spiritId = null;

try {
    // --- Гейт wants() по мастер-опции (AC-3) ---
    $config->setOption('machine_spirit_enabled', '0');
    $config->flushCache();
    check(MachineSpirit::wants('Клод включи перерыв') === false, 'enabled=0: wants() = false');

    $config->setOption('machine_spirit_enabled', '1');
    $config->flushCache();
    check(MachineSpirit::wants('Клод включи перерыв') === true, 'enabled=1: wants() = true');
    check(MachineSpirit::wants('про клодов болтаем') === false, 'не-обращение не матчится и при enabled=1');

    // --- handle(): enabled=0 → тишина (AC-3) ---
    $config->setOption('machine_spirit_enabled', '0');
    $config->flushCache();
    $called = false;
    (new MachineSpirit())->handle(
        ['message' => 'Клод тест', 'user_id' => $ownerId, 'username' => $ownerLogin],
        function () use (&$called) { $called = true; return 'не должно попасть в чат'; }
    );
    check($called === false, 'enabled=0: handle() не вызывает LLM');

    // --- Квитанция владельцу с цитатой (AC-1) ---
    $config->setOption('machine_spirit_enabled', '1');
    $config->flushCache();
    $cmdMsgId = $cm->addMessage($ownerId, 'Ит. Магос', 'Клод включи перерыв (ит-тест)');
    check(is_int($cmdMsgId) && $cmdMsgId > 0, 'сообщение-команда владельца сохранено');
    $cleanupMsgIds[] = $cmdMsgId;

    (new MachineSpirit())->handle(
        ['message' => 'Клод включи перерыв (ит-тест)', 'message_id' => $cmdMsgId,
         'user_id' => $ownerId, 'username' => $ownerLogin],
        fn() => 'Литания принятия: перерыв освящён. (ит-тест 1)'
    );
    $spirit = $um->getUserByLogin($spiritLogin);
    check(is_array($spirit), 'пользователь-дух автосоздан по логину из опций');
    $spiritId = (int)$spirit['id'];

    $res = $conn->query("SELECT id, message, quoted_msg_ids FROM chat_messages WHERE user_id = $spiritId ORDER BY id DESC LIMIT 1");
    $reply = $res ? $res->fetch_assoc() : null;
    check(is_array($reply) && strpos($reply['message'], 'Литания принятия') !== false, 'квитанция владельцу опубликована');
    if ($reply) $cleanupMsgIds[] = (int)$reply['id'];
    $quoted = json_decode($reply['quoted_msg_ids'] ?? '[]', true) ?: [];
    check(in_array($cmdMsgId, array_map('intval', $quoted), true), 'квитанция цитирует сообщение-команду');

    // --- Отказ чужому + кулдаун (AC-2) ---
    (new MachineSpirit())->handle(
        ['message' => 'Клод включи кино', 'user_id' => $guestId, 'username' => $guestLogin],
        fn() => 'Отказ: недостаточный уровень допуска. (ит-тест 2)'
    );
    $res = $conn->query("SELECT id, message FROM chat_messages WHERE user_id = $spiritId ORDER BY id DESC LIMIT 1");
    $refusal = $res ? $res->fetch_assoc() : null;
    check(is_array($refusal) && strpos($refusal['message'], 'Отказ') === 0, 'чужому опубликован отказ');
    if ($refusal) $cleanupMsgIds[] = (int)$refusal['id'];

    $countBefore = (int)$conn->query("SELECT COUNT(*) c FROM chat_messages WHERE user_id = $spiritId")->fetch_assoc()['c'];
    (new MachineSpirit())->handle(
        ['message' => 'Клод ну пожалуйста', 'user_id' => $guestId, 'username' => $guestLogin],
        fn() => 'Второй отказ, которого не должно быть'
    );
    $countAfter = (int)$conn->query("SELECT COUNT(*) c FROM chat_messages WHERE user_id = $spiritId")->fetch_assoc()['c'];
    check($countAfter === $countBefore, 'повторное чужое обращение в кулдауне — тишина');

    // Владелец в кулдауне отказов НЕ ограничен
    (new MachineSpirit())->handle(
        ['message' => 'Клод следующая серия', 'user_id' => $ownerId, 'username' => $ownerLogin],
        fn() => 'Литания продолжения. (ит-тест 3)'
    );
    $countOwner = (int)$conn->query("SELECT COUNT(*) c FROM chat_messages WHERE user_id = $spiritId")->fetch_assoc()['c'];
    check($countOwner === $countBefore + 1, 'кулдаун отказов не влияет на владельца');
    $res = $conn->query("SELECT id FROM chat_messages WHERE user_id = $spiritId ORDER BY id DESC LIMIT 1");
    if ($row = $res->fetch_assoc()) $cleanupMsgIds[] = (int)$row['id'];

    // --- Справка забывчивому Магосу (MLP-301) ---
    $helpInstruction = null;
    (new MachineSpirit())->handle(
        ['message' => 'Клод, команды', 'user_id' => $ownerId, 'username' => $ownerLogin],
        function ($prompt, $ctx) use (&$helpInstruction) {
            $helpInstruction = $ctx[0]['content'] ?? '';
            return 'Литания-перечень команд. (ит-тест 4)';
        }
    );
    check(is_string($helpInstruction) && strpos($helpInstruction, 'перечень') !== false
        && strpos($helpInstruction, 'включи перерыв') !== false, 'help-инструкция содержит перечень команд');
    $res = $conn->query("SELECT id, message FROM chat_messages WHERE user_id = $spiritId ORDER BY id DESC LIMIT 1");
    $help = $res ? $res->fetch_assoc() : null;
    check(is_array($help) && strpos($help['message'], 'перечень команд') !== false, 'справка опубликована');
    if ($help) $cleanupMsgIds[] = (int)$help['id'];

    // --- Нераспознанное обращение владельца (MLP-302) ---
    $unknownInstruction = null;
    (new MachineSpirit())->handle(
        ['message' => 'Клод, как дела?', 'user_id' => $ownerId, 'username' => $ownerLogin],
        function ($prompt, $ctx) use (&$unknownInstruction) {
            $unknownInstruction = $ctx[0]['content'] ?? '';
            return 'Недоумение когитатора. (ит-тест 5)';
        }
    );
    check(is_string($unknownInstruction) && strpos($unknownInstruction, 'не распознал') !== false,
        'нераспознанное обращение владельца -> инструкция «команда не распознана»');
    $res = $conn->query("SELECT id, message FROM chat_messages WHERE user_id = $spiritId ORDER BY id DESC LIMIT 1");
    $unk = $res ? $res->fetch_assoc() : null;
    check(is_array($unk) && strpos($unk['message'], 'Недоумение') !== false, 'ответ о нераспознанной команде опубликован');
    if ($unk) $cleanupMsgIds[] = (int)$unk['id'];

    // --- Очередь: dispatch кладёт machine_spirit без задержки (FR-1, FR-7) ---
    $config->setOption('ai_use_queue', '1');
    $config->setOption('ai_worker_mode', 'daemon');
    $config->flushCache();
    BotDispatch::dispatch('machine_spirit', [
        'message' => 'Клод включи перерыв (queue-тест)', 'message_id' => null,
        'user_id' => $ownerId, 'username' => $ownerLogin,
    ]);
    $res = $conn->query("SELECT id, run_after <= NOW() AS due FROM llm_jobs WHERE type = 'machine_spirit' ORDER BY id DESC LIMIT 1");
    $job = $res ? $res->fetch_assoc() : null;
    check(is_array($job), 'dispatch поставил задачу machine_spirit в llm_jobs');
    check($job && (int)$job['due'] === 1, 'задача без lifelike-задержки (run_after <= now)');

    // Воркер обязан ВЫБИРАТЬ этот тип (регрессия первого деплоя MLP-300:
    // фильтр типов в claimDue не включал machine_spirit — задачи висели pending).
    $claimed = (new \LLM\JobQueue())->claimDue(50);
    $claimedIds = array_map(static fn($j) => (int)$j['id'], $claimed);
    check($job && in_array((int)$job['id'], $claimedIds, true), 'claimDue() забирает задачи machine_spirit');
    if ($job) $conn->query("DELETE FROM llm_jobs WHERE id = " . (int)$job['id']);
} finally {
    // --- Очистка: сообщения, пользователи, опции ---
    if ($cleanupMsgIds) {
        $ids = implode(',', array_map('intval', array_unique($cleanupMsgIds)));
        $conn->query("DELETE FROM chat_messages WHERE id IN ($ids)");
    }
    foreach ([$spiritId, $ownerId, $guestId] as $uid) {
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
