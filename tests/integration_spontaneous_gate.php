<?php
use LLM\JobQueue;
/**
 * Интеграционный тест MLP-355: JobQueue::hasPendingAny — ждущая реакция (pending или processing)
 * глушит спонтанку, завершённая и зависшая старше окна — нет.
 *
 * Запуск: docker compose exec php php tests/integration_spontaneous_gate.php
 */
require_once __DIR__ . '/integration_helpers.php';

$conn = it_require_db();
$q = new JobQueue();
$marker = 'it355_' . getmypid();
$ids = [];
$add = function (string $type, string $status, string $createdAgo) use ($conn, $marker, &$ids): int {
    $payload = json_encode(['it' => $marker]);
    $conn->query("INSERT INTO llm_jobs (type, payload, run_after, status, created_at)
                  VALUES ('{$type}', '{$payload}', DATE_ADD(NOW(), INTERVAL 1 HOUR), '{$status}', DATE_SUB(NOW(), INTERVAL {$createdAgo}))");
    return $ids[] = (int)$conn->insert_id;
};

try {
    $base = $q->hasPendingAny(JobQueue::ANSWER_TYPES);
    if ($base) {
        it_skip('в очереди уже есть живая реакция — изоляция невозможна');
    }
    echo "== Ждущая команда глушит ==\n";
    $cmd = $add('dynamic_command', 'processing', '5 SECOND');
    check($q->hasPendingAny(JobQueue::ANSWER_TYPES) === true, 'команда в работе (processing) — глушит');
    $conn->query("UPDATE llm_jobs SET status = 'done' WHERE id = {$cmd}");
    check($q->hasPendingAny(JobQueue::ANSWER_TYPES) === false, 'команда выполнена — не глушит');

    echo "\n== Окно по возрасту ==\n";
    $add('dynamic_command', 'processing', '6 DAY');
    check($q->hasPendingAny(JobQueue::ANSWER_TYPES) === false, 'зависшая 6 дней назад — не глушит');

    echo "\n== Не реакции ==\n";
    $add('cron_spontaneous', 'processing', '5 SECOND');
    $add('memory_scribe', 'processing', '5 SECOND');
    check($q->hasPendingAny(JobQueue::ANSWER_TYPES) === false, 'спонтанка и автопись — не глушат');
    check($q->hasPendingAny([]) === false, 'пустой список типов — false');
} finally {
    if ($ids) {
        $conn->query('DELETE FROM llm_jobs WHERE id IN (' . implode(',', $ids) . ')');
    }
}

it_done();
