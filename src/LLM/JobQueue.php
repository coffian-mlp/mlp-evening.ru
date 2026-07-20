<?php

namespace LLM;

use Infra\Database;


/**
 * Очередь задач бота (таблица llm_jobs). Тонкая обёртка над mysqli.
 *
 * Атомарность «одного голоса» обеспечивается на уровне воркера (GET_LOCK на тик),
 * поэтому claimDue() читает и помечает задачи без claim-токена.
 */
class JobQueue {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Поставить задачу с отложенным запуском (lifelike-задержка).
     * @return int id задачи
     */
    public function enqueue(string $type, array $payload, int $delaySeconds): int {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $stmt = $this->db->prepare(
            "INSERT INTO llm_jobs (type, payload, run_after, status, created_at)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), 'pending', NOW())"
        );
        $stmt->bind_param('ssi', $type, $json, $delaySeconds);
        $stmt->execute();
        return (int)$this->db->insert_id;
    }

    /**
     * Забрать созревшие ИНДИВИДУАЛЬНЫЕ задачи (greeting, dynamic_command) и пометить processing.
     * Упоминания обрабатываются пачкой отдельно — см. claimMentionBurst().
     * Вызывать под воркер-локом.
     * @return array<int,array> задачи с декодированным payload в ключе 'data'
     */
    public function claimDue(int $limit = 50): array {
        $limit = max(1, (int)$limit);
        $res = $this->db->query(
            "SELECT * FROM llm_jobs
             WHERE status='pending' AND run_after <= NOW() AND type IN ('greeting','dynamic_command')
             ORDER BY id ASC LIMIT $limit"
        );
        return $this->collectAndMark($res);
    }

    /**
     * Дебаунс упоминаний: когда созрело самое раннее упоминание, забираем ВСЕ упоминания,
     * созданные в пределах $windowSeconds от него (даже если их run_after ещё не наступил),
     * чтобы ответить на пачку одним сообщением. Вызывать под воркер-локом.
     */
    public function claimMentionBurst(int $windowSeconds = 10): array {
        $w = max(0, (int)$windowSeconds);
        $res = $this->db->query(
            "SELECT created_at FROM llm_jobs
             WHERE status='pending' AND type='mention' AND run_after <= NOW()
             ORDER BY id ASC LIMIT 1"
        );
        if (!$res || $res->num_rows === 0) {
            return [];
        }
        $oldest = $res->fetch_assoc()['created_at'];

        $stmt = $this->db->prepare(
            "SELECT * FROM llm_jobs
             WHERE status='pending' AND type='mention'
               AND created_at BETWEEN ? AND DATE_ADD(?, INTERVAL ? SECOND)
             ORDER BY id ASC"
        );
        $stmt->bind_param('ssi', $oldest, $oldest, $w);
        $stmt->execute();
        return $this->collectAndMark($stmt->get_result());
    }

    private function collectAndMark($res): array {
        $jobs = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $row['data'] = json_decode($row['payload'] ?? '{}', true) ?: [];
                $jobs[] = $row;
            }
        }
        if ($jobs) {
            $ids = implode(',', array_map(static fn($j) => (int)$j['id'], $jobs));
            $this->db->query("UPDATE llm_jobs SET status='processing', claimed_at=NOW() WHERE id IN ($ids)");
        }
        return $jobs;
    }

    /**
     * Была ли недавняя задача типа $type для пользователя $username (MLP-254).
     * Смотрит payload.username по всем статусам (pending считается: поздороваться
     * уже решили). Для троттла приветствий — LLM-запрос не должен даже создаваться.
     */
    public function hasRecentByUsername(string $type, string $username, int $seconds): bool {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM llm_jobs
             WHERE type = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
               AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.username')) = ?
             LIMIT 1"
        );
        $stmt->bind_param('sis', $type, $seconds, $username);
        $stmt->execute();
        return $stmt->get_result()->num_rows > 0;
    }

    /**
     * Журнальная запись задачи, обработанной inline (MLP-254): статус сразу done.
     * Даёт троттлу единое состояние независимо от пути (очередь/inline)
     * и приближает «единый путь через llm_jobs» (TODO: проактив).
     */
    public function logDone(string $type, array $payload): void {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $stmt = $this->db->prepare(
            "INSERT INTO llm_jobs (type, payload, run_after, status, created_at, claimed_at)
             VALUES (?, ?, NOW(), 'done', NOW(), NOW())"
        );
        $stmt->bind_param('ss', $type, $json);
        $stmt->execute();
    }

    /** Есть ли созревшие задачи (для heartbeat/inline-решений). */
    public function hasDue(): bool {
        $res = $this->db->query("SELECT 1 FROM llm_jobs WHERE status='pending' AND run_after <= NOW() LIMIT 1");
        return $res && $res->num_rows > 0;
    }

    public function complete(array $ids): void {
        $this->markStatus($ids, 'done');
    }

    public function fail(array $ids): void {
        if (!$ids) return;
        $list = implode(',', array_map('intval', $ids));
        $this->db->query("UPDATE llm_jobs SET status='failed', attempts=attempts+1 WHERE id IN ($list)");
    }

    private function markStatus(array $ids, string $status): void {
        if (!$ids) return;
        $list = implode(',', array_map('intval', $ids));
        $safe = $this->db->real_escape_string($status);
        $this->db->query("UPDATE llm_jobs SET status='$safe' WHERE id IN ($list)");
    }

    /** Очистка старых завершённых задач (вызывать периодически из воркера). */
    public function purgeOld(int $olderThanHours = 24): void {
        $h = max(1, (int)$olderThanHours);
        $this->db->query(
            "DELETE FROM llm_jobs WHERE status IN ('done','failed') AND created_at < DATE_SUB(NOW(), INTERVAL $h HOUR)"
        );
    }
}
