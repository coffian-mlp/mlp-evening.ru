<?php
/**
 * MLP-320: mention в паузе rate-limit откладывается (release), а не съедается;
 * кап переносов; single-ответ строится по свежему контексту с прицельной
 * инструкцией последней репликой (анти-дубль, кейс ГТА 22.08).
 *
 * Запуск: docker compose exec php php tests/integration_mention_defer.php
 */

require_once __DIR__ . '/integration_helpers.php';

use LLM\BotWorker;
use LLM\JobQueue;

$conn = it_require_db();
$marker = 'imd_' . getmypid();

$optBackup = [];
$cleanupUserIds = [];
$cleanupJobIds = [];

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
              'ai_reply_min_gap' => '20', 'ai_reactions' => '0'] as $k => $v) {
        $optBackup[$k] = $cfg->getOption($k, null);
        $cfg->setOption($k, $v);
    }

    $chat = new \Domain\ChatManager();
    $queue = new JobQueue();
    $worker = new BotWorker();
    $handle = new ReflectionMethod(BotWorker::class, 'handleMentions');
    $handle->setAccessible(true);

    // Бот «только что ответил» — свежее сообщение задаёт lastBotReplyTs = сейчас.
    $botMsgId = (int)$chat->addMessage($botId, "{$marker}_Лира", "свежий ответ бота {$marker}");
    $qMsgId = (int)$chat->addMessage($userId, "{$marker}_Пони", "как заработать денег в ГТА? {$marker}");

    $payload = ['message' => "как заработать денег в ГТА? {$marker}", 'message_id' => $qMsgId,
                'user_id' => $userId, 'username' => "{$marker}_Пони"];
    $jobId = $queue->enqueue('mention', $payload, 0);
    $cleanupJobIds[] = $jobId;
    $conn->query("UPDATE llm_jobs SET status='processing', claimed_at=NOW() WHERE id = $jobId"); // симуляция claim

    // === rate_limited -> release (отложен), а не done ===
    $handle->invoke($worker, [['id' => $jobId, 'attempts' => 0] + $payload]);
    $row = $conn->query("SELECT status, attempts, run_after > NOW() AS deferred FROM llm_jobs WHERE id = $jobId")->fetch_assoc();
    check($row['status'] === 'pending', 'rate_limited: job возвращён в pending (не съеден)');
    check((int)$row['attempts'] === 1, 'rate_limited: attempts инкрементирован');
    check((int)$row['deferred'] === 1, 'rate_limited: run_after сдвинут в будущее (остаток паузы)');
    $lastBot = $conn->query("SELECT id FROM chat_messages WHERE user_id = $botId ORDER BY id DESC LIMIT 1")->fetch_assoc();
    check((int)$lastBot['id'] === $botMsgId, 'rate_limited: бот ничего не постил');

    // === Кап переносов: attempts >= 3 -> done без ответа (защита от вечного откладывания) ===
    $conn->query("UPDATE llm_jobs SET status='processing', attempts=3 WHERE id = $jobId");
    $handle->invoke($worker, [['id' => $jobId, 'attempts' => 3] + $payload]);
    $st = $conn->query("SELECT status FROM llm_jobs WHERE id = $jobId")->fetch_assoc()['status'];
    check($st === 'done', 'кап переносов: после 3 попыток job закрывается');

    // === Ответ по свежему контексту с прицельной инструкцией последней репликой ===
    $conn->query("UPDATE chat_messages SET created_at = DATE_SUB(created_at, INTERVAL 60 SECOND) WHERE id = $botMsgId");
    $fake = new class implements LLM\LLMProviderInterface {
        public $captured = null;
        public function askChat(array $messagesContext, string $systemPrompt): ?string {
            $this->captured = $messagesContext;
            return 'Отвечаю на прицел!';
        }
    };
    $rpLlm = new ReflectionProperty(BotWorker::class, 'llm');
    $rpLlm->setAccessible(true);
    $llm = $rpLlm->getValue($worker);
    $rpProviders = new ReflectionProperty(LLM\LLMManager::class, 'providers');
    $rpProviders->setAccessible(true);
    $rpProviders->setValue($llm, [$fake]);

    $jobId2 = $queue->enqueue('mention', $payload, 0);
    $cleanupJobIds[] = $jobId2;
    $conn->query("UPDATE llm_jobs SET status='processing', claimed_at=NOW() WHERE id = $jobId2");
    $handle->invoke($worker, [['id' => $jobId2, 'attempts' => 0] + $payload]);

    $st = $conn->query("SELECT status FROM llm_jobs WHERE id = $jobId2")->fetch_assoc()['status'];
    check($st === 'done', 'reply: job завершён');
    $lastBot = $conn->query("SELECT id, message FROM chat_messages WHERE user_id = $botId ORDER BY id DESC LIMIT 1")->fetch_assoc();
    check(str_contains((string)$lastBot['message'], 'Отвечаю на прицел'), 'reply: ответ бота опубликован');

    check(is_array($fake->captured) && $fake->captured, 'reply: контекст дошёл до провайдера');
    $last = end($fake->captured);
    check(($last['role'] ?? '') === 'user'
        && str_contains((string)$last['content'], 'именно на это сообщение')
        && str_contains((string)$last['content'], "@{$marker}_Пони")
        && str_contains((string)$last['content'], 'как заработать денег в ГТА?'),
        'reply: прицельная инструкция — последней репликой контекста, с автором и текстом');
    $all = implode("\n", array_map(static fn($m) => (string)$m['content'], $fake->captured));
    check(str_contains($all, "свежий ответ бота {$marker}"),
        'reply: контекст свежий — собственный недавний ответ бота виден (нет обрезки beforeId)');

} finally {
    if ($cleanupUserIds) {
        $ids = implode(',', array_map('intval', $cleanupUserIds));
        $conn->query("DELETE FROM chat_messages WHERE user_id IN ($ids)");
        $conn->query("DELETE FROM users WHERE id IN ($ids)");
    }
    if ($cleanupJobIds) {
        $conn->query("DELETE FROM llm_jobs WHERE id IN (" . implode(',', array_map('intval', $cleanupJobIds)) . ")");
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
