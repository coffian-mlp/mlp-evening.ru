<?php
/**
 * MLP-318: напоминалки — менеджер, полный путь хендлера (фейк-LLM), доставка воркером.
 *
 * Запуск: docker compose exec php php tests/integration_reminders.php
 */

require_once __DIR__ . '/integration_helpers.php';

use Domain\ReminderManager;
use LLM\BotWorker;
use LLM\ReminderCommand;

$conn = it_require_db();
$marker = 'itr_' . getmypid();

$optBackup = [];
$cleanupUserIds = [];
$cleanupMsgIds = [];

try {
    $cfg = \Infra\ConfigManager::getInstance();

    $stmt = $conn->prepare("INSERT INTO users (login, nickname, email, password_hash, role) VALUES (?, ?, ?, 'x', 'user')");
    foreach ([["{$marker}_bot", "{$marker}_Лира"], ["{$marker}_user", "{$marker}_Пони"]] as [$l, $n]) {
        $e = $l . '@test.local';
        $stmt->bind_param('sss', $l, $n, $e);
        $stmt->execute();
        $cleanupUserIds[] = (int)$stmt->insert_id;
    }
    [$botId, $userId] = $cleanupUserIds;

    foreach (['ai_enabled' => '1', 'ai_bot_user_id' => (string)$botId, 'ai_routerai_key' => 'it-dummy',
              'ai_live_confirm' => '0' /* подтверждения фикс-фразой — детерминизм теста */] as $k => $v) {
        $optBackup[$k] = $cfg->getOption($k, null);
        $cfg->setOption($k, $v);
    }

    // === wants: обращение + триггер-слово ===
    check(ReminderCommand::wants('Лира, напомни мне через час достать колу') === true, 'wants: «Лира, напомни…»');
    check(ReminderCommand::wants('лирочка запланируй встречу') === true, 'wants: алиас + запланируй');
    check(ReminderCommand::wants('напомни мне про колу') === false, 'wants: без обращения — нет');
    check(ReminderCommand::wants('Лира, как дела?') === false, 'wants: обращение без триггера — нет');

    // === Менеджер ===
    $rm = new ReminderManager();
    check($rm->add(0, 'x', 'текст', gmdate('Y-m-d H:i:s')) === false, 'add: гость -> false');
    check($rm->add($userId, "{$marker}_Пони", '  ', gmdate('Y-m-d H:i:s')) === false, 'add: пустой текст -> false');

    // === Хендлер с фейк-LLM ===
    $llm = new LLM\LLMManager();
    $fake = new class implements LLM\LLMProviderInterface {
        public $reply = '';
        public function askChat(array $messagesContext, string $systemPrompt): ?string { return $this->reply; }
    };
    $rp = new ReflectionProperty(LLM\LLMManager::class, 'providers');
    $rp->setAccessible(true);
    $rp->setValue($llm, [$fake]);
    $rc = new ReminderCommand($llm);
    $cmd = ['handler_type' => 'reminder', 'command_prefix' => '/напомни'];

    $lastBotMsg = function () use ($conn, $botId, &$cleanupMsgIds): string {
        $res = $conn->query("SELECT id, message FROM chat_messages WHERE user_id = $botId ORDER BY id DESC LIMIT 1");
        $row = $res ? $res->fetch_assoc() : null;
        if ($row) $cleanupMsgIds[] = (int)$row['id'];
        return (string)($row['message'] ?? '');
    };

    // Создание «через час»
    $fake->reply = 'REMIND|3600|достать колу из холодильника';
    $rc->handle($cmd, ['message' => 'Лира, напомни мне через час достать колу', 'user_id' => $userId, 'username' => "{$marker}_Пони"]);
    $reply = $lastBotMsg();
    check(preg_match('/№(\d+)/u', $reply, $m) === 1 && str_contains($reply, 'достать колу'), 'создание: подтверждение с № и текстом');
    $remId = (int)$m[1];
    $row = $conn->query("SELECT * FROM reminders WHERE id = $remId")->fetch_assoc();
    $delta = strtotime($row['remind_at'] . ' UTC') - time();
    check($row['status'] === 'pending' && (int)$row['user_id'] === $userId && abs($delta - 3600) < 60,
        'создание: запись pending, remind_at ~ +3600с UTC');

    // REMIND_AT: конверсия МСК -> UTC (через 2 часа по МСК)
    $atMsk = gmdate('Y-m-d H:i', time() + ReminderCommand::MSK_OFFSET_SEC + 7200);
    $fake->reply = "REMIND_AT|{$atMsk}|про стрим";
    $rc->handle($cmd, ['message' => '/напомни в это время про стрим', 'user_id' => $userId, 'username' => "{$marker}_Пони"]);
    $reply = $lastBotMsg();
    check(preg_match('/№(\d+)/u', $reply, $m2) === 1, 'REMIND_AT: подтверждение с №');
    $row = $conn->query("SELECT remind_at FROM reminders WHERE id = " . (int)$m2[1])->fetch_assoc();
    $delta = strtotime($row['remind_at'] . ' UTC') - time();
    check(abs($delta - 7200) < 120, 'REMIND_AT: МСК корректно сконвертирован в UTC (~+2ч)');

    // NONE -> подсказка без записи
    $before = (int)$conn->query("SELECT COUNT(*) c FROM reminders")->fetch_assoc()['c'];
    $fake->reply = 'NONE';
    $rc->handle($cmd, ['message' => 'Лира, напомни', 'user_id' => $userId, 'username' => "{$marker}_Пони"]);
    check(str_contains($lastBotMsg(), 'не разобрала'), 'NONE: подсказка');
    check((int)$conn->query("SELECT COUNT(*) c FROM reminders")->fetch_assoc()['c'] === $before, 'NONE: записи нет');

    // Горизонты
    $fake->reply = 'REMIND|10|слишком скоро';
    $rc->handle($cmd, ['message' => 'x', 'user_id' => $userId, 'username' => "{$marker}_Пони"]);
    check(str_contains($lastBotMsg(), 'слишком скоро'), 'горизонт: <60с отказ');
    $fake->reply = 'REMIND|999999999|далеко';
    $rc->handle($cmd, ['message' => 'x', 'user_id' => $userId, 'username' => "{$marker}_Пони"]);
    check(str_contains($lastBotMsg(), 'недели'), 'горизонт: >7 дней отказ');

    // LIST
    $fake->reply = 'LIST';
    $rc->handle($cmd, ['message' => 'Лира, покажи напоминалки', 'user_id' => $userId, 'username' => "{$marker}_Пони"]);
    $reply = $lastBotMsg();
    check(str_contains($reply, "№{$remId}") && str_contains($reply, 'достать колу'), 'LIST: показывает активные с №');

    // CANCEL чужого — отказ; своего — ок
    $fake->reply = "CANCEL|{$remId}";
    $rc->handle($cmd, ['message' => 'x', 'user_id' => $userId + 999999, 'username' => 'чужак']);
    check(str_contains($lastBotMsg(), 'не нашла'), 'CANCEL чужого: отказ (скоуп по user_id)');
    $rc->handle($cmd, ['message' => 'x', 'user_id' => $userId, 'username' => "{$marker}_Пони"]);
    check(str_contains($lastBotMsg(), 'отменено'), 'CANCEL своего: ок');
    $st = $conn->query("SELECT status FROM reminders WHERE id = $remId")->fetch_assoc()['status'];
    check($st === 'cancelled', 'CANCEL: статус cancelled');

    // Лимит активных
    for ($i = 0; $i < ReminderManager::MAX_ACTIVE_PER_USER; $i++) {
        $rm->add($userId, "{$marker}_Пони", "заглушка $i {$marker}", gmdate('Y-m-d H:i:s', time() + 5000 + $i));
    }
    $fake->reply = 'REMIND|3600|шестое лишнее';
    $rc->handle($cmd, ['message' => 'x', 'user_id' => $userId, 'username' => "{$marker}_Пони"]);
    check(str_contains($lastBotMsg(), 'активных'), 'лимит 5: отказ');

    // Гость
    $rc->handle($cmd, ['message' => 'x', 'user_id' => 0, 'username' => 'Гость']);
    check(str_contains($lastBotMsg(), 'войди'), 'гость: отказ');

    // === Доставка воркером ===
    $dueId = $rm->add($userId, "{$marker}_Пони", "созревшее напоминание {$marker}", gmdate('Y-m-d H:i:s', time() - 5));
    $w = new BotWorker();
    $deliver = new ReflectionMethod(BotWorker::class, 'deliverReminders');
    $deliver->setAccessible(true);
    $deliver->invoke($w);
    $reply = $lastBotMsg();
    check(str_contains($reply, "{$marker}_Пони") && str_contains($reply, "созревшее напоминание {$marker}"),
        'доставка: адресат и текст в сообщении');
    $st = $conn->query("SELECT status FROM reminders WHERE id = " . (int)$dueId)->fetch_assoc()['status'];
    check($st === 'done', 'доставка: статус done');
    $deliver->invoke($w);
    $c = (int)$conn->query("SELECT COUNT(*) c FROM chat_messages WHERE user_id = $botId AND message LIKE '%созревшее напоминание {$marker}%'")->fetch_assoc()['c'];
    check($c === 1, 'доставка: повторный тик не дублирует');

} finally {
    if ($cleanupUserIds) {
        $ids = implode(',', array_map('intval', $cleanupUserIds));
        $conn->query("DELETE FROM reminders WHERE user_id IN ($ids) OR user_id = " . (int)($cleanupUserIds[1] + 999999));
        $conn->query("DELETE FROM users WHERE id IN ($ids)");
    }
    $conn->query("DELETE FROM reminders WHERE text LIKE '%{$marker}%'");
    if ($cleanupMsgIds) {
        $conn->query("DELETE FROM chat_messages WHERE id IN (" . implode(',', array_map('intval', array_unique($cleanupMsgIds))) . ")");
    }
    if ($optBackup) {
        foreach ($optBackup as $k => $v) {
            if ($v === null) {
                $conn->query("DELETE FROM site_options WHERE key_name = '" . $conn->real_escape_string($k) . "'");
            } else {
                $cfg->setOption($k, (string)$v);
            }
        }
        $cfg->flushCache();
    }
}

it_done();
