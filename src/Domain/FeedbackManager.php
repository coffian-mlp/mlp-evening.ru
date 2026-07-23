<?php

namespace Domain;

use Infra\Database;

/**
 * Беклог фидбека из чата (MLP-270) — владелец таблицы feedback_backlog.
 * Записи создаёт команда бота /todo (LLM\LLMManager, handler_type='todo'),
 * читает и меняет статусы — дашборд (Api\FeedbackController, роль admin).
 */
class FeedbackManager {

    public const STATUSES = ['new', 'done', 'dismissed'];
    public const MAX_TEXT = 1000;

    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /** Новая запись. Текст обрезается до MAX_TEXT. false — пустой текст/сбой. */
    public function add(?int $userId, string $username, ?int $messageId, string $text) {
        $text = trim($text);
        if ($text === '') {
            return false;
        }
        if (mb_strlen($text) > self::MAX_TEXT) {
            $text = mb_substr($text, 0, self::MAX_TEXT);
        }
        $stmt = $this->db->prepare("INSERT INTO feedback_backlog (user_id, username, message_id, text) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('isis', $userId, $username, $messageId, $text);
        return $stmt->execute() ? (int)$stmt->insert_id : false;
    }

    /** Страница записей, свежие сверху; $status = null — все. */
    public function getPage(int $limit = 50, int $offset = 0, ?string $status = null): array {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);

        $where = '';
        if ($status !== null && in_array($status, self::STATUSES, true)) {
            $where = "WHERE status = ?";
        }

        $stmt = $this->db->prepare("SELECT COUNT(*) c FROM feedback_backlog $where");
        if ($where) $stmt->bind_param('s', $status);
        $stmt->execute();
        $total = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);

        $stmt = $this->db->prepare("SELECT * FROM feedback_backlog $where ORDER BY id DESC LIMIT ? OFFSET ?");
        if ($where) {
            $stmt->bind_param('sii', $status, $limit, $offset);
        } else {
            $stmt->bind_param('ii', $limit, $offset);
        }
        $stmt->execute();
        $res = $stmt->get_result();

        $items = [];
        while ($row = $res->fetch_assoc()) {
            $items[] = $row;
        }
        return ['items' => $items, 'total' => $total];
    }

    public function setStatus(int $id, string $status): bool {
        if (!in_array($status, self::STATUSES, true)) {
            return false;
        }
        $stmt = $this->db->prepare("UPDATE feedback_backlog SET status = ? WHERE id = ?");
        $stmt->bind_param('si', $status, $id);
        return $stmt->execute() && $stmt->affected_rows > 0;
    }

    public function countNew(): int {
        $res = $this->db->query("SELECT COUNT(*) c FROM feedback_backlog WHERE status = 'new'");
        return (int)($res->fetch_assoc()['c'] ?? 0);
    }
}
