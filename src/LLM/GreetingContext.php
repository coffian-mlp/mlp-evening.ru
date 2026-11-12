<?php
namespace LLM;

/** Greeting-only history boundary; automatic bot output cannot keep a conversation alive. */
final class GreetingContext
{
    public const TIME_INSTRUCTION = ' Время суток определяй только по текущему [Время] в системном сообщении, а не по таймкодам, привычкам участников или прошлым пожеланиям спокойной ночи. Не представляй прошлые темы или привычки участников как происходящие сейчас.';

    public static function idle(array $messages, int $botId, int $now): bool
    {
        foreach ($messages as $message) {
            if ((int)($message['user_id'] ?? 0) === $botId || !empty($message['is_deleted'])) continue;
            $at = strtotime(($message['created_at'] ?? '') . ' UTC');
            if ($at !== false && $at <= $now && $now - $at < ConversationMemoryPolicy::IDLE) return false;
        }
        return true;
    }
}
