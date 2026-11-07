<?php
namespace Domain;

use Infra\Database;
use Infra\Transaction;

/** Owns derived episodes and validates sources before publishing any API result. */
final class MemoryEpisodeManager
{
    private \mysqli $db;

    public function __construct(?\mysqli $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    public function connection(): \mysqli { return $this->db; }

    /** Source writes and derived invalidation share one connection and transaction. */
    public static function change(string $kind, array $ids, callable $write, bool $erase = false): mixed
    {
        $store = new self();
        return Transaction::run($store->db, function () use ($store, $kind, $ids, $write, $erase) {
            $result = $write();
            if ($result !== false && $result !== null) $store->invalidate($kind, $ids, $erase);
            return $result;
        });
    }

    private function installed(): bool
    {
        return $this->db->query("SHOW TABLES LIKE 'memory_episodes'")->num_rows === 1;
    }

    public function invalidate(string $kind, array $ids, bool $erase = false): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids || !$this->installed()) return;
        if (!in_array($kind, ['chat', 'memory'], true)) throw new \InvalidArgumentException('Invalid source kind');
        $in = implode(',', $ids);
        $statement = $erase ? 'DELETE e FROM memory_episodes e' : 'UPDATE memory_episodes e';
        $statement .= ' JOIN memory_episode_sources s ON s.episode_id=e.id';
        if (!$erase) {
            $statement .= " SET e.status='pending',e.revision=e.revision+1,e.associations=NULL,e.embedding=NULL,e.dimensions=NULL";
        }
        $this->db->query($statement . " WHERE s.source_kind='$kind' AND s.source_id IN ($in)");
    }

    public function eraseUser(int $userId): void
    {
        if (!$this->installed()) return;
        $stmt = $this->db->prepare('DELETE e FROM memory_episodes e JOIN memory_episode_sources s ON s.episode_id=e.id WHERE s.user_id=? OR s.related_user_id=?');
        $stmt->bind_param('ii', $userId, $userId);
        $stmt->execute();
    }

    /** API calls happen after this source-locked transaction returns. */
    public function register(array $episode, callable $validate): ?array
    {
        return Transaction::run($this->db, function () use ($episode, $validate) {
            if (!$validate($episode)) return null;
            $stmt = $this->db->prepare('SELECT id,source_hash,profile_hash,revision,status FROM memory_episodes WHERE episode_key=? FOR UPDATE');
            $stmt->bind_param('s', $episode['key']);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            if ($existing && $existing['source_hash'] === $episode['hash'] && $existing['profile_hash'] === $episode['profile']) {
                return $existing;
            }
            $authors = json_encode($episode['authors'], JSON_THROW_ON_ERROR);
            $stmt = $this->db->prepare("INSERT INTO memory_episodes(episode_key,source_kind,first_source_id,last_source_id,source_hash,profile_hash,authors_json,started_at,ended_at)
                VALUES(?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE source_kind=VALUES(source_kind),first_source_id=VALUES(first_source_id),last_source_id=VALUES(last_source_id),
                source_hash=VALUES(source_hash),profile_hash=VALUES(profile_hash),authors_json=VALUES(authors_json),started_at=VALUES(started_at),ended_at=VALUES(ended_at),
                revision=revision+1,status='pending',associations=NULL,embedding=NULL,dimensions=NULL");
            $stmt->bind_param('ssiisssss', $episode['key'], $episode['kind'], $episode['first_id'], $episode['last_id'],
                $episode['hash'], $episode['profile'], $authors, $episode['started_at'], $episode['ended_at']);
            $stmt->execute();
            $stmt = $this->db->prepare('SELECT id,source_hash,profile_hash,revision,status FROM memory_episodes WHERE episode_key=?');
            $stmt->bind_param('s', $episode['key']);
            $stmt->execute();
            $record = $stmt->get_result()->fetch_assoc();
            $id = (int)$record['id'];
            $stmt = $this->db->prepare('DELETE FROM memory_episode_sources WHERE episode_id=?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt = $this->db->prepare('INSERT INTO memory_episode_sources(episode_id,source_kind,source_id,user_id,related_user_id,start_offset,end_offset,source_signature) VALUES(?,?,?,?,?,?,?,?)');
            foreach ($episode['sources'] as $source) {
                $user = $source['user_id'] ?: null;
                $related = $source['related_user_id'] ?: null;
                $stmt->bind_param('isiiiiis', $id, $episode['kind'], $source['source_id'], $user, $related,
                    $source['start_offset'], $source['end_offset'], $source['signature']);
                $stmt->execute();
            }
            return $record;
        });
    }

    /** Recheck between association and embedding calls without holding a lock across either API. */
    public function current(array $episode, array $record, callable $validate): bool
    {
        return Transaction::run($this->db, function () use ($episode, $record, $validate) {
            if (!$validate($episode)) return false;
            $stmt = $this->db->prepare('SELECT revision,status FROM memory_episodes WHERE id=? FOR UPDATE');
            $stmt->bind_param('i', $record['id']);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            return $row && $row['status'] === 'pending' && (int)$row['revision'] === (int)$record['revision'];
        });
    }

    /** Deleted or resegmented sources cannot leave obsolete ready episodes behind. */
    public function prune(array $keys, ?callable $check = null): int
    {
        if ($check !== null) $check();
        $keep = array_fill_keys($keys, true);
        $removed = 0;
        $rows = $this->db->query('SELECT id,episode_key FROM memory_episodes')->fetch_all(MYSQLI_ASSOC);
        $stmt = $this->db->prepare('DELETE FROM memory_episodes WHERE id=?');
        foreach ($rows as $row) {
            if ($check !== null) $check();
            if (isset($keep[$row['episode_key']])) continue;
            $stmt->bind_param('i', $row['id']);
            $stmt->execute();
            $removed += $stmt->affected_rows;
        }
        return $removed;
    }

    public function publish(array $episode, array $record, string $associations, array $vector, callable $validate): bool
    {
        if (count($vector) !== 512 || trim($associations) === '' || mb_strlen($associations) > 1000) {
            throw new \InvalidArgumentException('Invalid derived memory');
        }
        $norm = 0.0;
        foreach ($vector as $value) {
            if ((!is_float($value) && !is_int($value)) || !is_finite((float)$value)) {
                throw new \InvalidArgumentException('Invalid memory vector');
            }
            $norm += $value * $value;
        }
        if ($norm <= 0 || !is_finite($norm)) throw new \InvalidArgumentException('Invalid memory vector norm');
        $binary = pack('g*', ...$vector);
        return Transaction::run($this->db, function () use ($episode, $record, $associations, $binary, $validate) {
            if (!$validate($episode)) return false;
            $stmt = $this->db->prepare("UPDATE memory_episodes SET associations=?,embedding=?,dimensions=512,status='ready'
                WHERE id=? AND revision=? AND source_hash=? AND profile_hash=? AND status='pending'");
            $stmt->bind_param('ssiiss', $associations, $binary, $record['id'], $record['revision'], $episode['hash'], $episode['profile']);
            $stmt->execute();
            return $stmt->affected_rows === 1;
        });
    }

    /** IDs only. Future consumers must hydrate sources before using any result. */
    public function findAssociationIds(string $query, int $limit = 8): array
    {
        $limit = max(1, min(50, $limit));
        $stmt = $this->db->prepare("SELECT id FROM memory_episodes WHERE status='ready' AND MATCH(associations) AGAINST(? IN NATURAL LANGUAGE MODE)
            ORDER BY MATCH(associations) AGAINST(? IN NATURAL LANGUAGE MODE) DESC,id LIMIT ?");
        $stmt->bind_param('ssi', $query, $query, $limit);
        $stmt->execute();
        return array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id'));
    }

    public function recallVectors(string $profile): array
    {
        $stmt=$this->db->prepare("SELECT id,revision,source_hash,dimensions,embedding FROM memory_episodes WHERE status='ready' AND profile_hash=? AND dimensions=512 ORDER BY id");
        $stmt->bind_param('s',$profile);$stmt->execute();return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function recallAssociationIds(string $query, string $profile, array $cosines = [], array $strong = []): array
    {
        $stmt=$this->db->prepare("SELECT id,MATCH(associations) AGAINST(? IN NATURAL LANGUAGE MODE) AS score FROM memory_episodes WHERE status='ready' AND profile_hash=? AND MATCH(associations) AGAINST(? IN NATURAL LANGUAGE MODE)");
        $stmt->bind_param('sss',$query,$profile,$query);$stmt->execute();$scores=[];
        foreach($stmt->get_result()->fetch_all(MYSQLI_ASSOC)as$row)$scores[(int)$row['id']]=(float)$row['score'];
        foreach($strong as$id)if(!isset($scores[$id]))$scores[$id]=0.0;
        return array_slice(self::recallLexicalOrder($scores,$cosines),0,8);
    }

    /** All meaningful terms must be present; structured identifiers bypass FT token-size limits. */
    public function recallStrongAssociationIds(array $terms, string $profile): array
    {
        $words=[];$identifier='';
        foreach($terms as$term){
            if($identifier===''&&preg_match('/[\p{L}]/u',$term)&&preg_match('/[0-9]/',$term)&&str_contains($term,'-'))$identifier=$term;
            foreach(preg_split('/[^\p{L}\p{N}_]+/u',$term,-1,PREG_SPLIT_NO_EMPTY)as$word)if(mb_strlen($word)>=3)$words[$word]=true;
        }
        $boolean=implode(' ',array_map(static fn($word)=>'+'.$word,array_keys($words)));
        $like='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$identifier).'%';
        if($boolean===''&&$identifier==='')return [];
        $stmt=$this->db->prepare("SELECT id FROM memory_episodes WHERE status='ready' AND profile_hash=? AND ((?<>'' AND MATCH(associations) AGAINST(? IN BOOLEAN MODE)) OR (?<>'' AND associations LIKE ?))");
        $stmt->bind_param('sssss',$profile,$boolean,$boolean,$identifier,$like);$stmt->execute();return array_map('intval',array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC),'id'));
    }

    public static function recallLexicalOrder(array $scores, array $cosines): array
    {
        uksort($scores,static function($a,$b)use($scores,$cosines){
            if(abs($scores[$b]-$scores[$a])>1e-9)return $scores[$b]<=>$scores[$a];
            return (($cosines[$b]??-1)<=>($cosines[$a]??-1))?:($a<=>$b);
        });
        return array_keys($scores);
    }

    public function recallSourceIds(array $sourceIds, array $userIds, ?string $date, string $profile): array
    {
        $sourceIds=array_values(array_filter(array_map('intval',$sourceIds)));$userIds=array_values(array_filter(array_map('intval',$userIds)));
        $parts=[];
        if($sourceIds)$parts[]="(s.source_kind='chat' AND s.source_id IN (".implode(',',$sourceIds).'))';
        if($userIds){$ids=implode(',',$userIds);$parts[]="(s.user_id IN ($ids) OR s.related_user_id IN ($ids))";}
        if($date!==null){$day=$this->db->real_escape_string($date);$parts[]="(e.started_at<'$day'+INTERVAL 1 DAY AND e.ended_at>='$day')";}
        if(!$parts)return [];
        $stmt=$this->db->prepare("SELECT DISTINCT e.id,e.ended_at FROM memory_episodes e JOIN memory_episode_sources s ON s.episode_id=e.id WHERE e.status='ready' AND e.profile_hash=? AND (".implode(' OR ',$parts).') ORDER BY e.ended_at DESC,e.id LIMIT 8');
        $stmt->bind_param('s',$profile);$stmt->execute();return array_map('intval',array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC),'id'));
    }

    public function recallRecords(array $ids, string $profile): array
    {
        $ids=array_slice(array_values(array_unique(array_filter(array_map('intval',$ids)))),0,24);if(!$ids)return [];
        $stmt=$this->db->prepare("SELECT id,source_kind,source_hash,revision,started_at,ended_at FROM memory_episodes WHERE status='ready' AND profile_hash=? AND id IN (".implode(',',$ids).')');
        $stmt->bind_param('s',$profile);$stmt->execute();return array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC),null,'id');
    }

    public function recallMembers(array $ids): array
    {
        $ids=array_slice(array_values(array_unique(array_filter(array_map('intval',$ids)))),0,24);if(!$ids)return [];
        return $this->db->query('SELECT episode_id,source_kind,source_id,user_id,related_user_id,start_offset,end_offset,source_signature FROM memory_episode_sources WHERE episode_id IN ('.implode(',',$ids).') ORDER BY episode_id,source_id,start_offset')->fetch_all(MYSQLI_ASSOC);
    }

}
