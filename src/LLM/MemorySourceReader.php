<?php
namespace LLM;

use Domain\BotMemoryManager;
use Domain\ChatManager;
use Domain\Privacy;
use Domain\UserManager;

/** Composes source owners without introducing dependencies between their managers. */
final class MemorySourceReader
{
    private ChatManager $chat;
    private BotMemoryManager $memory;
    private UserManager $users;

    public function __construct()
    {
        $this->chat = new ChatManager();
        $this->memory = new BotMemoryManager();
        $this->users = new UserManager();
    }

    public function sources(?callable $check = null, ?callable $beforeQuery = null): array
    {
        if ($beforeQuery !== null) $beforeQuery();
        $chat = $this->chat->memorySourceRows();
        if ($beforeQuery !== null) $beforeQuery();
        $memory = $this->memory->memorySourceRows();
        $allowed = $this->allowedUsers(array_merge($chat, $memory), false, $check, $beforeQuery);
        return [
            'chat' => array_values(array_filter($chat, function ($row) use ($allowed, $check) { if ($check !== null) $check(); return $this->eligible($row, 'chat', $allowed); })),
            'memory' => array_values(array_filter($memory, function ($row) use ($allowed, $check) { if ($check !== null) $check(); return $this->eligible($row, 'memory', $allowed); })),
        ];
    }

    /** Called only inside the episode transaction, on the same application connection. */
    public function current(array $episode, ?callable $beforeQuery = null): bool
    {
        $kind = $episode['kind'];
        if (!in_array($kind, ['chat', 'memory'], true)) throw new \InvalidArgumentException('Invalid source kind');
        $ids = array_values(array_unique(array_column($episode['sources'], 'source_id')));
        if ($beforeQuery !== null) $beforeQuery();
        $rows = $kind === 'chat'
            ? $this->chat->memorySourceRows($ids, true)
            : $this->memory->memorySourceRows($ids, true);
        if (count($rows) !== count($ids)) return false;
        $allowed = $this->allowedUsers($rows, true, null, $beforeQuery);
        $rows = array_column($rows, null, 'id');
        foreach ($episode['sources'] as $source) {
            $row = $rows[$source['source_id']];
            if (!$this->eligible($row, $kind, $allowed) || MemoryEpisodeBuilder::signature($row, $kind) !== $source['signature']) return false;
        }
        return true;
    }

    private function allowedUsers(array $rows, bool $lock, ?callable $check, ?callable $beforeQuery): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if ($check !== null) $check();
            foreach ([$row['user_id'] ?? null, $row['created_by'] ?? null] as $id) {
                if ($id) $ids[(int)$id] = (int)$id;
            }
        }
        if ($beforeQuery !== null) $beforeQuery();
        $allowed = array_fill_keys($this->users->memoryIdentityIds(array_values($ids), $lock), true);
        if ($beforeQuery !== null) $beforeQuery();
        foreach (Privacy::memoryStates(array_values($ids), $lock) as $row) {
            if (in_array($row['state'], ['withdrawn', 'deleted'], true)) unset($allowed[$row['user_id']]);
        }
        return $allowed;
    }

    private function eligible(array $row, string $kind, array $allowed): bool
    {
        if (empty($row['created_at']) || MemoryEpisodeBuilder::text($row, $kind) === '') return false;
        if ($kind === 'chat' && (!empty($row['is_deleted']) || empty($row['user_id']))) return false;
        foreach ([$row['user_id'] ?? null, $row['created_by'] ?? null] as $id) {
            if ($id !== null && !isset($allowed[$id])) return false;
        }
        return true;
    }
    /** Batch hydration never trusts cached text, membership signatures or old consent. */
    public function recall(array $records, array $members, string $profile, int $botId, callable $check, callable $prepare): array
    {
        $groups=[];$sourceIds=['chat'=>[],'memory'=>[]];
        foreach($members as $member){$check();$kind=$member['source_kind'];if(!isset($sourceIds[$kind]))continue;$groups[$member['episode_id']][]=$member;$sourceIds[$kind][]=(int)$member['source_id'];}
        $prepare();$chat=$this->chat->memorySourceRows($sourceIds['chat']);$prepare();$memory=$this->memory->memorySourceRows($sourceIds['memory']);
        $allowed=$this->allowedUsers(array_merge($chat,$memory),false,$check,$prepare);
        $rows=['chat'=>array_column($chat,null,'id'),'memory'=>array_column($memory,null,'id')];$result=[];
        foreach($records as $id=>$record){
            $check();$refs=[];$lines=[];$keys=[];$valid=true;
            foreach($groups[$id]??[] as $member){
                $check();$kind=$member['source_kind'];$row=$rows[$kind][$member['source_id']]??null;
                if(!$row||!$this->eligible($row,$kind,$allowed)||MemoryEpisodeBuilder::signature($row,$kind)!==$member['source_signature']){$valid=false;break;}
                $refs[]=['source_id'=>(int)$member['source_id'],'user_id'=>(int)($member['user_id']??0),'related_user_id'=>(int)($member['related_user_id']??0),'start_offset'=>(int)$member['start_offset'],'end_offset'=>(int)$member['end_offset'],'signature'=>$member['source_signature']];
                $text=MemoryEpisodeBuilder::text($row,$kind);$part=mb_substr($text,(int)$member['start_offset'],(int)$member['end_offset']-(int)$member['start_offset']);
                $author=$kind==='chat'?mb_substr(preg_replace('/\s+/u',' ',(string)$row['username']),0,80):'user_id='.(int)($row['user_id']??$row['created_by']??0);
                $role=$kind==='chat'?((int)$row['user_id']===$botId?'bot':'user'):'memory';
                $lines[]=['source'=>$kind.':'.$member['source_id'],'author'=>$author,'user_id'=>isset($row['user_id'])?(int)$row['user_id']:null,'role'=>$role,'date_utc'=>$row['created_at'],'text'=>$part];$keys[]=$kind.':'.$member['source_id'];
            }
            if(!$valid||!$refs)continue;
            $hash=hash('sha256',json_encode([MemoryEpisodeBuilder::VERSION,$botId,$profile,$refs],JSON_THROW_ON_ERROR));
            if(!hash_equals($record['source_hash'],$hash))continue;
            $result[$id]=['id'=>(int)$id,'date'=>$record['started_at'],'source_ids'=>array_values(array_unique($keys)),'lines'=>$lines,'summary'=>implode(' ',array_map(static fn($l)=>'['.$l['role'].' user_id='.($l['user_id']??'null').'] '.$l['author'].': '.$l['text'],$lines))];
        }
        return $result;
    }

    /** Full range hash detects direct edits as well as mutations through application hooks. */
    public function conversation(array $rows, int $first, int $cursor, int $bot, bool $lock = false): array {
        $rows = array_values(array_filter($rows, static fn($r) => (int)$r['id'] >= $first));
        $ids = array_values(array_unique(array_filter(array_map('intval', array_column($rows, 'user_id')))));
        $existing = array_fill_keys($this->users->conversationIdentityIds($ids, $lock), true);
        $states = array_column(Privacy::conversationStates($ids, $lock), null, 'user_id');
        $version = Privacy::version(); $allowed = []; $blocked = false;
        foreach ($ids as $id) {
            $allowed[$id] = isset($existing[$id]) && ($id === $bot || (($states[$id]['state'] ?? '') === 'active' && ($states[$id]['policy_version'] ?? '') === $version));
            if (!$allowed[$id]) $blocked = true;
        }
        $hash = hash_init('sha256'); $covered = [];
        foreach ($rows as $r) {
            if (!(int)$r['user_id']) { $blocked = true; }
            if ((int)$r['id'] <= $cursor) {
                hash_update($hash, json_encode([$r, $states[(int)$r['user_id']] ?? null, isset($existing[(int)$r['user_id']])], JSON_THROW_ON_ERROR));
                $covered[] = $r;
            }
        }
        return ['hash' => hash_final($hash), 'blocked' => $blocked, 'rows' => $rows, 'covered' => $covered];
    }

    public function conversationDossiers(array $ids, callable $prepare): array {
        $prepare(); $rows = $this->memory->memorySourceRows($ids);
        $allowed = $this->allowedUsers($rows, false, null, $prepare);
        return array_values(array_filter($rows, fn($r) => $this->eligible($r, 'memory', $allowed)));
    }

}
