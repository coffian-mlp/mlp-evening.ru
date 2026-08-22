<?php

namespace Domain;

use Infra\Database;

/**
 * Напоминалки (MLP-318) — владелец таблицы reminders.
 * Создание/отмена — через LLM\ReminderCommand (парсер свободной формы),
 * доставка — BotWorker::deliverReminders (тик, точность ±poll-интервал воркера).
 * Время хранится в UTC; пользовательский ввод (МСК) конвертирует хендлер.
 */
class ReminderManager {

    public const MAX_TEXT = 300;
    public const MAX_ACTIVE_PER_USER = 5;
    public const MIN_HORIZON_SEC = 60;
    public const MAX_HORIZON_SEC = 604800; // 7 дней

    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /** Новое напоминание. false — пустой текст/кривой юзер/сбой; лимит проверяет вызывающий. */
    public function add(int $userId, string $username, string $text, string $remindAtUtc) {
        $text = mb_substr(trim(preg_replace('/\s+/u', ' ', $text)), 0, self::MAX_TEXT);
        if ($text === '' || $userId <= 0) {
            return false;
        }
        $stmt = $this->db->prepare("INSERT INTO reminders (user_id, username, text, remind_at) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('isss', $userId, $username, $text, $remindAtUtc);
        return $stmt->execute() ? (int)$stmt->insert_id : false;
    }

    public function countActive(int $userId): int {
        $stmt = $this->db->prepare("SELECT COUNT(*) c FROM reminders WHERE user_id = ? AND status = 'pending'");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        return (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    }

    /** Активные напоминания пользователя (для «покажи напоминалки»). */
    public function listActive(int $userId): array {
        $stmt = $this->db->prepare("SELECT * FROM reminders WHERE user_id = ? AND status = 'pending' ORDER BY remind_at ASC");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }

    /** Отмена СВОЕГО напоминания (чужое — false: скоуп по user_id в самом запросе). */
    public function cancel(int $id, int $userId): bool {
        $stmt = $this->db->prepare("UPDATE reminders SET status = 'cancelled' WHERE id = ? AND user_id = ? AND status = 'pending'");
        $stmt->bind_param('ii', $id, $userId);
        return $stmt->execute() && $stmt->affected_rows > 0;
    }

    /**
     * Забрать созревшие и пометить done (вызывать под воркер-локом: GET_LOCK
     * сериализует тики — второй консьюмер не заберёт те же строки).
     */
    public function claimDue(int $limit = 5): array {
        $limit = max(1, min(20, $limit));
        $stmt = $this->db->prepare("SELECT * FROM reminders WHERE status = 'pending' AND remind_at <= UTC_TIMESTAMP() ORDER BY remind_at ASC LIMIT ?");
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        if ($rows) {
            $ids = implode(',', array_map(static fn($r) => (int)$r['id'], $rows));
            $this->db->query("UPDATE reminders SET status = 'done' WHERE id IN ($ids)");
        }
        return $rows;
    }
}
