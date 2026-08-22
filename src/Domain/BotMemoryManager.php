<?php

namespace Domain;

use Infra\Database;
use Infra\ConfigManager;

/**
 * Долгая память Лиры (MLP-314) — владелец таблицы bot_memory.
 * Записи двух видов: dossier (факт о пользователе, ключ user_id) и meme (запись
 * «сундука мемов»). Происхождение: manual (команда/дашборд) и auto (автопись);
 * автоматика (сжатие) трогает только auto-записи, правка переводит запись в manual.
 * Потребители: LLM\LyraMemory (блок в промпт, команды чата), LLM\MemoryScribe
 * (автопись, итерация 2), Api\MemoryController (дашборд, роль admin).
 * Контракт: dev_knowledge/contracts/memory.contract.md.
 */
class BotMemoryManager {

    public const KINDS = ['dossier', 'meme'];
    public const SOURCES = ['manual', 'auto'];
    public const MAX_TEXT = 500;

    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Нормализация текста записи (анти-инъекция в промпт, единая кодировка).
     * Порядок существенен: decode до замены скобок — закрывает обход через &#91;.
     * Квадратные скобки заменяются ГЛОБАЛЬНО: ломают подделку служебных врезок
     * ([Системное правило]) и реплик ([12:34] Ник:) в любой позиции текста.
     */
    public static function normalizeText(string $text): string {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = strtr($text, ['[' => '(', ']' => ')']);
        $text = trim($text);
        return mb_substr($text, 0, self::MAX_TEXT);
    }

    /** Была ли строка укорочена нормализацией (для пометки в подтверждении команды). */
    public static function wasTruncated(string $original): bool {
        $decoded = trim(preg_replace('/\s+/u', ' ', html_entity_decode($original, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        return mb_strlen($decoded) > self::MAX_TEXT;
    }

    /** Новая запись. Возвращает id или false (пустой текст после нормализации / кривые аргументы / сбой). */
    public function add(string $kind, ?int $userId, string $text, string $source = 'manual', ?int $createdBy = null) {
        if (!in_array($kind, self::KINDS, true) || !in_array($source, self::SOURCES, true)) {
            return false;
        }
        if ($kind === 'dossier' && (int)$userId <= 0) {
            return false;
        }
        $text = self::normalizeText($text);
        if ($text === '') {
            return false;
        }
        $uid = $kind === 'dossier' ? (int)$userId : null;
        $stmt = $this->db->prepare("INSERT INTO bot_memory (kind, user_id, text, source, created_by) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param('sissi', $kind, $uid, $text, $source, $createdBy);
        return $stmt->execute() ? (int)$stmt->insert_id : false;
    }

    /** Страница записей для дашборда, свежие сверху; $kind = null — все. */
    public function getPage(int $limit = 50, int $offset = 0, ?string $kind = null): array {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);

        $where = '';
        if ($kind !== null && in_array($kind, self::KINDS, true)) {
            $where = "WHERE kind = ?";
        } else {
            $kind = null;
        }

        $stmt = $this->db->prepare("SELECT COUNT(*) c FROM bot_memory $where");
        if ($where) $stmt->bind_param('s', $kind);
        $stmt->execute();
        $total = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);

        $stmt = $this->db->prepare("SELECT * FROM bot_memory $where ORDER BY id DESC LIMIT ? OFFSET ?");
        if ($where) {
            $stmt->bind_param('sii', $kind, $limit, $offset);
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

    /** Досье пользователя (для команды /память). */
    public function getByUser(int $userId): array {
        $stmt = $this->db->prepare("SELECT * FROM bot_memory WHERE kind = 'dossier' AND user_id = ? ORDER BY id ASC");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }

    /** Досье участников окна, сгруппированные по user_id (порядок записей внутри — по id). */
    public function getDossiers(array $userIds): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), fn($v) => $v > 0)));
        if (!$ids) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("SELECT * FROM bot_memory WHERE kind = 'dossier' AND user_id IN ($placeholders) ORDER BY id ASC");
        $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        $stmt->execute();
        $res = $stmt->get_result();
        $map = [];
        while ($row = $res->fetch_assoc()) {
            $map[(int)$row['user_id']][] = $row;
        }
        return $map;
    }

    /**
     * Мемы: manual — приоритетно, затем auto по свежести. $limit — для горячего пути
     * (блок памяти съедает <= meme_limit символов, вся таблица не нужна); сжатие
     * (compressIfNeeded) зовёт БЕЗ лимита — обязано видеть все auto-записи.
     */
    public function getMemes(?int $limit = null): array {
        $sql = "SELECT * FROM bot_memory WHERE kind = 'meme' ORDER BY (source = 'manual') DESC, id DESC";
        if ($limit !== null) {
            $sql .= " LIMIT " . max(1, (int)$limit);
        }
        $res = $this->db->query($sql);
        $rows = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /** Правка текста (дашборд): нормализация + перевод в manual — защита от автосжатия. */
    public function updateText(int $id, string $text): bool {
        $text = self::normalizeText($text);
        if ($text === '') {
            return false;
        }
        $stmt = $this->db->prepare("UPDATE bot_memory SET text = ?, source = 'manual' WHERE id = ?");
        $stmt->bind_param('si', $text, $id);
        return $stmt->execute() && $stmt->affected_rows > 0;
    }

    /** Удаление. Возвращает удалённую строку (для аудита) или null. */
    public function delete(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM bot_memory WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) {
            return null;
        }
        $stmt = $this->db->prepare("DELETE FROM bot_memory WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        return $stmt->affected_rows > 0 ? $row : null;
    }

    /** Суммарная длина auto-части досье пользователя (порог сжатия — ai_memory_user_limit). */
    public function autoDossierLength(int $userId): int {
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(CHAR_LENGTH(text)), 0) l FROM bot_memory WHERE kind = 'dossier' AND user_id = ? AND source = 'auto'");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        return (int)($stmt->get_result()->fetch_assoc()['l'] ?? 0);
    }

    /** Сжатие: заменить все auto-записи досье пользователя одной компактной (транзакция). Manual не трогается. */
    public function replaceAutoDossier(int $userId, string $compactText): bool {
        $compactText = self::normalizeText($compactText);
        if ($compactText === '' || $userId <= 0) {
            return false;
        }
        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare("DELETE FROM bot_memory WHERE kind = 'dossier' AND user_id = ? AND source = 'auto'");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt = $this->db->prepare("INSERT INTO bot_memory (kind, user_id, text, source) VALUES ('dossier', ?, ?, 'auto')");
            $stmt->bind_param('is', $userId, $compactText);
            $stmt->execute();
            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->db->rollback();
            error_log("BotMemoryManager::replaceAutoDossier failed: " . $e->getMessage());
            return false;
        }
    }

    /** Суммарная длина auto-мемов (порог сжатия — 2 × ai_memory_meme_limit). */
    public function autoMemesLength(): int {
        $res = $this->db->query("SELECT COALESCE(SUM(CHAR_LENGTH(text)), 0) l FROM bot_memory WHERE kind = 'meme' AND source = 'auto'");
        return (int)($res->fetch_assoc()['l'] ?? 0);
    }

    /** Сжатие сундука: заменить auto-мемы компактным списком (транзакция). Manual не трогается. */
    public function replaceAutoMemes(array $compactTexts): bool {
        $texts = [];
        foreach ($compactTexts as $t) {
            $t = self::normalizeText((string)$t);
            if ($t !== '') {
                $texts[] = $t;
            }
        }
        if (!$texts) {
            return false;
        }
        $this->db->begin_transaction();
        try {
            $this->db->query("DELETE FROM bot_memory WHERE kind = 'meme' AND source = 'auto'");
            $stmt = $this->db->prepare("INSERT INTO bot_memory (kind, user_id, text, source) VALUES ('meme', NULL, ?, 'auto')");
            foreach ($texts as $t) {
                $stmt->bind_param('s', $t);
                $stmt->execute();
            }
            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->db->rollback();
            error_log("BotMemoryManager::replaceAutoMemes failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Право учить Лиру (/запомни, /забудь). Единственная точка правила (образец
     * PollManager::canCreate, AR7-1). Включает выключатель подсистемы: при
     * ai_memory_enabled=0 команды не работают (AC-8). Fail-closed: мусорное
     * значение опции трактуется как 'moderator'; значение 'all' не допускается.
     */
    public static function canTeach(): bool {
        if (!Auth::check()) {
            return false;
        }
        $config = ConfigManager::getInstance();
        if (!(int)$config->getOption('ai_memory_enabled', 1)) {
            return false;
        }
        $role = $config->getOption('ai_memory_teach_role', 'moderator');
        return $role === 'admin' ? Auth::isAdmin() : Auth::isModerator();
    }

    /**
     * Право смотреть память о себе (/память). Fail-closed: мусорное значение → admin.
     * Включает выключатель подсистемы (AC-8).
     */
    public static function canViewOwn(): bool {
        if (!Auth::check()) {
            return false;
        }
        $config = ConfigManager::getInstance();
        if (!(int)$config->getOption('ai_memory_enabled', 1)) {
            return false;
        }
        $role = $config->getOption('ai_memory_view_role', 'all');
        if ($role === 'all') {
            return true;
        }
        if ($role === 'moderator') {
            return Auth::isModerator();
        }
        return Auth::isAdmin();
    }
}
