<?php
/**
 * MLP-317: живые подтверждения команд (botSayLive) — LLM озвучивает выполненное
 * действие, страховки откатывают на фикс-фразы. Провайдер подменяется через Reflection.
 *
 * Запуск: docker compose exec php php tests/integration_live_confirm.php
 */

require_once __DIR__ . '/integration_helpers.php';

$conn = it_require_db();
$marker = 'itlc_' . getmypid();

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
              'ai_live_confirm' => '1', 'ai_memory_enabled' => '1'] as $k => $v) {
        $optBackup[$k] = $cfg->getOption($k, null);
        $cfg->setOption($k, $v);
    }

    $llm = new LLM\LLMManager();
    $fake = new class implements LLM\LLMProviderInterface {
        public $reply = '';
        public function askChat(array $messagesContext, string $systemPrompt): ?string {
            return $this->reply;
        }
    };
    $rp = new ReflectionProperty(LLM\LLMManager::class, 'providers');
    $rp->setAccessible(true);
    $rp->setValue($llm, [$fake]);

    $lastBotMsg = function () use ($conn, $botId, &$cleanupMsgIds): string {
        $res = $conn->query("SELECT id, message FROM chat_messages WHERE user_id = $botId ORDER BY id DESC LIMIT 1");
        $row = $res ? $res->fetch_assoc() : null;
        if ($row) $cleanupMsgIds[] = (int)$row['id'];
        return (string)($row['message'] ?? '');
    };

    // 1) /todo: живой ответ с номером постится как есть
    $fake->reply = "О, шикарная идея, {$marker}_Пони! Записала в свиток под №{PLACEHOLDER} — Принцессы оценят.";
    // номер неизвестен заранее — используем botSayLive напрямую? Нет: полный путь /todo.
    // Настроим фейк так, чтобы номер совпал: сначала узнаем следующий id.
    $nextId = (int)$conn->query("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'feedback_backlog'")->fetch_assoc()['AUTO_INCREMENT'];
    $fake->reply = "О, шикарная идея! Записала в свиток под №{$nextId} — Принцессы оценят. ✨";
    $llm->processTrigger('dynamic_command', [
        'command' => ['handler_type' => 'todo', 'command_prefix' => '/todo'],
        'message' => "/todo живая идея {$marker}", 'user_id' => $userId, 'username' => "{$marker}_Пони",
    ]);
    $reply = $lastBotMsg();
    check(str_contains($reply, 'шикарная идея') && str_contains($reply, "№{$nextId}"), '/todo: живой ответ LLM с номером постится');
    $conn->query("DELETE FROM feedback_backlog WHERE text LIKE '%{$marker}%'");

    // 2) Живой ответ БЕЗ номера -> откат на фикс-фразу (номер гарантирован)
    $fake->reply = "Записала куда-то, не помню куда!"; // потерян №
    $nextId = (int)$conn->query("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'feedback_backlog'")->fetch_assoc()['AUTO_INCREMENT'];
    $llm->processTrigger('dynamic_command', [
        'command' => ['handler_type' => 'todo', 'command_prefix' => '/todo'],
        'message' => "/todo вторая {$marker}", 'user_id' => $userId, 'username' => "{$marker}_Пони",
    ]);
    $reply = $lastBotMsg();
    check(!str_contains($reply, 'не помню куда') && preg_match('/№\d+/u', $reply) === 1,
        'ответ без номера: откат на фикс-фразу с гарантированным №');
    $conn->query("DELETE FROM feedback_backlog WHERE text LIKE '%{$marker}%'");

    // 3) Молчание LLM -> фикс-фраза
    $fake->reply = '';
    $llm->processTrigger('dynamic_command', [
        'command' => ['handler_type' => 'todo', 'command_prefix' => '/todo'],
        'message' => "/todo третья {$marker}", 'user_id' => $userId, 'username' => "{$marker}_Пони",
    ]);
    $reply = $lastBotMsg();
    check(preg_match('/№\d+/u', $reply) === 1 && str_contains($reply, "{$marker}_Пони"), 'молчание LLM: фикс-фраза');
    $conn->query("DELETE FROM feedback_backlog WHERE text LIKE '%{$marker}%'");

    // 4) Опция выключена -> фикс-фраза без LLM
    $cfg->setOption('ai_live_confirm', '0');
    $fake->reply = 'НЕ ДОЛЖНО ПОЯВИТЬСЯ';
    $llm->processTrigger('dynamic_command', [
        'command' => ['handler_type' => 'todo', 'command_prefix' => '/todo'],
        'message' => "/todo четвёртая {$marker}", 'user_id' => $userId, 'username' => "{$marker}_Пони",
    ]);
    $reply = $lastBotMsg();
    check(!str_contains($reply, 'НЕ ДОЛЖНО') && preg_match('/№\d+/u', $reply) === 1, 'ai_live_confirm=0: фикс-фраза, LLM не используется');
    $conn->query("DELETE FROM feedback_backlog WHERE text LIKE '%{$marker}%'");
    $cfg->setOption('ai_live_confirm', '1');

    // 5) /забудь: живая инструкция НЕ содержит текст записи (приватность)
    $bm = new Domain\BotMemoryManager();
    $recId = $bm->add('meme', null, "секретный мем {$marker}", 'manual');
    $captured = new class implements LLM\LLMProviderInterface {
        public $lastSystem = ''; public $lastContext = [];
        public function askChat(array $messagesContext, string $systemPrompt): ?string {
            $this->lastSystem = $systemPrompt; $this->lastContext = $messagesContext;
            return "Пуф — записи №%d больше нет!"; // без номера -> откат, но нам важен захват промпта
        }
    };
    $rp->setValue($llm, [$captured]);
    $llm->processTrigger('dynamic_command', [
        'command' => ['handler_type' => 'memory_forget', 'command_prefix' => '/забудь'],
        'message' => "/забудь №{$recId}", 'user_id' => $userId, 'username' => "{$marker}_Пони",
    ]);
    $lastBotMsg();
    check(!str_contains($captured->lastSystem, 'секретный мем'), '/забудь: содержимое записи НЕ утекает в живую инструкцию');
    check((int)$conn->query("SELECT COUNT(*) c FROM bot_memory WHERE id = " . (int)$recId)->fetch_assoc()['c'] === 0,
        '/забудь: запись удалена независимо от судьбы живого ответа');

} finally {
    $conn->query("DELETE FROM feedback_backlog WHERE text LIKE '%{$marker}%'");
    $conn->query("DELETE FROM bot_memory WHERE text LIKE '%{$marker}%'");
    if ($cleanupMsgIds) {
        $conn->query("DELETE FROM chat_messages WHERE id IN (" . implode(',', array_map('intval', array_unique($cleanupMsgIds))) . ")");
    }
    if ($cleanupUserIds) {
        $conn->query("DELETE FROM users WHERE id IN (" . implode(',', array_map('intval', $cleanupUserIds)) . ")");
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
