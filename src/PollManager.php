<?php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/CentrifugoService.php';

/**
 * Владелец таблиц опросов (MLP-237): polls / poll_options / poll_votes.
 * Единая точка SQL по опросам — API-роутер, UI и бот ходят сюда.
 * Схема — migrations/2026_07_polls.sql.
 */
class PollManager {
    private $db;

    const MIN_OPTIONS = 2;
    const MAX_OPTIONS = 10;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    // ---------------- Создание ----------------

    /**
     * Создать опрос. Возвращает id или 0 при ошибке валидации.
     * $options — список строк-вариантов (2..10, пустые отбрасываются).
     */
    public function create(int $userId, string $question, array $options, bool $isMulti = false, bool $isAnonymous = false, ?string $closesAt = null): int {
        $question = trim($question);
        // Варианты: строка (только текст) ИЛИ ['text'=>, 'image_url'=>]. Валиден, если есть текст или картинка.
        $clean = [];
        foreach ($options as $o) {
            if (is_array($o)) {
                $text = trim((string)($o['text'] ?? ''));
                $img  = trim((string)($o['image_url'] ?? ''));
            } else {
                $text = trim((string)$o);
                $img  = '';
            }
            if ($text !== '' || $img !== '') {
                $clean[] = ['text' => $text, 'image_url' => $img !== '' ? $img : null];
            }
        }
        if ($question === '' || count($clean) < self::MIN_OPTIONS) return 0;
        $clean = array_slice($clean, 0, self::MAX_OPTIONS);

        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare("INSERT INTO polls (question, created_by, created_at, is_multi, is_anonymous, closes_at) VALUES (?, ?, NOW(), ?, ?, ?)");
            $im = $isMulti ? 1 : 0; $ia = $isAnonymous ? 1 : 0;
            $stmt->bind_param("siiis", $question, $userId, $im, $ia, $closesAt);
            $stmt->execute();
            $pollId = (int)$this->db->insert_id;

            $optStmt = $this->db->prepare("INSERT INTO poll_options (poll_id, text, image_url, position) VALUES (?, ?, ?, ?)");
            foreach ($clean as $pos => $opt) {
                $optStmt->bind_param("issi", $pollId, $opt['text'], $opt['image_url'], $pos);
                $optStmt->execute();
            }
            $this->db->commit();
            return $pollId;
        } catch (\Throwable $e) {
            $this->db->rollback();
            error_log('PollManager::create ' . $e->getMessage());
            return 0;
        }
    }

    /** Привязать опрос к сообщению-карточке чата (MLP-239). */
    public function attachMessage(int $pollId, int $messageId): void {
        $stmt = $this->db->prepare("UPDATE polls SET message_id = ? WHERE id = ?");
        $stmt->bind_param("ii", $messageId, $pollId);
        $stmt->execute();
    }

    // ---------------- Чтение ----------------

    public function getPoll(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM polls WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $poll = $stmt->get_result()->fetch_assoc();
        if (!$poll) return null;
        $poll['options'] = $this->options($id);
        return $poll;
    }

    private function options(int $pollId): array {
        $rows = [];
        $stmt = $this->db->prepare("SELECT id, text, image_url, position FROM poll_options WHERE poll_id = ? ORDER BY position ASC");
        $stmt->bind_param("i", $pollId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        return $rows;
    }

    private function voteRows(int $pollId): array {
        $rows = [];
        $stmt = $this->db->prepare("SELECT option_id, user_id FROM poll_votes WHERE poll_id = ?");
        $stmt->bind_param("i", $pollId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        return $rows;
    }

    /** Результаты: счётчики и проценты (от числа проголосовавших). */
    public function getResults(int $pollId): array {
        return self::computeResults($this->options($pollId), $this->voteRows($pollId));
    }

    /**
     * Pure: посчитать результаты. percent — доля от числа уникальных
     * проголосовавших (для multi суммарно может быть > 100%).
     */
    public static function computeResults(array $options, array $voteRows): array {
        $countByOption = [];
        $voters = [];
        foreach ($voteRows as $v) {
            $oid = (int)$v['option_id'];
            $countByOption[$oid] = ($countByOption[$oid] ?? 0) + 1;
            $voters[(int)$v['user_id']] = true;
        }
        $totalVoters = count($voters);
        $out = [];
        foreach ($options as $opt) {
            $oid = (int)$opt['id'];
            $c = $countByOption[$oid] ?? 0;
            $out[] = [
                'id' => $oid,
                'text' => $opt['text'],
                'image_url' => $opt['image_url'] ?? null,
                'votes' => $c,
                'percent' => $totalVoters > 0 ? (int)round($c * 100 / $totalVoters) : 0,
            ];
        }
        return ['total_voters' => $totalVoters, 'options' => $out];
    }

    /** Варианты, за которые проголосовал пользователь (для подсветки в UI). */
    public function getUserVotes(int $pollId, int $userId): array {
        $ids = [];
        $stmt = $this->db->prepare("SELECT option_id FROM poll_votes WHERE poll_id = ? AND user_id = ?");
        $stmt->bind_param("ii", $pollId, $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $ids[] = (int)$r['option_id'];
        return $ids;
    }

    /**
     * Голосующие по вариантам (для тултипа не-анонимных опросов) — паттерн реакций.
     * Возвращает option_id => "ник|цвет|аватар;;ник|цвет|аватар".
     */
    public function getVotersByOption(int $pollId): array {
        $out = [];
        $stmt = $this->db->prepare(
            "SELECT pv.option_id,
                    GROUP_CONCAT(
                        CONCAT_WS('|',
                            COALESCE(NULLIF(u.nickname, ''), u.login),
                            COALESCE(uo_color.option_value, ''),
                            COALESCE(uo_avatar.option_value, '')
                        ) SEPARATOR ';;'
                    ) AS users_data
             FROM poll_votes pv
             LEFT JOIN users u ON pv.user_id = u.id
             LEFT JOIN user_options uo_color ON u.id = uo_color.user_id AND uo_color.option_key = 'chat_color'
             LEFT JOIN user_options uo_avatar ON u.id = uo_avatar.user_id AND uo_avatar.option_key = 'avatar_url'
             WHERE pv.poll_id = ?
             GROUP BY pv.option_id");
        $stmt->bind_param("i", $pollId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $out[(int)$r['option_id']] = $r['users_data'];
        }
        return $out;
    }

    public function hasVoted(int $pollId, int $userId): bool {
        $stmt = $this->db->prepare("SELECT 1 FROM poll_votes WHERE poll_id = ? AND user_id = ? LIMIT 1");
        $stmt->bind_param("ii", $pollId, $userId);
        $stmt->execute();
        return $stmt->get_result()->num_rows > 0;
    }

    public function isOpen(int $pollId): bool {
        $stmt = $this->db->prepare("SELECT status FROM polls WHERE id = ?");
        $stmt->bind_param("i", $pollId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row && $row['status'] === 'open';
    }

    public function listActive(): array {
        $rows = [];
        $res = $this->db->query("SELECT * FROM polls WHERE status = 'open' ORDER BY id DESC");
        while ($res && $r = $res->fetch_assoc()) $rows[] = $r;
        return $rows;
    }

    // ---------------- Голосование ----------------

    /**
     * Заменить голоса пользователя набором $optionIds (пустой набор = снять голос).
     * single (is_multi=0) требует ровно один вариант. Варианты сверяются со схемой опроса.
     * Возвращает true при успехе; false — опрос закрыт/невалидный набор.
     */
    public function vote(int $pollId, int $userId, array $optionIds): bool {
        $poll = $this->getPoll($pollId);
        if (!$poll || $poll['status'] !== 'open') return false;

        $valid = array_map(fn($o) => (int)$o['id'], $poll['options']);
        $ids = array_values(array_unique(array_map('intval', $optionIds)));
        foreach ($ids as $oid) {
            if (!in_array($oid, $valid, true)) return false; // чужой вариант
        }
        if (!$poll['is_multi'] && count($ids) > 1) return false; // single — максимум один

        $this->db->begin_transaction();
        try {
            $del = $this->db->prepare("DELETE FROM poll_votes WHERE poll_id = ? AND user_id = ?");
            $del->bind_param("ii", $pollId, $userId);
            $del->execute();
            if ($ids) {
                $ins = $this->db->prepare("INSERT INTO poll_votes (poll_id, option_id, user_id, created_at) VALUES (?, ?, ?, NOW())");
                foreach ($ids as $oid) {
                    $ins->bind_param("iii", $pollId, $oid, $userId);
                    $ins->execute();
                }
            }
            $this->db->commit();
            // Realtime у ВСЕХ путей голосования (API и бот): раньше broadcast был только
            // в PollController, поэтому голос бота (через воркер) не обновлял карточку до F5.
            $voters = empty($poll['is_anonymous']) ? $this->getVotersByOption($pollId) : null;
            $this->broadcast(['type' => 'poll_vote', 'poll_id' => $pollId, 'results' => $this->getResults($pollId), 'voters' => $voters]);
            return true;
        } catch (\Throwable $e) {
            $this->db->rollback();
            error_log('PollManager::vote ' . $e->getMessage());
            return false;
        }
    }

    public function close(int $pollId): bool {
        $stmt = $this->db->prepare("UPDATE polls SET status = 'closed', closed_at = NOW() WHERE id = ? AND status = 'open'");
        $stmt->bind_param("i", $pollId);
        $ok = $stmt->execute() && $this->db->affected_rows > 0;
        if ($ok) {
            $this->broadcast(['type' => 'poll_closed', 'poll_id' => $pollId, 'results' => $this->getResults($pollId)]);
        }
        return $ok;
    }

    /** Realtime-рассылка события опроса (Centrifugo public:chat), как у реакций/сообщений. */
    private function broadcast(array $data): void {
        try {
            (new CentrifugoService())->publish('public:chat', $data);
        } catch (\Throwable $e) {
            error_log('PollManager::broadcast ' . $e->getMessage());
        }
    }
}
