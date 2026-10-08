<?php
namespace LLM;

use Domain\BotMemoryManager;
use Domain\UserManager;

/** Resolve explicit command subjects independently of the author and command recipient. */
final class CommandMentionContext
{
    public const MAX_USERS = 5;

    public static function names(string $text): array
    {
        preg_match_all('/(?<![\p{L}\p{N}_@])@([\p{L}\p{N}_-]+)(?![\p{L}\p{N}_-])/u', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $matches);
        return array_values(array_unique(array_map('mb_strtolower', $matches[1])));
    }

    public static function resolve(string $text, callable $lookup): array
    {
        $users = [];
        foreach (array_slice(self::names($text), 0, self::MAX_USERS) as $name) {
            $user = $lookup($name);
            if (!$user || (int)($user['id'] ?? 0) <= 0) continue;
            $id = (int)$user['id'];
            $users[$id] = ['id' => $id,
                'login' => mb_substr(BotMemoryManager::normalizeText((string)$user['login']), 0, 80),
                'nickname' => mb_substr(BotMemoryManager::normalizeText((string)($user['nickname'] ?? $user['login'])), 0, 100)];
        }
        return array_values($users);
    }

    public static function build(LLMManager $llm, string $text): array
    {
        if (!self::names($text)) return ['users' => [], 'memory' => null];
        $users = self::resolve($text, (new UserManager())->findByLoginOrNickname(...));
        $memory = null;
        if ($users) {
            try { $memory = (new LyraMemory($llm))->buildPromptBlock(array_column($users, 'id'), false); }
            catch (\Throwable $e) { error_log('Command subject memory unavailable: ' . get_class($e)); }
        }
        return ['users' => $users, 'memory' => $memory];
    }
}
