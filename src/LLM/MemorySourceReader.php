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
}
