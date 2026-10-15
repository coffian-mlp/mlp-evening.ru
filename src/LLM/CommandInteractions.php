<?php
namespace LLM;

use Domain\CommandInteractionManager;

/** Composition root for reusable command buttons and continuations. */
final class CommandInteractions
{
    public static function registry(): array
    {
        return PlaylistCommand::interactionRegistry() + DrawingCommand::interactionRegistry();
    }

    public static function notify(int $id, int $actor, ?array $outcome = null): void
    {
        $result = (new CommandInteractionManager(self::registry()))->getResult($id, $actor, true);
        if (!empty($result['handler_context']['drawing_request'])) {
            CommandInteractionContinuation::reconcile(self::registry());
            return;
        }
        PlaylistCommand::queueInteractionReply($id, $actor, $outcome);
    }

    public static function deliver(LLMManager $llm, array $payload): bool
    {
        $result = (new CommandInteractionManager(self::registry()))->getResult((int)$payload['interaction_id'], (int)$payload['user_id'], true);
        if (!empty($result['handler_context']['drawing_request'])) {
            CommandInteractionContinuation::reconcile(self::registry());
            return true;
        }
        return (new PlaylistCommand($llm))->handleInteractionReply($payload);
    }
}
