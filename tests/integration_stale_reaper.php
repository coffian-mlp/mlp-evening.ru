<?php
use LLM\JobQueue;
/**
 * Интеграционный тест MLP-357: JobQueue::failStaleAny закрывает зависшие processing старше порога
 * для заданных типов и не трогает свежие, выполненные и чужие типы.
 *
 * Запуск: docker compose exec php php tests/integration_stale_reaper.php
 */
require_once __DIR__ . '/integration_helpers.php';

$conn = it_require_db();
$q = new JobQueue();
$marker = 'it357_' . getmypid();
$ids = [];
$add = function (string $type, string $status, int $claimedAgoSec) use ($conn, $marker, &$ids): int {
    $payload = json_encode(['it' => $marker]);
    $conn->query("INSERT INTO llm_jobs (type, payload, run_after, status, created_at, claimed_at)
                  VALUES ('{$type}', '{$payload}', NOW(), '{$status}', DATE_SUB(NOW(), INTERVAL {$claimedAgoSec} SECOND), DATE_SUB(NOW(), INTERVAL {$claimedAgoSec} SECOND))");
    return $ids[] = (int)$conn->insert_id;
};
$status = function (int $id) use ($conn): string {
    return (string)$conn->query("SELECT status FROM llm_jobs WHERE id = {$id}")->fetch_assoc()['status'];
};

try {
    $stale = $add('dynamic_command', 'processing', 20 * 60);
    $fresh = $add('mention', 'processing', 30);
    $done  = $add('greeting', 'done', 20 * 60);
    $other = $add('memory_scribe', 'processing', 20 * 60);

    $n = $q->failStaleAny(JobQueue::REACTIVE_TYPES, 600);
    check($n >= 1, "закрыто зависших: {$n}");
    check($status($stale) === 'failed', 'команда в processing 20 минут — failed');
    check($status($fresh) === 'processing', 'свежий processing не трогаем');
    check($status($done) === 'done', 'выполненную не трогаем');
    check($status($other) === 'processing', 'memory_scribe — у него свой реапер с порогом 30 минут');
    check($q->failStaleAny([], 600) === 0, 'пустой список типов — 0');
} finally {
    if ($ids) {
        $conn->query('DELETE FROM llm_jobs WHERE id IN (' . implode(',', $ids) . ')');
    }
}

it_done();
