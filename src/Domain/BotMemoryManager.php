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
    public const SOURCES = ['manual', 'auto', 'oc']; // oc — облик, придуманный Лирой (MLP-335); автосжатие не трогает
    // Storage bound is independent of the smaller prompt inclusion budgets.
    public const MAX_TEXT = 4000;

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
        if (($userId && Privacy::isWithdrawn($userId)) || ($createdBy && Privacy::isWithdrawn($createdBy))) return false;
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
        if ($kind === 'meme' && $source === 'auto') {
            return \Infra\Transaction::run($this->db, function () use ($text, $createdBy) {
                $known = $this->lockedMemeTexts();
                $key = hash('sha256', $text);
                if (isset($known[$key])) return (int)$known[$key]['id'];
                $stmt = $this->db->prepare("INSERT INTO bot_memory (kind,user_id,text,source,created_by) VALUES ('meme',NULL,?,'auto',?)");
                $stmt->bind_param('si', $text, $createdBy);$stmt->execute();
                return (int)$stmt->insert_id;
            });
        }
        $stmt = $this->db->prepare("INSERT INTO bot_memory (kind, user_id, text, source, created_by) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param('sissi', $kind, $uid, $text, $source, $createdBy);
        return $stmt->execute() ? (int)$stmt->insert_id : false;
    }

    /** Страница записей для дашборда, свежие сверху; $kind = null — все. */
    public function getPage(int $limit = 50, int $offset = 0, ?string $kind = null, bool $includeArchived = false): array {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);

        $where = $includeArchived ? 'WHERE 1=1' : 'WHERE is_archived=0';
        $hasKind = $kind !== null && in_array($kind, self::KINDS, true);
        if ($hasKind) {
            $where .= " AND kind = ?";
        } else {
            $kind = null;
        }

        $stmt = $this->db->prepare("SELECT COUNT(*) c FROM bot_memory $where");
        if ($hasKind) $stmt->bind_param('s', $kind);
        $stmt->execute();
        $total = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);

        $stmt = $this->db->prepare("SELECT * FROM bot_memory $where ORDER BY id DESC LIMIT ? OFFSET ?");
        if ($hasKind) {
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
        if (Privacy::isWithdrawn($userId)) return [];
        $stmt = $this->db->prepare("SELECT * FROM bot_memory WHERE kind = 'dossier' AND is_archived=0 AND user_id = ? " . Privacy::sqlFilter('created_by') . " ORDER BY id ASC");
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
        $ids = array_values(array_filter($ids, static fn($id)=>!Privacy::isWithdrawn($id)));
        if (!$ids) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("SELECT * FROM bot_memory WHERE kind = 'dossier' AND is_archived=0 AND user_id IN ($placeholders) " . Privacy::sqlFilter('created_by') . " ORDER BY id ASC");
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
        $sql = "SELECT * FROM bot_memory WHERE kind = 'meme' AND is_archived=0 " . Privacy::sqlFilter('created_by') . " ORDER BY (source = 'manual') DESC, id DESC";
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
        return MemoryEpisodeManager::change('memory', [$id], fn() => $stmt->execute() && $stmt->affected_rows > 0);
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
        return MemoryEpisodeManager::change('memory', [$id], function () use ($stmt, $row) {
            $stmt->execute();
            return $stmt->affected_rows > 0 ? $row : null;
        }, true);
    }

    /** Суммарная длина auto-части досье пользователя (порог сжатия — MAX_TEXT). */
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
            $this->invalidateSelection("kind='dossier' AND user_id=" . (int)$userId . " AND source='auto'");
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
        $res = $this->db->query("SELECT COALESCE(SUM(CHAR_LENGTH(text)), 0) l FROM bot_memory WHERE kind = 'meme' AND source = 'auto' AND is_archived=0");
        return (int)($res->fetch_assoc()['l'] ?? 0);
    }

    /** Сжатие сундука: заменить auto-мемы компактным списком (транзакция). Manual не трогается. */
    public function replaceAutoMemes(array $compactTexts): bool {
        $texts = [];
        foreach ($compactTexts as $t) {
            $t = self::normalizeText((string)$t);
            if ($t !== '') {
                $texts[hash('sha256', $t)] = $t;
            }
        }
        if (!$texts) {
            return false;
        }
        try {
            return \Infra\Transaction::run($this->db, function () use ($texts) {
                $known = $this->lockedMemeTexts();
                // Visibility changes do not change the indexed fact or its source signature.
                $this->db->query("UPDATE bot_memory SET is_archived=1,updated_at=updated_at WHERE kind='meme' AND source='auto' AND is_archived=0");
                foreach ($texts as $key => $text) {
                    if (isset($known[$key])) {
                        $row = $known[$key];
                        if ($row['source'] === 'auto') $this->db->query('UPDATE bot_memory SET is_archived=0,updated_at=updated_at WHERE id='.(int)$row['id']);
                        continue;
                    }
                    $stmt = $this->db->prepare("INSERT INTO bot_memory (kind,user_id,text,source) VALUES ('meme',NULL,?,'auto')");
                    $stmt->bind_param('s', $text);$stmt->execute();
                }
                return true;
            });
        } catch (\Throwable $e) {
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

    /**
     * Единственная запись внешности пользователя (MLP-335): прежние «внешность: …» любого источника
     * удаляются, новая пишется с $source ('oc' — придумала Лира, 'manual' — задал человек через /яос).
     */
    public function setAppearance(int $userId, string $text, string $source = 'oc', ?int $createdBy = null) {
        if(Privacy::isWithdrawn($userId))return false;
        if ($userId <= 0 || !in_array($source, self::SOURCES, true)) {
            return false;
        }
        return \Infra\Transaction::run($this->db, function () use ($userId, $text, $source, $createdBy) {
            $this->invalidateSelection("kind='dossier' AND user_id=" . (int)$userId . " AND LOWER(text) LIKE 'внешность%'");
            $stmt = $this->db->prepare("DELETE FROM bot_memory WHERE kind = 'dossier' AND user_id = ? AND LOWER(text) LIKE 'внешность%'");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            return $this->add('dossier', $userId, $text, $source, $createdBy);
        });
    }

    /**
     * Единственная запись «ОС: …» — лор/характер пони-персонажа владельца (MLP-340). Как setAppearance:
     * прежние записи с этим префиксом удаляются, новая пишется с $source.
     */
    public function setPersona(int $userId, string $text, string $source = 'manual', ?int $createdBy = null) {
        if(Privacy::isWithdrawn($userId))return false;
        if ($userId <= 0 || !in_array($source, self::SOURCES, true)) {
            return false;
        }
        return \Infra\Transaction::run($this->db, function () use ($userId, $text, $source, $createdBy) {
            $this->invalidateSelection("kind='dossier' AND user_id=" . (int)$userId . " AND LOWER(text) LIKE 'ос:%'");
            $stmt = $this->db->prepare("DELETE FROM bot_memory WHERE kind = 'dossier' AND user_id = ? AND LOWER(text) LIKE 'ос:%'");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            return $this->add('dossier', $userId, $text, $source, $createdBy);
        });
    }

    public function eraseUserData(int $userId): void {
        \Infra\Transaction::run($this->db, function () use ($userId) {
            $this->invalidateSelection('user_id=' . $userId . ' OR created_by=' . $userId);
            foreach (['DELETE FROM bot_memory WHERE user_id=? OR created_by=?'] as $sql) {
                $stmt=$this->db->prepare($sql);$values=array_fill(0,substr_count($sql,"?"),$userId);
                $stmt->bind_param(str_repeat("i",count($values)),...$values);$stmt->execute();
            }
        });
    }

    /** Called inside the source transaction before deleting a selected group. */
    private function invalidateSelection(string $where): void {
        $rows = $this->db->query('SELECT id FROM bot_memory WHERE ' . $where . ' ORDER BY id FOR UPDATE')->fetch_all(MYSQLI_ASSOC);
        (new MemoryEpisodeManager($this->db))->invalidate('memory', array_column($rows, 'id'), true);
    }

    /** Explicit source interface includes archives; eligibility is checked before indexing. */
    public function memorySourceRows(?array $ids = null, bool $lock = false): array {
        $where = '';
        if ($ids !== null) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
            if (!$ids) return [];
            $where = ' WHERE id IN (' . implode(',', $ids) . ')';
        }
        return $this->db->query('SELECT * FROM bot_memory' . $where . ' ORDER BY id' . ($lock ? ' FOR UPDATE' : ''))->fetch_all(MYSQLI_ASSOC);
    }

    /** Lock the meme range, including its insertion gap, before automatic deduplication. */
    private function lockedMemeTexts(): array {
        $rows = $this->db->query("SELECT * FROM bot_memory WHERE kind='meme' ORDER BY is_archived ASC,(source='manual') DESC,id ASC FOR UPDATE")->fetch_all(MYSQLI_ASSOC);
        return self::memeTextMap($rows);
    }

    private static function memeTextMap(array $rows): array {
        $map=[];
        foreach($rows as$row){$key=hash('sha256',self::normalizeText($row['text']));if(!isset($map[$key]))$map[$key]=$row;}
        return$map;
    }

    /** Restore historical auto memes as archive-only rows; no external service is called. */
    public function restoreArchivedMemes(array $rows, bool $dryRun = false): array {
        $work=function()use($rows,$dryRun){
            $known=$dryRun?self::memeTextMap($this->db->query("SELECT * FROM bot_memory WHERE kind='meme' ORDER BY is_archived ASC,id ASC")->fetch_all(MYSQLI_ASSOC)):$this->lockedMemeTexts();
            $result=['inserted'=>0,'skipped'=>0,'privacy_skipped'=>0,'would_insert'=>0,'ids'=>[]];
            foreach($rows as$row){
                if(($row['kind']??'')!=='meme'||($row['source']??'')!=='auto'||!empty($row['user_id']))throw new \InvalidArgumentException('Only historical auto memes can be restored');
                $text=self::normalizeText((string)($row['text']??''));if($text==='')throw new \InvalidArgumentException('Empty archive text');
                $creator=isset($row['created_by'])?(int)$row['created_by']:null;
                if($creator!==null){
                    $identities=(new UserManager())->memoryIdentityIds([$creator],!$dryRun);
                    $states=Privacy::memoryStates([$creator],!$dryRun);
                    $withdrawn=array_filter($states,static fn($state)=>in_array($state['state'],['withdrawn','deleted'],true));
                    if(!in_array($creator,$identities,true)||$withdrawn){$result['privacy_skipped']++;continue;}
                }
                $key=hash('sha256',$text);
                if(isset($known[$key])){$result['skipped']++;$result['ids'][(int)$row['id']]=(int)$known[$key]['id'];continue;}
                $dates=[];foreach(['created_at','updated_at']as$field){$date=(string)($row[$field]??'');$parsed=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$date,new \DateTimeZone('UTC'));if(!$parsed||$parsed->format('Y-m-d H:i:s')!==$date)throw new \InvalidArgumentException('Invalid historical timestamp');$dates[]=$date;}
                $id=0;
                if($dryRun)$result['would_insert']++;
                else{
                    $stmt=$this->db->prepare("INSERT INTO bot_memory(kind,user_id,text,source,created_by,created_at,updated_at,is_archived) VALUES('meme',NULL,?,'auto',?,?,?,1)");
                    $stmt->bind_param('siss',$text,$creator,$dates[0],$dates[1]);$stmt->execute();$id=(int)$stmt->insert_id;$result['inserted']++;
                }
                $known[$key]=['id'=>$id];$result['ids'][(int)$row['id']]=$id;
            }
            return$result;
        };
        return $dryRun?$work():\Infra\Transaction::run($this->db,$work);
    }

}
