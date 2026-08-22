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
     * Типы, которые claimDue забирает поштучно (MLP-314: единый источник истины).
     * mention сюда НЕ входит — он клеймится дебаунс-пачкой claimMentionBurst().
     * Новый клеймящийся тип добавляется сюда и автоматически учитывается
     * гейтом hasReactiveDue() (см. REACTIVE_TYPES).
     */
    public const CLAIM_TYPES = ['greeting', 'dynamic_command', 'cron_spontaneous', 'stream_command'];

    /**
     * «Реактивный бэклог» для гейта фоновой автописи (MLP-314): CLAIM_TYPES + mention.
     * Позитивный список намеренно: негативный (type <> 'memory_scribe') блокировался бы
     * навсегда осиротевшим pending неклеймящегося типа (прецедент — machine_spirit
     * после MLP-307; purgeOld чистит только done/failed).
     */
    public const REACTIVE_TYPES = ['mention', 'greeting', 'dynamic_command', 'cron_spontaneous', 'stream_command'];

    /**
     * Забрать созревшие ИНДИВИДУАЛЬНЫЕ задачи (greeting, dynamic_command) и пометить processing.
     * Упоминания обрабатываются пачкой отдельно — см. claimMentionBurst().
     * Вызывать под воркер-локом.
     * @return array<int,array> задачи с декодированным payload в ключе 'data'
     */
    public function claimDue(int $limit = 50): array {
        $limit = max(1, (int)$limit);
        $typesIn = "'" . implode("','", self::CLAIM_TYPES) . "'";
        $stmt = $this->db->prepare(
            // MLP-311: команды и события стрима — вперёд очереди. Их ответ привязан
            // к тому, что зрители видят прямо сейчас, и устаревает за секунды,
            // в отличие от приветствий и спонтанных реплик.
            "SELECT * FROM llm_jobs
             WHERE status='pending' AND run_after <= NOW() AND type IN ($typesIn)
             ORDER BY (type = 'stream_command') DESC, id ASC LIMIT ?"
        );
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        return $this->collectAndMark($stmt->get_result());
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
     * Была ли недавняя задача типа $type для пользователя с payload.user_id (MLP-294).
     * Как hasRecentByUsername, но по id: greeting несёт логин, mention — ник,
     * сверять их между собой по имени нельзя.
     */
    public function hasRecentByUserId(string $type, int $userId, int $seconds): bool {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM llm_jobs
             WHERE type = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
               AND JSON_EXTRACT(payload, '$.user_id') = ?
             LIMIT 1"
        );
        $stmt->bind_param('sii', $type, $seconds, $userId);
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

    /**
     * Метрики за период (MLP-280): счётчики по типам/статусам и по дням.
     * llm_jobs хранится 7 дней (purgeOld в воркере) — окно метрик такое же.
     */
    public function stats(int $hours = 168): array {
        // MLP-289 (AR7-L5): интервалы — через bind, единый prepared-стиль класса.
        $h = max(1, min(720, $hours));
        $byType = [];
        $stmt = $this->db->prepare(
            "SELECT type, status, COUNT(*) c FROM llm_jobs
             WHERE created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
             GROUP BY type, status"
        );
        $stmt->bind_param('i', $h);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && $row = $res->fetch_assoc()) {
            $byType[$row['type']][$row['status']] = (int)$row['c'];
        }
        $byDay = [];
        $stmt = $this->db->prepare(
            "SELECT DATE(created_at) d, COUNT(*) c FROM llm_jobs
             WHERE created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
             GROUP BY DATE(created_at) ORDER BY d"
        );
        $stmt->bind_param('i', $h);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && $row = $res->fetch_assoc()) {
            $byDay[$row['d']] = (int)$row['c'];
        }
        return ['by_type' => $byType, 'by_day' => $byDay];
    }

    /** Очистка старых завершённых задач (вызывать периодически из воркера). */
    public function purgeOld(int $olderThanHours = 24): void {
        $h = max(1, (int)$olderThanHours);
        $stmt = $this->db->prepare(
            "DELETE FROM llm_jobs WHERE status IN ('done','failed') AND created_at < DATE_SUB(NOW(), INTERVAL ? HOUR)"
        );
        $stmt->bind_param('i', $h);
        $stmt->execute();
    }

    /**
     * Забрать pending-задачи автописи (MLP-314). Отдельно от claimDue намеренно:
     * scribe низкоприоритетен и не должен конкурировать с ответами бота
     * (сортировка claimDue по id ставила бы его впереди свежих реплик).
     * Вызывать под воркер-локом, после reactive-шагов.
     */
    public function claimScribe(int $limit = 1): array {
        $limit = max(1, (int)$limit);
        $stmt = $this->db->prepare(
            "SELECT * FROM llm_jobs WHERE status='pending' AND type='memory_scribe' ORDER BY id ASC LIMIT ?"
        );
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        return $this->collectAndMark($stmt->get_result());
    }

    /**
     * Есть ли невыполненная задача типа (pending ИЛИ processing) — гейт планировщика
     * автописи от дублей. Processing учитывается: job, чей процесс умер, не должен
     * порождать параллельные дубли до реапера failStale().
     */
    public function hasPending(string $type): bool {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM llm_jobs WHERE status IN ('pending','processing') AND type = ? LIMIT 1"
        );
        $stmt->bind_param('s', $type);
        $stmt->execute();
        return $stmt->get_result()->num_rows > 0;
    }

    /**
     * Реапер зависших processing (процесс умер во время выполнения): старше порога —
     * failed с attempts+1. Возвращает число реанимированных.
     */
    public function failStale(string $type, int $olderSec = 1800): int {
        $sec = max(60, (int)$olderSec);
        $stmt = $this->db->prepare(
            "UPDATE llm_jobs SET status='failed', attempts = attempts + 1
             WHERE status='processing' AND type = ? AND claimed_at < DATE_SUB(NOW(), INTERVAL ? SECOND)"
        );
        $stmt->bind_param('si', $type, $sec);
        $stmt->execute();
        return $stmt->affected_rows;
    }

    /**
     * Ждёт ли реактивная работа (гейт запуска scribe, MLP-314). Намеренно БЕЗ
     * run_after <= NOW(): mention с lifelike-задержкой 4–42с — тоже «ждущий»
     * (прецедент трактовки — claimMentionBurst забирает несозревшие).
     */
    public function hasReactiveDue(): bool {
        $typesIn = "'" . implode("','", self::REACTIVE_TYPES) . "'";
        $res = $this->db->query("SELECT 1 FROM llm_jobs WHERE status='pending' AND type IN ($typesIn) LIMIT 1");
        return $res && $res->num_rows > 0;
    }
}
