<?php
namespace LLM;

use Infra\ConfigManager;
use Infra\Database;

/** Durable reply delivery never repeats a command with side effects. */
final class CommandReplyDelivery {
    public const MAX_ATTEMPTS = 8;

    public static function handles(array $job): bool {
        return ($job['type'] ?? '') === 'dynamic_command'
            && in_array($job['data']['command']['handler_type'] ?? '', ['text', 'schedule', 'command_reply_delivery'], true);
    }

    public static function confirmation(LLMManager $llm, string $instruction, string $fallback, array $quotes, ?string $mustContain, string $message): bool {
        $queue = new JobQueue();
        $data = ['command' => ['handler_type' => 'command_reply_delivery'], 'message' => $message,
            'confirmation' => compact('instruction', 'fallback', 'quotes', 'mustContain')];
        $id = $queue->enqueue('dynamic_command', $data, 0);
        self::processJob($llm, $queue, ['id' => $id, 'type' => 'dynamic_command', 'data' => $data, 'attempts' => 0], $llm->isEnabled());
        return true; // Confirmation accepted independently of the completed action.
    }

    public static function processJob(LLMManager $llm, JobQueue $queue, array $job, bool $enabled): void {
        $id = (int)$job['id'];
        if (!(int)ConfigManager::getInstance()->getOption('ai_enabled', 0)) { $queue->pauseReply($id); return; }
        try {
            if ((int)($job['attempts'] ?? 0) >= self::MAX_ATTEMPTS && !isset($job['data']['prepared_reply'])) {
                if (!isset($job['data']['confirmation'])) { $queue->fail([$id]); return; }
                $job['data']['prepared_reply'] = ['text' => $job['data']['confirmation']['fallback'], 'quotes' => $job['data']['confirmation']['quotes']];
                $job['data'] = $queue->savePayload($id, $job['data']);
            }
            if (isset($job['data']['prepared_reply'])) {
                if (self::publish($llm, $job)) return;
            } elseif ($enabled || isset($job['data']['confirmation'])) {
                $confirmation = $job['data']['confirmation'] ?? null;
                if ($confirmation !== null) {
                    $text = $enabled ? $llm->commandConfirmationText($confirmation['instruction'], $confirmation['mustContain'], (string)($job['data']['message'] ?? '')) : null;
                    if ($text === null && (!(int)ConfigManager::getInstance()->getOption('ai_live_confirm', 1)
                        || (int)($job['attempts'] ?? 0) >= self::MAX_ATTEMPTS - 1)) $text = $confirmation['fallback'];
                    if ($text !== null) {
                        $job['data']['prepared_reply'] = ['text' => $text, 'quotes' => $confirmation['quotes']];
                        $job['data'] = $queue->savePayload($id, $job['data']);
                        if (self::publish($llm, $job)) return;
                    }
                } elseif ($llm->withReplyJob($job, fn() => $llm->processTrigger('dynamic_command', $job['data']))) {
                    return;
                }
            }
        } catch (\Throwable $error) {
            error_log('Command reply deferred: job=' . $id . ' class=' . get_class($error));
        }
        if ((int)($job['attempts'] ?? 0) >= self::MAX_ATTEMPTS - 1) $queue->fail([$id]);
        else $queue->release([$id], ScheduleAnnouncement::retryDelay((int)($job['attempts'] ?? 0)));
    }

    /** Message and delivery receipt commit together, before realtime transport. */
    public static function publish(LLMManager $llm, array $job): int {
        $db = Database::getInstance()->getConnection(); $id = (int)$job['id'];
        $reply = $job['data']['prepared_reply']; $payload = null; $receipt = 0;
        $chat = $llm->getChatManager();
        try {
            $messageId = $chat->addInteractionMessageGuarded($llm->getBotUserId(), $llm->getBotNickname(), $reply['text'], $reply['quotes'],
                function () use ($db, $id, &$payload, &$receipt) {
                    $stmt = $db->prepare('SELECT payload FROM llm_jobs WHERE id=? FOR UPDATE');
                    $stmt->bind_param('i', $id); $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                    if (!$row) throw new \RuntimeException('Missing reply job');
                    $payload = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
                    $receipt = (int)($payload['reply_message_id'] ?? 0);
                    if ($receipt > 0) throw new \RuntimeException('Reply already delivered');
                },
                function (int $messageId) use ($id, &$payload) {
                    $payload['reply_message_id'] = $messageId;
                    (new JobQueue())->savePayload($id, $payload); (new JobQueue())->complete([$id]);
                }, true);
        } catch (\Throwable $error) {
            if ($receipt < 1) throw $error;
            (new JobQueue())->complete([$id]);
            return $receipt;
        }
        try { $chat->publishInteractionMessage($messageId); }
        catch (\Throwable $error) { error_log('Reply realtime notification failed: job=' . $id); }
        return $messageId;
    }
}
