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

    /** Legacy ID contract; oversized input retains its historical truncation behaviour. */
    public function add(?int $userId, string $username, ?int $messageId, string $text) {
        $text = trim($text);
        if ($text === '') return false;
        if (mb_strlen($text) > self::MAX_TEXT) {
            $text = mb_substr($text, 0, self::MAX_TEXT);
            return $this->writeLocked(fn() => $this->insert($userId, $username, $messageId, $text));
        }
        $result = $this->addOrFind($userId, $username, $messageId, $text);
        return $result === false ? false : $result['id'];
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
        return $this->writeLocked(function () use ($id, $status) {
            $stmt = $this->db->prepare("UPDATE feedback_backlog SET status = ? WHERE id = ?");
            $stmt->bind_param('si', $status, $id);
            return $stmt->execute() && $stmt->affected_rows > 0;
        });
    }

    public function countNew(): int {
        $res = $this->db->query("SELECT COUNT(*) c FROM feedback_backlog WHERE status = 'new'");
        return (int)($res->fetch_assoc()['c'] ?? 0);
    }
    /** New callers receive an immutable existing ID or a newly created record. */
    public function addOrFind(?int $userId, string $username, ?int $messageId, string $text): array|false {
        $text = trim($text);
        if (mb_strlen($text) > self::MAX_TEXT) return false;
        $canonical = self::canonicalText($text);
        if ($canonical === '') return false;
        return $this->writeLocked(function () use ($userId, $username, $messageId, $text, $canonical) {
            $rows = $this->db->query("SELECT id,text FROM feedback_backlog WHERE status='new' ORDER BY id FOR UPDATE");
            while ($row = $rows->fetch_assoc()) {
                if (self::canonicalText($row['text']) === $canonical) return ['id' => (int)$row['id'], 'created' => false];
            }
            $id = $this->insert($userId, $username, $messageId, $text);
            return $id === false ? false : ['id' => $id, 'created' => true];
        });
    }

    private static function canonicalText(string $text): string {
        return mb_strtolower(trim(preg_replace('/[\p{Z}\s]+/u', ' ', $text) ?? $text), 'UTF-8');
    }

    private function insert(?int $userId, string $username, ?int $messageId, string $text): int|false {
        $stmt = $this->db->prepare("INSERT INTO feedback_backlog (user_id, username, message_id, text) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('isis', $userId, $username, $messageId, $text);
        return $stmt->execute() ? (int)$stmt->insert_id : false;
    }

    private function writeLocked(callable $write): mixed {
        $this->requireOwnTransaction();
        $lock = $this->db->query("SELECT GET_LOCK('feedback_backlog_write',5) acquired")->fetch_assoc();
        if ((int)$lock['acquired'] !== 1) throw new \Core\UserError('Беклог занят. Попробуй ещё раз позже.');
        try {
            $this->db->begin_transaction();
            try {
                $result = $write();
                $this->db->commit();
                return $result;
            } catch (\Throwable $error) {
                $this->db->rollback();
                throw $error;
            }
        } finally {
            $this->db->query("SELECT RELEASE_LOCK('feedback_backlog_write')");
        }
    }

    private function requireOwnTransaction(): void {
        if (\Infra\Transaction::depth($this->db) > 0) throw new \Core\UserError('feedback_nested_transaction');
        $this->db->query('SAVEPOINT feedback_transaction_probe');
        try { $this->db->query('RELEASE SAVEPOINT feedback_transaction_probe'); }
        catch (\mysqli_sql_exception $error) {
            if ($error->getCode() === 1305) return;
            throw $error;
        }
        throw new \Core\UserError('feedback_nested_transaction');
    }

}
