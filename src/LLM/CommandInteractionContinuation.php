<?php

namespace LLM;

use Core\UserError;
use Domain\BotCommandManager;
use Domain\ChatManager;
use Domain\CommandInteractionManager;
use Infra\Database;
use Infra\ConfigManager;

/** Generic continuation routing and async work; handlers own all domain interpretation. */
final class CommandInteractionContinuation
{
    public static function routeMessage(array $payload, array $registry): bool
    {
        $source = self::routeSource($payload);
        if ($source === null) return false;
        $manager = new CommandInteractionManager($registry);
        $text = html_entity_decode((string)($source['raw_message'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $quotes = json_decode((string)($source['quoted_msg_ids'] ?? '[]'), true) ?: [];
        $addresses = (new LLMManager())->messageAddressesBot($text, []);
        foreach ($registry as $type => $handler) {
            if (!isset($handler['continuation'])) continue;
            $result = self::routeHandler($manager, $source, $text, $quotes, $addresses, $type);
            if ($result !== null) return $result;
        }
        return false;
    }

    private static function routeSource(array $payload): ?array
    {
        $actor = (int)($payload['user_id'] ?? 0); $sourceId = (int)($payload['message_id'] ?? 0);
        if ($actor < 1 || $sourceId < 1) return null;
        $source = (new ChatManager())->getMessageById($sourceId);
        if (!$source || !empty($source['is_deleted']) || (int)$source['user_id'] !== $actor) return null;
        $text = html_entity_decode((string)($source['raw_message'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('/^[!\/]\p{L}+/u', trim($text))) return null;
        if (BotCommandManager::matchCommand((new BotCommandManager())->getActive(), $text)) return null;
        return $source;
    }

    private static function routeHandler(CommandInteractionManager $manager, array $source, string $text, array $quotes, bool $addresses, string $type): ?bool
    {
        $capability = $manager->continuationHandler($type);
        $parsed = ($capability['classify'])($text, 'pending');
        if (!self::canLookupTarget($quotes, $addresses, $parsed)) return null;
        $actor = (int)$source['user_id'];
        $target = $manager->lookupTarget($actor, $quotes, ['intent' => $parsed['intent'] ?? 'unknown', 'addressesBot' => $addresses]);
        if (($target['status'] ?? '') === 'target') {
            $selected = $target['target'];
            if ($selected['type'] !== $type) return null;
            $parsed = ($capability['classify'])($text, $selected['state']);
            if (($parsed['intent'] ?? 'unknown') === 'unknown') return false;
            self::applyTarget($manager, $selected, $source, $parsed);
            return true;
        }
        if (in_array($target['status'] ?? '', ['ambiguous', 'invalid'], true) && ($parsed['intent'] ?? 'unknown') !== 'unknown') {
            (new ChatManager())->assertCanSend($actor);
            self::queueNotice($source, $type, 'ambiguous_choice');
            return true;
        }
        return null;
    }

    private static function canLookupTarget(array $quotes, bool $addresses, array $parsed): bool
    {
        return $quotes !== [] || ($addresses && ($parsed['intent'] ?? 'unknown') !== 'unknown');
    }

    private static function applyTarget(CommandInteractionManager $manager, array $selected, array $source, array $parsed): void
    {
        $actor = (int)$source['user_id']; $sourceId = (int)$source['id'];
        $version = ChatManager::interactionSourceVersion($source);
        $parsed['source_version'] = $version;
        $parsed['source_version_encoding'] = 'storage_v2';
        $followup = ['id' => $sourceId, 'user_id' => $actor, 'version' => $version, 'version_encoding' => 'storage_v2'];
        try {
            if ($parsed['intent'] === 'cancel') {
                $manager->cancelActive($selected['id'], $actor, $followup);
                self::queueTerminal($selected['id'], $actor);
            } elseif ($parsed['intent'] === 'refine' && trim((string)($parsed['text'] ?? '')) === '') {
                self::enqueue($manager->requestRefinement($selected['id'], $actor, $followup), $actor);
            } else {
                self::enqueue($manager->submitClarification($selected['id'], $actor, $sourceId, $parsed), $actor);
            }
        } catch (UserError $error) {
            (new ChatManager())->assertCanSend($actor);
            self::queueNotice($source, $selected['type'], $selected['state'] === 'resolving' ? 'choice_busy' : 'context_overflow');
        }
    }

    public static function enqueue(array $operation, int $actor): void
    {
        if (empty($operation['interaction_id']) || !isset($operation['revision'], $operation['operation_key'])) return;
        try {
            BotDispatch::dispatch('dynamic_command', ['command' => ['handler_type' => 'command_interaction_reply'], 'continuation_work' => true,
                'interaction_id' => (int)$operation['interaction_id'], 'revision' => (int)$operation['revision'],
                'operation_key' => $operation['operation_key'], 'user_id' => $actor]);
        } catch (\Throwable $error) {
            error_log('Interaction work enqueue failed: id=' . (int)$operation['interaction_id'] . ' class=' . get_class($error));
        }
    }

    public static function queueTerminal(int $id, int $actor): void
    {
        BotDispatch::dispatch('dynamic_command', ['command' => ['handler_type' => 'command_interaction_reply'], 'interaction_id' => $id, 'user_id' => $actor]);
    }

    public static function reconcile(array $registry, int $limit = 20): void
    {
        $manager = new CommandInteractionManager($registry);
        foreach ($manager->listRecoverableWork($limit) as $work) self::enqueue($work, $work['user_id']);
    }

    public static function processWork(array $payload, array $registry): bool
    {
        if (isset($payload['notice'])) return self::processNotice($payload, $registry);
        $id = (int)($payload['interaction_id'] ?? 0); $revision = (int)($payload['revision'] ?? -1); $key = (string)($payload['operation_key'] ?? '');
        if ($id < 1 || $revision < 0 || $key === '') return false;
        $manager = new CommandInteractionManager($registry);
        try { $snapshot = $manager->claimWork($id, $revision, $key); }
        catch (UserError $error) { return false; }
        if (!$snapshot) return false;
        return self::processClaimedWork($manager, $snapshot);
    }

    private static function processNotice(array $payload, array $registry): bool
    {
        try { return self::deliverNotice($payload, $registry); }
        catch (\Throwable $error) { error_log('Interaction notice failed: source=' . (int)($payload['message_id'] ?? 0) . ' class=' . get_class($error)); return false; }
    }

    private static function processClaimedWork(CommandInteractionManager $manager, array $snapshot): bool
    {
        $id = $snapshot['interaction_id']; $revision = $snapshot['revision']; $key = $snapshot['operation_key']; $token = $snapshot['lease_token'];
        try {
            $handler = $manager->continuationHandler($snapshot['type']);
            if (($snapshot['work']['kind'] ?? '') === 'terminal') return self::renderTerminalWork($manager, $snapshot, $handler);
            $deadline = min(time() + 55, strtotime($snapshot['context']['root_expires_at'] . ' UTC'));
            $result = self::verifiedWorkResult($manager, $snapshot, $handler, $deadline);
            if ($result === null) return false;
            return self::renderWorkResult($manager, $snapshot, $handler, $result, $deadline);
        } catch (\Throwable $error) {
            try { $manager->completeWork($id, $revision, $key, $token, false); } catch (\Throwable $ignored) {}
            error_log('Interaction work failed: id=' . $id . ' revision=' . $revision . ' class=' . get_class($error));
            return false;
        }
    }

    private static function actionContext(array $snapshot): array
    {
        return ['actor_id' => (int)$snapshot['owner_id'], 'data' => $snapshot['context']['handler_context']];
    }

    private static function renderTerminalWork(CommandInteractionManager $manager, array $snapshot, array $handler): bool
    {
        $deadline = min(time() + 20, (int)$snapshot['work']['delivery_expires_at']);
        $text = ($handler['format'])('terminal', ['outcome' => $snapshot['outcome'], '_deadline' => $deadline, '_action_context' => self::actionContext($snapshot)]);
        if (!is_string($text) || trim($text) === '') throw new \RuntimeException('Empty terminal reply');
        return $manager->publishTerminalWork($snapshot['interaction_id'], $snapshot['revision'], $snapshot['operation_key'], $snapshot['lease_token'], $text) !== null;
    }

    private static function verifiedWorkResult(CommandInteractionManager $manager, array $snapshot, array $handler, int $deadline): ?array
    {
        $result = $snapshot['context']['resolver_result'];
        if ($snapshot['work']['kind'] === 'resolve' && $result === null) {
            $result = ($handler['resolve'])($snapshot['context']['handler_context'], $deadline);
            if (!$manager->saveVerifiedResult($snapshot['interaction_id'], $snapshot['revision'], $snapshot['operation_key'], $snapshot['lease_token'], $result)) return null;
        }
        return $result ?? ['status' => 'empty', 'code' => 'choice_clarifying'];
    }

    private static function renderWorkResult(CommandInteractionManager $manager, array $snapshot, array $handler, array $result, int $deadline): bool
    {
        $id = $snapshot['interaction_id']; $revision = $snapshot['revision']; $key = $snapshot['operation_key']; $token = $snapshot['lease_token'];
        $child = ($result['status'] ?? '') === 'candidates';
        $childId = $child ? $manager->stageChild($id, $revision, $key, $token) : null;
        if ($child && !$childId) return false;
        $result['_deadline'] = min($deadline, time() + 20);
        $result['_action_context'] = self::actionContext($snapshot);
        $text = ($handler['format'])($child ? 'candidates' : 'clarifying', $result);
        if (!is_string($text) || trim($text) === '') throw new \RuntimeException('Empty continuation reply');
        if ($child) $text .= "\n[[command:" . $childId . ']]';
        // Questions are quote targets, not duplicate public widgets for the parent.
        return $manager->publishWork($id, $revision, $key, $token, $text, $child) !== null;
    }

    private static function queueNotice(array $source, string $type, string $code): void
    {
        BotDispatch::dispatch('dynamic_command', ['command' => ['handler_type' => 'command_interaction_reply'], 'continuation_work' => true,
            'notice' => ['type' => $type, 'code' => $code], 'message_id' => (int)$source['id'], 'user_id' => (int)$source['user_id'],
            'source_version' => ChatManager::interactionSourceVersion($source), 'source_version_encoding' => 'storage_v2']);
    }

    private static function deliverNotice(array $payload, array $registry): bool
    {
        if (!self::validNoticeSource($payload)) return false;
        $chat = new ChatManager(); $sourceId = (int)$payload['message_id']; $actor = (int)$payload['user_id'];
        $marker = '[[command-delivery:notice_' . $sourceId . ']]';
        if ($chat->findBotReplyTo($sourceId, $marker)) return true;
        $handler = (new CommandInteractionManager($registry))->continuationHandler($payload['notice']['type']);
        if (($handler['permission'])($actor, []) === false) return false;
        $text = ($handler['format'])('clarifying', ['code' => $payload['notice']['code'], '_deadline' => time() + 20, '_action_context' => ['actor_id' => $actor, 'data' => []]]);
        return self::publishNotice($payload, $handler, $text, $marker);
    }

    private static function validNoticeSource(array $payload): bool
    {
        $sourceId = (int)($payload['message_id'] ?? 0); $actor = (int)($payload['user_id'] ?? 0);
        $source = (new ChatManager())->getMessageById($sourceId);
        if (!$source || !empty($source['is_deleted']) || (int)$source['user_id'] !== $actor
            || !hash_equals((string)($payload['source_version'] ?? ''), ChatManager::interactionSourceVersion($source, $payload['source_version_encoding'] ?? 'getter_read_v1'))) return false;
        try { (new ChatManager())->assertCanSend($actor); } catch (UserError $error) { return false; }
        return true;
    }

    private static function publishNotice(array $payload, array $handler, string $text, string $marker): bool
    {
        $chat = new ChatManager(); $sourceId = (int)$payload['message_id'];
        $bot = (int)ConfigManager::getInstance()->getOption('ai_bot_user_id', 0);
        $db = Database::getInstance()->getConnection(); $lock = 'interaction_notice:' . $sourceId;
        $stmt = $db->prepare('SELECT GET_LOCK(?,2) AS ok'); $stmt->bind_param('s', $lock); $stmt->execute();
        if ((int)($stmt->get_result()->fetch_assoc()['ok'] ?? 0) !== 1) return false;
        $published = null;
        try {
            if ($chat->findBotReplyTo($sourceId, $marker)) return true;
            $published = $chat->addInteractionMessageGuarded($bot, 'Лира', $text . "\n" . $marker, [$sourceId],
                static fn() => self::guardNoticeSource($chat, $payload, $handler), static function ($id) {}, true);
        } finally {
            $stmt = $db->prepare('SELECT RELEASE_LOCK(?)'); $stmt->bind_param('s', $lock); $stmt->execute();
        }
        if ($published !== null) $chat->publishInteractionMessage($published);
        return true;
    }

    private static function guardNoticeSource(ChatManager $chat, array $payload, array $handler): void
    {
        $actor = (int)$payload['user_id'];
        $chat->assertCanSend($actor);
        if (($handler['permission'])($actor, []) === false) throw new UserError('Команда сейчас недоступна.');
        $chat->lockInteractionMessages([['id' => (int)$payload['message_id'], 'user_id' => $actor, 'version' => $payload['source_version'], 'version_encoding' => $payload['source_version_encoding'] ?? 'getter_read_v1']]);
    }
}
