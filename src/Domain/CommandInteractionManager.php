<?php

namespace Domain;

use Core\UserError;
use Infra\ConfigManager;
use Infra\Database;
use Infra\Transaction;
use RuntimeException;

/** Server-owned choices; command handlers are registered by the composition root. */
final class CommandInteractionManager
{
    private \mysqli $db;
    private array $registry;

    public function __construct(?array $registry = null)
    {
        $this->db = Database::getInstance()->getConnection();
        $this->registry = $registry ?? [];
    }

    public function create(string $type, int $ownerId, int $sourceMessageId, array $options, int $ttlSeconds = 900): int
    {
        $this->handler($type);
        $source = (new ChatManager())->getMessageById($sourceMessageId);
        if (!$source || !empty($source['deleted']) || (int)$source['user_id'] !== $ownerId) {
            throw new UserError('Исходная команда недоступна. Повтори команду.');
        }
        $this->assertAllowed($ownerId);
        if (count($options) < 1 || count($options) > 8 || $ttlSeconds < 1 || $ttlSeconds > 86400) {
            throw new RuntimeException('Invalid interaction options or expiry');
        }
        $keys = [];
        foreach ($options as $option) {
            $key = $option['key'] ?? '';
            $label = $option['label'] ?? '';
            if (!is_string($key) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $key)
                || isset($keys[$key]) || !is_string($label) || trim($label) === '' || mb_strlen($label) > 160
                || !is_array($option['payload'] ?? [])) {
                throw new RuntimeException('Invalid interaction option');
            }
            $keys[$key] = true;
        }
        $json = json_encode($options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (strlen($json) > 32768) throw new RuntimeException('Interaction payload too large');
        $version = $this->sourceVersion($source, 'getter_read_v1');
        $expiry = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);
        $stmt = $this->db->prepare('INSERT INTO command_interactions (type,owner_id,source_message_id,source_version,options_json,expires_at) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)');
        $stmt->bind_param('siisss', $type, $ownerId, $sourceMessageId, $version, $json, $expiry);
        $stmt->execute();
        return (int)$this->db->insert_id;
    }

    public function bindMessage(int $interactionId, int $botMessageId): void
    {
        $this->mutate($interactionId, function ($row) use ($interactionId, $botMessageId) {
            if (($row['context_json'] ?? null) !== null) {
                $this->guard($row, (int)$row['owner_id']);
                if (!in_array($row['state'], ['pending', 'clarifying', 'resolving'], true)) throw new UserError('Выбор уже закрыт.');
            }
            $botId = (int)ConfigManager::getInstance()->getOption('ai_bot_user_id', 0);
            $message = (new ChatManager())->lockInteractionMessages([['id' => $botMessageId, 'user_id' => $botId]])[$botMessageId];
            if (!$message || !empty($message['deleted']) || $botId < 1 || (int)$message['user_id'] !== $botId
                || !str_contains($message['raw_message'] ?? '', '[[command:' . $interactionId . ']]')) {
                throw new RuntimeException('Interaction must bind to its live bot message');
            }
            if ($row['bot_message_id'] !== null && (int)$row['bot_message_id'] !== $botMessageId) {
                throw new RuntimeException('Interaction is already bound');
            }
            $stmt = $this->db->prepare('UPDATE command_interactions SET bot_message_id=?,bot_user_id=? WHERE id=?');
            $stmt->bind_param('iii', $botMessageId, $botId, $interactionId);
            $stmt->execute();
            if (($row['context_json'] ?? null) !== null) {
                $context = $this->requireContext($row);
                $context['proposal_binding'] = ['id' => $botMessageId, 'user_id' => $botId, 'version' => $this->sourceVersion($message), 'version_encoding' => 'storage_v2', 'marker' => '[[command:' . $interactionId . ']]'];
                $this->saveContext($interactionId, $context, $row['state']);
            }
        });
    }

    public function readPublic(int $interactionId, ?int $viewerId = null, ?int $messageId = null): array
    {
        $row = $this->row($interactionId);
        $handler = $this->handler($row['type']);
        $owner = $viewerId !== null && $viewerId === (int)$row['owner_id'];
        $state = $row['state'];
        $valid = $messageId !== null && $messageId === (int)$row['bot_message_id'] && $this->validBinding($row);
        if (in_array($state, ['pending', 'clarifying', 'resolving'], true) && strtotime($row['expires_at'] . ' UTC') <= time()) $state = 'expired';
        if (!$valid) $state = 'unavailable';
        $public = ($handler['visibility'] ?? 'owner') === 'public';
        $result = ['id' => $interactionId, 'message_id' => (int)$row['bot_message_id'], 'state' => $state,
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($row['expires_at'] . ' UTC')),
            'can_act' => false, 'options' => []];
        if (!$valid || (!$owner && !$public)) return $result;
        $result['capabilities'] = ['refine' => ($row['context_json'] ?? null) !== null && $state === 'pending', 'cancel' => ($row['context_json'] ?? null) !== null && in_array($state, ['pending', 'clarifying', 'resolving'], true)];
        $result['options'] = array_map(static fn($option) => ['key' => $option['key'], 'label' => $option['label']],
            array_values(array_filter(json_decode($row['options_json'], true, 512, JSON_THROW_ON_ERROR), static fn($option) => ($row['context_json'] ?? null) === null || $state === 'pending' || $option['key'] === 'cancel')));
        if ($owner && in_array($state, ['pending', 'clarifying', 'resolving'], true)) {
            try { $this->assertAllowed($viewerId); $result['can_act'] = true; } catch (UserError $error) {}
        }
        if ($owner && $row['outcome_json'] !== null) {
            $outcome = json_decode($row['outcome_json'], true, 512, JSON_THROW_ON_ERROR);
            $result['result'] = ['status' => $outcome['status'], 'code' => $outcome['code']];
        }
        return $result;
    }

    public function consume(int $interactionId, string $optionKey, int $actorId, ?int $now = null): array
    {
        $initial = $this->row($interactionId);
        if (($initial['context_json'] ?? null) !== null) {
            if ($optionKey === 'refine') return $this->requestRefinement($interactionId, $actorId);
            if ($optionKey === 'cancel') return $this->cancelActive($interactionId, $actorId);
        }
        return $this->mutate($interactionId, function ($row) use ($interactionId, $optionKey, $actorId, $now) {
            $this->guard($row, $actorId);
            if ((int)$row['owner_id'] !== $actorId) throw new UserError('Этот выбор принадлежит другому пользователю.');
            $this->assertAllowed($actorId);
            if (!$this->validBinding($row)) throw new UserError('Исходная команда недоступна. Повтори команду.');
            if ($row['outcome_json'] !== null) return json_decode($row['outcome_json'], true, 512, JSON_THROW_ON_ERROR);
            if ($row['state'] !== 'pending' || strtotime($row['expires_at'] . ' UTC') <= ($now ?? time())) {
                throw new UserError('Срок выбора истёк. Повтори команду.');
            }
            $options = json_decode($row['options_json'], true, 512, JSON_THROW_ON_ERROR);
            $selected = null;
            foreach ($options as $option) if ($option['key'] === $optionKey) $selected = $option;
            if ($selected === null) throw new UserError('Неизвестный вариант выбора.');
            if ($optionKey === 'cancel') {
                $outcome = ['status' => 'cancelled', 'code' => 'choice_cancelled', 'facts' => []];
            } else {
                $handler = $this->handler($row['type']);
                $payload = $selected['payload'] ?? [];
                $allowed = ($handler['permission'])($actorId, $payload);
                if ($allowed === false) throw new UserError('Действие недоступно.');
                $outcome = ($handler['execute'])($actorId, $payload, 'interaction:' . $interactionId);
                if (!is_array($outcome) || !in_array($outcome['status'] ?? '', ['accepted', 'rejected', 'cancelled'], true)
                    || !is_string($outcome['code'] ?? null) || !is_array($outcome['facts'] ?? null)) {
                    throw new RuntimeException('Invalid command handler outcome');
                }
            }
            $json = json_encode($outcome, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $state = $outcome['status'] === 'cancelled' ? 'cancelled' : 'consumed';
            if (($row['context_json'] ?? null) !== null) {
                $context = $this->requireContext($row); $context['revision']++;
                $context['pending_work'] = $this->newWork($context, 'terminal:' . $interactionId, 'terminal');
                $this->saveContext($interactionId, $context, $state);
            }
            $stmt = $this->db->prepare('UPDATE command_interactions SET state=?,selected_key=?,outcome_json=? WHERE id=?');
            $stmt->bind_param('sssi', $state, $optionKey, $json, $interactionId);
            $stmt->execute();
            // MySQL JSON canonicalizes key order; the first response and replay share persisted form.
            return json_decode($this->row($interactionId)['outcome_json'], true, 512, JSON_THROW_ON_ERROR);
        });
    }

    public function getResult(int $id, int $actorId, bool $includeHandlerContext = false): array
    {
        $row = $this->row($id);
        if ((int)$row['owner_id'] !== $actorId) throw new UserError('Этот выбор принадлежит другому пользователю.');
        $result = ['outcome' => $row['outcome_json'] === null ? null : json_decode($row['outcome_json'], true, 512, JSON_THROW_ON_ERROR),
            'reply_message_id' => $row['result_message_id'] === null ? null : (int)$row['result_message_id'],
            'bot_message_id' => $row['bot_message_id'] === null ? null : (int)$row['bot_message_id'],
            'source_message_id' => (int)$row['source_message_id']];
        if ($includeHandlerContext) $result['handler_context'] = ($row['context_json'] ?? null) === null ? [] : $this->requireContext($row)['handler_context'];
        return $result;
    }

    public function bindResultMessage(int $id, int $messageId): void
    {
        Transaction::run($this->db, function () use ($id, $messageId) {
            $row = $this->row($id, true);
            $message = (new ChatManager())->getMessageById($messageId);
            if ($row['outcome_json'] === null || !$message || !empty($message['deleted'])
                || (int)$message['user_id'] !== (int)$row['bot_user_id']) throw new RuntimeException('Invalid interaction result message');
            if ($row['result_message_id'] !== null && (int)$row['result_message_id'] !== $messageId) throw new RuntimeException('Result already bound');
            $stmt = $this->db->prepare('UPDATE command_interactions SET result_message_id=? WHERE id=?');
            $stmt->bind_param('ii', $messageId, $id);
            $stmt->execute();
            if (($row['context_json'] ?? null) !== null) {
                $context = $this->requireContext($row);
                if (($context['pending_work']['kind'] ?? '') === 'terminal') {
                    $context['pending_work'] = null;
                    $this->saveContext($id, $context, $row['state']);
                }
            }
        });
    }

    private function row(int $id, bool $lock = false): array
    {
        $stmt = $this->db->prepare('SELECT * FROM command_interactions WHERE id=?' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) throw new UserError('Выбор не найден.');
        return $row;
    }

    private function handler(string $type): array
    {
        $handler = $this->registry[$type] ?? null;
        if (!is_array($handler) || !is_callable($handler['permission'] ?? null) || !is_callable($handler['execute'] ?? null)) {
            throw new UserError('Эта команда сейчас недоступна.');
        }
        return $handler;
    }

    private function sourceVersion(array $message, string $encoding = 'storage_v2'): string
    {
        return ChatManager::interactionSourceVersion($message, $encoding);
    }

    private function validBinding(array $row): bool
    {
        if (($row['context_json'] ?? null) !== null) {
            try { $this->guard($row, (int)$row['owner_id'], false, false); return $row['bot_message_id'] !== null && !$this->requireContext($row)['staged']; }
            catch (UserError $error) { return false; }
        }
        if ($row['bot_message_id'] === null) return false;
        $chat = new ChatManager();
        $source = $chat->getMessageById((int)$row['source_message_id']);
        $bot = $chat->getMessageById((int)$row['bot_message_id']);
        return $source && $bot && empty($source['deleted']) && empty($bot['deleted'])
            && (int)$source['user_id'] === (int)$row['owner_id'] && (int)$bot['user_id'] === (int)$row['bot_user_id']
            && hash_equals($row['source_version'], $this->sourceVersion($source, 'getter_read_v1'))
            && str_contains($bot['raw_message'] ?? '', '[[command:' . $row['id'] . ']]');
    }

    private function assertAllowed(int $userId): void
    {
        if ($userId < 1 || !(new UserManager())->getUserById($userId)) throw new UserError('Нужна авторизация.');
        (new ChatManager())->assertCanSend($userId);
    }
    /** Optional capability; no command-specific interpretation belongs in this owner. */
    public function continuationHandler(string $type): array
    {
        $capability = $this->handler($type)['continuation'] ?? null;
        foreach (['classify', 'prepare', 'resolve', 'format', 'permission'] as $name) {
            if (!is_array($capability) || !is_callable($capability[$name] ?? null)) {
                throw new UserError('Эта команда не поддерживает уточнение.');
            }
        }
        return $capability;
    }

    public function createContinuation(string $type, int $ownerId, int $sourceMessageId, array $options,
        array $handlerContext, string $initialState = 'pending'): int
    {
        $this->continuationHandler($type);
        if (!in_array($initialState, ['pending', 'clarifying'], true)) throw new RuntimeException('Invalid initial state');
        return $this->ownerLock($type, $ownerId, function () use ($type, $ownerId, $sourceMessageId, $options, $handlerContext, $initialState) {
            return Transaction::run($this->db, function () use ($type, $ownerId, $sourceMessageId, $options, $handlerContext, $initialState) {
                $stmt = $this->db->prepare('SELECT * FROM command_interactions WHERE type=? AND owner_id=? AND source_message_id=? FOR UPDATE');
                $stmt->bind_param('sii', $type, $ownerId, $sourceMessageId); $stmt->execute();
                $existing = $stmt->get_result()->fetch_assoc();
                $this->assertLatestAccepted($type, $ownerId, $sourceMessageId);
                if ($existing && empty($this->context($existing)['acceptance_only'])) return (int)$existing['id'];
                $this->assertAllowed($ownerId);
                $this->lockOwnerRows($type, $ownerId);
                $source = (new ChatManager())->lockInteractionMessages([array_filter(['id' => $sourceMessageId, 'user_id' => $ownerId, 'version' => $existing['source_version'] ?? null, 'version_encoding' => $existing ? $this->rowSourceEncoding($existing) : 'storage_v2'], static fn($v) => $v !== null)])[$sourceMessageId];
                $this->capPermission($type, $ownerId, $handlerContext);
                $options = $this->continuationOptions($options, $initialState);
                $id = $this->create($type, $ownerId, $sourceMessageId, $options);
                $row = $this->row($id, true);
                $this->setCanonicalRowSource($id, $source);
                $context = ['version' => 1, 'source_version_encoding' => 'storage_v2', 'root_id' => $id, 'parent_id' => null, 'root_expires_at' => $row['expires_at'],
                    'revision' => 0, 'handler_context' => $handlerContext,
                    'source_bindings' => [['id' => $sourceMessageId, 'user_id' => $ownerId, 'version' => $this->sourceVersion($source), 'version_encoding' => 'storage_v2']],
                    'operations' => [], 'pending_work' => null, 'child_id' => null, 'resolver_result' => null,
                    'question_bindings' => [], 'staged' => false];
                if ($existing) {
                    $context['acceptance_result'] = $this->requireContext($existing)['acceptance_result'];
                    $json = json_encode($options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                    $stmt = $this->db->prepare('UPDATE command_interactions SET options_json=? WHERE id=?');
                    $stmt->bind_param('si', $json, $id); $stmt->execute();
                }
                $this->saveContext($id, $context, $initialState);
                $this->supersedeLocked($type, $ownerId, $sourceMessageId, $id);
                return $id;
            });
        });
    }

    /** Private opaque initial resolution recovery; never projects handler metadata to HTTP. */
    public function getSourceHandlerContext(string $type, int $actorId, int $sourceMessageId, string $expectedSourceVersion): ?array
    {
        return $this->ownerLock($type, $actorId, fn() => Transaction::run($this->db, function () use ($type, $actorId, $sourceMessageId, $expectedSourceVersion) {
            $this->assertAllowed($actorId);
            $this->continuationHandler($type);
            $this->assertLatestAccepted($type, $actorId, $sourceMessageId);
            $binding = ['id' => $sourceMessageId, 'user_id' => $actorId, 'version' => $expectedSourceVersion, 'version_encoding' => 'storage_v2'];
            (new ChatManager())->lockInteractionMessages([$binding]);
            $stmt = $this->db->prepare('SELECT * FROM command_interactions WHERE type=? AND owner_id=? AND source_message_id=? FOR UPDATE');
            $stmt->bind_param('sii', $type, $actorId, $sourceMessageId); $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if (!$row) return null;
            $this->guard($row, $actorId, true, true, [$binding]);
            $context = $this->context($row);
            if (!empty($context['acceptance_only'])) return null;
            $this->assertInitialRecovery($row, $context);
            return ['interaction_id' => (int)$row['id'], 'handler_context' => $context['handler_context']];
        }));
    }

    private function assertInitialRecovery(array $row, ?array $context): void
    {
        if (!in_array($row['state'], ['pending', 'clarifying'], true)) throw new UserError('Выбор уже закрыт.');
        if ($context === null) throw new UserError('Исходный контекст не поддерживает восстановление.');
        if ($context['staged'] || $context['parent_id'] !== null || $context['revision'] !== 0) throw new UserError('Исходный поиск уже изменён.');
    }

    /** Quotes are authoritative; callers supply persisted quotes, never browser claimed quotes. */
    public function lookupTarget(int $actorId, array $quotedMessageIds, array $intent): array
    {
        $quotes = array_values(array_unique(array_map('intval', $quotedMessageIds)));
        $preflight = $this->targetPreflight($actorId, $quotes, $intent);
        if ($preflight !== null) return ['status' => $preflight];
        $targets = $this->collectTargets($actorId, $quotes, $intent);
        if ($targets === null) return ['status' => 'invalid'];
        return $this->targetResult($targets, (bool)$quotes);
    }

    private function targetPreflight(int $actorId, array $quotes, array $intent): ?string
    {
        if ($quotes) return $this->quotedTargetsOwnedBy($actorId, $quotes) ? null : 'invalid';
        return $this->addressedContinuationIntent($intent) ? null : 'none';
    }

    private function collectTargets(int $actorId, array $quotes, array $intent): ?array
    {
        $targets = [];
        foreach ($this->targetRows($actorId, $quotes) as $row) {
            if (!$this->compatibleTarget($row, $intent)) continue;
            try {
                $this->guard($row, $actorId, false);
                $targets[] = ['id' => (int)$row['id'], 'type' => $row['type'], 'state' => $row['state']];
            } catch (UserError $error) {
                if ($quotes) return null;
            }
        }
        return $targets;
    }

    private function targetResult(array $targets, bool $quoted): array
    {
        if (!$targets) return ['status' => $quoted ? 'invalid' : 'none'];
        if (count($targets) !== 1) return ['status' => 'ambiguous'];
        return ['status' => 'target', 'target' => $targets[0]];
    }

    private function quotedTargetsOwnedBy(int $actorId, array $quotes): bool
    {
        $matched = 0;
        foreach ($quotes as $id) {
            $stmt = $this->db->prepare("SELECT owner_id FROM command_interactions WHERE bot_message_id=? OR JSON_CONTAINS(context_json,JSON_OBJECT('id',?),'$.question_bindings')");
            $stmt->bind_param('ii', $id, $id); $stmt->execute();
            $bindings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            if (!$bindings) continue;
            foreach ($bindings as $binding) if ((int)$binding['owner_id'] !== $actorId) return false;
            $matched++;
        }
        return $matched === 1;
    }

    private function addressedContinuationIntent(array $intent): bool
    {
        return !empty($intent['addressesBot']) && in_array($intent['intent'] ?? '', ['cancel','refine','clarification','retry'], true);
    }

    private function targetRows(int $actorId, array $quotes): array
    {
        if (!$quotes) {
            $filter = " AND state IN ('pending','clarifying','resolving') AND expires_at>UTC_TIMESTAMP() AND JSON_UNQUOTE(JSON_EXTRACT(context_json,'$.staged'))='false'";
        } else {
            $ids = implode(',', $quotes); // Integers reconstructed from persisted server-side quotes.
            $conditions = ["bot_message_id IN ($ids)"];
            foreach ($quotes as $id) $conditions[] = "JSON_CONTAINS(context_json,JSON_OBJECT('id',$id),'$.question_bindings')";
            $filter = ' AND (' . implode(' OR ', $conditions) . ')';
        }
        $stmt = $this->db->prepare('SELECT * FROM command_interactions WHERE owner_id=? AND context_json IS NOT NULL' . $filter . ' ORDER BY id');
        $stmt->bind_param('i', $actorId); $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function compatibleTarget(array $row, array $intent): bool
    {
        if (!empty($intent['type']) && $intent['type'] !== $row['type']) return false;
        $context = $this->context($row);
        return in_array($row['state'], ['pending','clarifying','resolving'], true) && !$context['staged'];
    }

    public function requestRefinement(int $id, int $actorId, ?array $followupBinding = null): array
    {
        return $this->mutate($id, function ($row) use ($actorId, $followupBinding) {
            $this->guard($row, $actorId, true, true, $this->controlBindings($actorId, $followupBinding));
            if ($row['bot_message_id'] === null) throw new UserError('Предложение ещё не опубликовано.');
            $context = $this->requireContext($row);
            $key = 'refine:' . $row['id'];
            if (isset($context['operations'][$key])) return $context['operations'][$key];
            if ($row['state'] !== 'pending' || $context['staged']) throw new UserError('Выбор уже закрыт для уточнения.');
            $context = $this->appendControlSource($context, $actorId, $followupBinding);
            $context['revision']++;
            $result = $this->operation($row, $context, $key, 'choice_clarifying');
            $context['operations'][$key] = $result;
            $context['pending_work'] = $this->newWork($context, $key, 'prompt');
            $this->saveContext((int)$row['id'], $context, 'clarifying');
            return $this->persistedOperation((int)$row['id'], $key);
        });
    }

    public function submitClarification(int $id, int $actorId, int $messageId, array $parsedInput): array
    {
        return $this->mutate($id, function ($row) use ($actorId, $messageId, $parsedInput) {
            $binding = array_filter(['id' => $messageId, 'user_id' => $actorId, 'version' => $parsedInput['source_version'] ?? null, 'version_encoding' => $parsedInput['source_version_encoding'] ?? 'storage_v2'], static fn($value) => $value !== null);
            $this->guard($row, $actorId, true, true, [$binding]);
            if ($row['bot_message_id'] === null) throw new UserError('Предложение ещё не опубликовано.');
            $context = $this->requireContext($row);
            $source = (new ChatManager())->lockInteractionMessages([$binding])[$messageId];
            $version = $this->sourceVersion($source);
            $saved = $this->acceptedInputOperation($context, $messageId);
            if ($saved !== null) return $saved;
            $key = 'input:' . $messageId . ':' . $version;
            if (isset($context['operations'][$key])) return $context['operations'][$key];
            if (!in_array($row['state'], ['pending', 'clarifying'], true) || $context['staged']) throw new UserError('Поиск уже выполняется или выбор закрыт.');
            if (!in_array($parsedInput['intent'] ?? '', ['refine', 'clarification', 'retry'], true)) throw new UserError('Нужно явное уточнение.');
            $inputs = array_filter(array_keys($context['operations']), static fn($key) => str_starts_with($key, 'input:'));
            if (count($inputs) >= 8 || count($context['source_bindings']) >= 9) throw new UserError('Лимит уточнений достигнут. Повтори команду.');
            $handler = $this->continuationHandler($row['type']);
            $prepared = ($handler['prepare'])($context['handler_context'], $parsedInput);
            if (!is_array($prepared)) throw new RuntimeException('Invalid prepared context');
            $this->capPermission($row['type'], $actorId, $prepared);
            $context['handler_context'] = $prepared;
            $context['source_bindings'][] = ['id' => $messageId, 'user_id' => $actorId, 'version' => $version, 'version_encoding' => 'storage_v2'];
            $context['revision']++;
            $context['resolver_result'] = null;
            $context['child_id'] = null;
            $context['pending_work'] = $this->newWork($context, $key, 'resolve');
            $context['operations'][$key] = $this->operation($row, $context, $key, 'choice_resolving');
            $this->saveContext((int)$row['id'], $context, 'resolving');
            return $this->persistedOperation((int)$row['id'], $key);
        });
    }

    public function cancelActive(int $id, int $actorId, ?array $followupBinding = null): array
    {
        return $this->mutate($id, function ($row) use ($actorId, $followupBinding) {
            $this->guard($row, $actorId, true, true, $this->controlBindings($actorId, $followupBinding));
            if ($row['bot_message_id'] === null) throw new UserError('Предложение ещё не опубликовано.');
            if ($row['outcome_json'] !== null) return json_decode($row['outcome_json'], true, 512, JSON_THROW_ON_ERROR);
            $context = $this->requireContext($row);
            if (!in_array($row['state'], ['pending', 'clarifying', 'resolving'], true) || $context['staged']) throw new UserError('Выбор уже закрыт.');
            if ($followupBinding !== null) $context['operations']['cancel_source'] = ['source_binding' => $this->controlBindings($actorId, $followupBinding)[0]];
            if ($context['child_id']) $this->invalidateChild((int)$context['child_id'], 'cancelled');
            $context['revision']++;
            $context['pending_work'] = $this->newWork($context, 'terminal:' . $row['id'], 'terminal');
            $this->saveContext((int)$row['id'], $context, 'cancelled');
            $outcome = ['status' => 'cancelled', 'code' => 'choice_cancelled', 'facts' => []];
            $this->saveOutcome((int)$row['id'], 'cancel', $outcome, 'cancelled');
            return json_decode($this->row((int)$row['id'])['outcome_json'], true, 512, JSON_THROW_ON_ERROR);
        });
    }

    /** Call only after accepting a complete enabled new command. Replay older sources cannot close newer ones. */
    public function supersedeOwn(string $type, int $actorId, int $newSource): void
    {
        $this->ownerLock($type, $actorId, function () use ($type, $actorId, $newSource) {
            Transaction::run($this->db, function () use ($type, $actorId, $newSource) {
                $this->assertAllowed($actorId);
                (new ChatManager())->lockInteractionMessages([['id' => $newSource, 'user_id' => $actorId]]);
                $this->continuationHandler($type);
                $this->supersedeLocked($type, $actorId, $newSource);
            });
        });
    }

    public function listRecoverableWork(int $limit = 20): array
    {
        $limit = max(1, min(20, $limit));
        $stmt = $this->db->prepare("SELECT * FROM command_interactions WHERE context_json IS NOT NULL
            AND ((state IN ('clarifying','resolving') AND expires_at>UTC_TIMESTAMP())
                OR (state IN ('consumed','cancelled') AND outcome_json IS NOT NULL AND result_message_id IS NULL
                    AND JSON_UNQUOTE(JSON_EXTRACT(context_json,'$.pending_work.kind'))='terminal'
                    AND JSON_EXTRACT(context_json,'$.pending_work.delivery_expires_at')>UNIX_TIMESTAMP()))
            AND JSON_TYPE(JSON_EXTRACT(context_json,'$.pending_work'))='OBJECT'
            AND JSON_UNQUOTE(JSON_EXTRACT(context_json,'$.staged'))='false'
            AND COALESCE(JSON_EXTRACT(context_json,'$.pending_work.lease_until'),0)<=UNIX_TIMESTAMP()
            AND COALESCE(JSON_EXTRACT(context_json,'$.pending_work.available_at'),0)<=UNIX_TIMESTAMP()
            ORDER BY COALESCE(JSON_UNQUOTE(JSON_EXTRACT(context_json,'$.pending_work.scanned_at')),'0000000000000000'),id LIMIT ?");
        $stmt->bind_param('i', $limit); $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $result = [];
        foreach ($rows as $candidate) {
            // Scheduling metadata is private and may advance even if an actor/source is temporarily unavailable.
            // Mark before returning so invalid-source rows cannot monopolize each fresh worker's first page.
            $item = $this->mutate((int)$candidate['id'], function ($row) {
                $context = $this->context($row); $work = $context['pending_work'] ?? null;
                if (!$context || !$work || !$this->workMatches($row, $context, $context['revision'], $work['operation_key'])
                    || ($work['lease_until'] ?? 0) > time() || ($work['available_at'] ?? 0) > time()) return null;
                $context['pending_work']['scanned_at'] = sprintf('%016d', (int)(microtime(true) * 1000000));
                $this->saveContext((int)$row['id'], $context, $row['state']);
                return ['interaction_id' => (int)$row['id'], 'revision' => $context['revision'], 'operation_key' => $work['operation_key'], 'user_id' => (int)$row['owner_id']];
            });
            if ($item !== null) $result[] = $item;
        }
        return $result;
    }

    public function claimWork(int $id, int $revision, string $operationKey): ?array
    {
        return $this->mutate($id, function ($row) use ($revision, $operationKey) {
            $context = $this->requireContext($row); $work = $context['pending_work'];
            $this->guard($row, (int)$row['owner_id'], true, ($work['kind'] ?? '') !== 'terminal');
            if (!$this->workMatches($row, $context, $revision, $operationKey) || ($work['lease_until'] ?? 0) > time() || ($work['available_at'] ?? 0) > time()) return null;
            if (($work['kind'] === 'resolve' && !$context['resolver_result'] && $work['attempts'] >= 3) || $work['delivery_attempts'] >= 3) {
                if ($work['kind'] === 'terminal') {
                    $context['pending_work'] = null; $this->saveContext((int)$row['id'], $context, $row['state']); return null;
                }
                if ($context['child_id']) $this->invalidateChild((int)$context['child_id'], 'cancelled');
                $context['child_id'] = null; $context['revision']++;
                $context['resolver_result'] = ['status' => 'error', 'code' => 'search_failed', 'revision' => $context['revision']];
                $context['pending_work'] = $work['kind'] === 'prompt' ? null : $this->newWork($context, 'failure:' . $context['revision'], 'prompt');
                $this->saveContext((int)$row['id'], $context, 'clarifying');
                return null;
            }
            $work['lease_token'] = bin2hex(random_bytes(16));
            $work['lease_until'] = time() + 90;
            if ($work['kind'] === 'resolve' && !$context['resolver_result']) $work['attempts']++;
            $work['delivery_attempts'] = ($work['delivery_attempts'] ?? 0) + 1;
            $context['pending_work'] = $work;
            $this->saveContext((int)$row['id'], $context, $row['state']);
            return ['interaction_id' => (int)$row['id'], 'type' => $row['type'], 'owner_id' => (int)$row['owner_id'], 'state' => $row['state'],
                'source_message_id' => (int)$row['source_message_id'], 'bot_message_id' => (int)$row['bot_message_id'],
                'revision' => $revision, 'operation_key' => $operationKey, 'lease_token' => $work['lease_token'],
                'context' => $context, 'work' => $work,
                'outcome' => $row['outcome_json'] === null ? null : json_decode($row['outcome_json'], true, 512, JSON_THROW_ON_ERROR)];
        });
    }

    public function saveVerifiedResult(int $id, int $revision, string $operationKey, string $token, array $result): bool
    {
        return $this->mutate($id, function ($row) use ($revision, $operationKey, $token, $result) {
            $this->guard($row, (int)$row['owner_id']);
            $context = $this->requireContext($row);
            if (($context['pending_work']['kind'] ?? '') !== 'resolve' || !$this->leaseMatches($row, $context, $revision, $operationKey, $token)) return false;
            if ($context['resolver_result'] !== null) return true;
            if (!in_array($result['status'] ?? '', ['candidates', 'empty', 'error'], true)
                || !is_array($result['options'] ?? []) || count($result['options'] ?? []) > 6) throw new RuntimeException('Invalid verified resolver result');
            if (($result['status'] ?? '') === 'candidates') $this->continuationOptions($result['options'], 'pending');
            $context = $this->boundedVerifiedContext($context, $result, $revision);
            $this->saveContext((int)$row['id'], $context, $row['state']);
            return true;
        });
    }

    /** Durable resolver read; publication must use the persisted result after envelope preflight. */
    public function getVerifiedWorkResult(int $id, int $revision, string $operationKey, string $token): ?array
    {
        return $this->mutate($id, function ($row) use ($revision, $operationKey, $token) {
            $this->guard($row, (int)$row['owner_id']);
            $context = $this->requireContext($row);
            if (($context['pending_work']['kind'] ?? '') !== 'resolve' || !$this->leaseMatches($row, $context, $revision, $operationKey, $token)) return null;
            return $context['resolver_result'];
        });
    }

    /** Reserve 1024 bytes for lineage IDs and one bounded proposal/question binding. */
    private function boundedVerifiedContext(array $context, array $result, int $revision): array
    {
        $fallback = $result['overflow_result'] ?? ['status' => 'empty', 'options' => [], 'code' => 'context_overflow', 'facts' => []];
        $baseHandlerContext = $context['handler_context'];
        $updates = $result['handler_context_updates'] ?? [];
        if (!is_array($updates)) throw new RuntimeException('Invalid resolver handler context updates');
        $context['handler_context'] = array_replace($baseHandlerContext, $updates);
        unset($result['overflow_result'], $result['handler_context_updates']);
        $result['revision'] = $revision;
        $result['source_bindings'] = $context['source_bindings'];
        $context['resolver_result'] = $result;
        if (strlen(json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) <= 31744) return $context;
        if (!is_array($fallback) || ($fallback['status'] ?? '') !== 'empty' || ($fallback['options'] ?? null) !== []
            || strlen(json_encode($fallback, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) > 2048) throw new RuntimeException('Invalid resolver overflow result');
        $fallbackUpdates = $fallback['handler_context_updates'] ?? [];
        if (!is_array($fallbackUpdates)) throw new RuntimeException('Invalid overflow handler context updates');
        $context['handler_context'] = array_replace($baseHandlerContext, $fallbackUpdates);
        unset($fallback['handler_context_updates']);
        $fallback['revision'] = $revision;
        $fallback['source_bindings'] = $context['source_bindings'];
        $context['resolver_result'] = $fallback;
        if (strlen(json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) > 31744) throw new UserError('Контекст выбора переполнен. Начни новую команду.');
        return $context;
    }

    /** Saved verified candidates only. Staged child remains unbound and never actionable. */
    public function stageChild(int $id, int $revision, string $operationKey, string $token): ?int
    {
        return $this->mutate($id, function ($row) use ($revision, $operationKey, $token) {
            $this->guard($row, (int)$row['owner_id']);
            $context = $this->requireContext($row);
            if (!$this->leaseMatches($row, $context, $revision, $operationKey, $token)) return null;
            if ($context['child_id']) return (int)$context['child_id'];
            if (($context['resolver_result']['status'] ?? '') !== 'candidates') throw new RuntimeException('No verified candidates');
            $binding = end($context['source_bindings']);
            $options = $this->continuationOptions($context['resolver_result']['options'], 'pending');
            $json = json_encode($options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $state = 'resolving'; $expiry = $context['root_expires_at'];
            $source = (int)$binding['id']; $version = $binding['version']; $owner = (int)$row['owner_id']; $type = $row['type'];
            $stmt = $this->db->prepare('INSERT INTO command_interactions (type,owner_id,source_message_id,source_version,options_json,expires_at,state) VALUES (?,?,?,?,?,?,?)');
            $stmt->bind_param('siissss', $type, $owner, $source, $version, $json, $expiry, $state); $stmt->execute();
            $childId = (int)$stmt->insert_id;
            $child = $context;
            if (isset($binding['version_encoding'])) $child['source_version_encoding'] = $binding['version_encoding'];
            $child['parent_id'] = (int)$row['id']; $child['child_id'] = null; $child['pending_work'] = null; $child['staged'] = true; $child['question_bindings'] = []; unset($child['proposal_binding']);
            $this->saveContext($childId, $child, 'resolving');
            $context['child_id'] = $childId;
            $this->saveContext((int)$row['id'], $context, 'resolving');
            return $childId;
        });
    }

    public function bindAndActivateChild(int $id, int $revision, string $operationKey, string $token, int $messageId): bool
    {
        return $this->mutate($id, function ($row) use ($id, $revision, $operationKey, $token, $messageId) {
            $this->guard($row, (int)$row['owner_id']);
            $context = $this->requireContext($row);
            if (!$this->leaseMatches($row, $context, $revision, $operationKey, $token)) return false;
            if (!$context['child_id']) throw new RuntimeException('No staged child');
            $child = $this->row((int)$context['child_id'], true); $childContext = $this->requireContext($child);
            if (!$childContext['staged'] || $child['state'] !== 'resolving') return false;
            $this->bindMessage((int)$child['id'], $messageId);
            $childContext = $this->requireContext($this->row((int)$child['id']));
            $childContext['staged'] = false; $childContext['resolver_result'] = null;
            $this->saveContext((int)$child['id'], $childContext, 'pending');
            $context['pending_work'] = null;
            $this->saveContext((int)$row['id'], $context, 'superseded');
            return true;
        });
    }

    public function bindQuestion(int $id, int $revision, string $operationKey, string $token, int $messageId): bool
    {
        return $this->mutate($id, function ($row) use ($id, $revision, $operationKey, $token, $messageId) {
            $this->guard($row, (int)$row['owner_id']);
            $context = $this->requireContext($row);
            if (!$this->leaseMatches($row, $context, $revision, $operationKey, $token) || ($context['pending_work']['kind'] ?? '') === 'terminal') return false;
            $botId = (int)ConfigManager::getInstance()->getOption('ai_bot_user_id', 0);
            $marker = $this->deliveryMarker($id, $revision, $operationKey);
            $messages = (new ChatManager())->lockInteractionMessages([['id' => $messageId, 'user_id' => $botId, 'marker' => $marker]]);
            foreach ($context['question_bindings'] as $binding) if ($binding['id'] === $messageId) return true;
            $context['question_bindings'][] = ['id' => $messageId, 'user_id' => $botId, 'version' => $this->sourceVersion($messages[$messageId]), 'version_encoding' => 'storage_v2', 'marker' => $marker];
            $context['pending_work'] = null;
            $this->saveContext($id, $context, 'clarifying');
            return true;
        });
    }

    /** Release lease after recoverable failure. No external search after a verified result. */
    public function completeWork(int $id, int $revision, string $operationKey, string $token, bool $success = true): bool
    {
        return $this->mutate($id, function ($row) use ($id, $revision, $operationKey, $token, $success) {
            $context = $this->requireContext($row);
            $this->guard($row, (int)$row['owner_id'], true, ($context['pending_work']['kind'] ?? '') !== 'terminal');
            if (!$this->leaseMatches($row, $context, $revision, $operationKey, $token)) return false;
            $state = $row['state'];
            if ($success) $context['pending_work'] = null;
            else {
                $work = $context['pending_work'];
                $exhausted = (!$context['resolver_result'] && $work['attempts'] >= 3) || $work['delivery_attempts'] >= 3;
                if ($exhausted && $work['kind'] === 'terminal') {
                    $context['pending_work'] = null;
                } elseif ($exhausted) {
                    if ($context['child_id']) $this->invalidateChild((int)$context['child_id'], 'cancelled');
                    $context['child_id'] = null;
                    $context['revision']++;
                    $context['resolver_result'] = ['status' => 'error', 'code' => 'search_failed', 'revision' => $context['revision']];
                    $context['pending_work'] = $this->newWork($context, 'failure:' . $context['revision'], 'prompt');
                    $state = 'clarifying';
                    if ($work['kind'] === 'prompt') $context['pending_work'] = null;
                } else {
                    $work['lease_token'] = null; $work['lease_until'] = 0;
                    $work['available_at'] = time() + [5,15,30][min(2, max(0, $work['attempts'] - 1))];
                    $context['pending_work'] = $work;
                }
            }
            $this->saveContext($id, $context, $state);
            return true;
        });
    }

    public function deliveryMarker(int $id, int $revision, string $operationKey): string
    {
        return '[[command-delivery:work_' . $id . '_' . $revision . '_' . substr(hash('sha256', $operationKey), 0, 16) . ']]';
    }

    /** Guard and insert/bind are one short DB transaction; caller generated text outside locks. */
    public function publishWork(int $id, int $revision, string $operationKey, string $token,
        string $text, bool $child = false): ?int
    {
        $initial = $this->row($id);
        $published = $this->ownerLock($initial['type'], (int)$initial['owner_id'], function () use ($id, $revision, $operationKey, $token, $text, $child) {
            $botId = (int)ConfigManager::getInstance()->getOption('ai_bot_user_id', 0);
            $chat = new ChatManager();
            $sourceId = (int)$this->row($id)['source_message_id'];
            $existing = $chat->findBotReplyTo($sourceId, $this->deliveryMarker($id, $revision, $operationKey));
            if ($existing) {
                $ok = $child ? $this->bindAndActivateChild($id, $revision, $operationKey, $token, (int)$existing['id'])
                    : $this->bindQuestion($id, $revision, $operationKey, $token, (int)$existing['id']);
                return $ok ? (int)$existing['id'] : null;
            }
            try {
                return $chat->addInteractionMessageGuarded($botId, 'Лира', $text . ' ' . $this->deliveryMarker($id, $revision, $operationKey), [$sourceId],
                    function () use ($id, $revision, $operationKey, $token) {
                        $row = $this->row($id, true); $this->guard($row, (int)$row['owner_id']);
                        if (!$this->leaseMatches($row, $this->requireContext($row), $revision, $operationKey, $token)) throw new UserError('Результат уже неактуален.');
                    },
                    function ($messageId) use ($id, $revision, $operationKey, $token, $child) {
                        $ok = $child ? $this->bindAndActivateChild($id, $revision, $operationKey, $token, $messageId)
                            : $this->bindQuestion($id, $revision, $operationKey, $token, $messageId);
                        if (!$ok) throw new UserError('Результат уже неактуален.');
                    }, true);
            } catch (UserError $error) { return null; }
        });
        if ($published !== null) (new ChatManager())->publishInteractionMessage($published);
        return $published;
    }

    /** Durable command acceptance precedes external work. Callback is local DB work only. */
    public function acceptNewCommand(string $type, int $actorId, int $sourceId, callable $acceptance, ?string $expectedSourceVersion = null): mixed
    {
        return $this->ownerLock($type, $actorId, fn() => Transaction::run($this->db, function () use ($type, $actorId, $sourceId, $acceptance, $expectedSourceVersion) {
            $this->assertAllowed($actorId);
            $this->continuationHandler($type);
            $this->lockOwnerRows($type, $actorId);
            $this->assertLatestAccepted($type, $actorId, $sourceId);
            $stmt = $this->db->prepare('SELECT * FROM command_interactions WHERE type=? AND owner_id=? AND source_message_id=? FOR UPDATE');
            $stmt->bind_param('sii', $type, $actorId, $sourceId); $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            if ($existing) {
                $this->guard($existing, $actorId, true, true, [array_filter(['id' => $sourceId, 'user_id' => $actorId, 'version' => $expectedSourceVersion], static fn($value) => $value !== null)]);
                $context = $this->requireContext($existing);
                if (array_key_exists('acceptance_result', $context)) return $context['acceptance_result'];
                throw new UserError('Команда уже обработана.');
            }
            $source = (new ChatManager())->lockInteractionMessages([array_filter(['id' => $sourceId, 'user_id' => $actorId, 'version' => $expectedSourceVersion], static fn($value) => $value !== null)])[$sourceId];
            $result = $acceptance();
            if ($result === false) return false;
            $id = $this->create($type, $actorId, $sourceId, [['key' => 'cancel', 'label' => 'Передумал', 'payload' => []]]);
            $row = $this->row($id, true);
            $this->setCanonicalRowSource($id, $source);
            $context = ['version' => 1, 'source_version_encoding' => 'storage_v2', 'root_id' => $id, 'parent_id' => null, 'root_expires_at' => $row['expires_at'],
                'revision' => 0, 'handler_context' => [], 'source_bindings' => [['id' => $sourceId, 'user_id' => $actorId, 'version' => $this->sourceVersion($source), 'version_encoding' => 'storage_v2']],
                'operations' => [], 'pending_work' => null, 'child_id' => null, 'resolver_result' => null,
                'question_bindings' => [], 'staged' => false, 'acceptance_only' => true, 'acceptance_result' => $result];
            $this->saveContext($id, $context, 'consumed');
            $this->supersedeLocked($type, $actorId, $sourceId, $id);
            return $this->requireContext($this->row($id))['acceptance_result'];
        }));
    }

    private function assertLatestAccepted(string $type, int $actor, int $source): void
    {
        $stmt = $this->db->prepare("SELECT id FROM command_interactions WHERE type=? AND owner_id=? AND source_message_id>? AND context_json IS NOT NULL AND JSON_EXTRACT(context_json,'$.parent_id') = CAST('null' AS JSON) ORDER BY id LIMIT 1");
        $stmt->bind_param('sii', $type, $actor, $source); $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) throw new UserError('Поиск заменён более новой командой.');
    }

    /** Initial proposal text is prepared outside locks; guard, insert and immutable bind are atomic. */
    public function publishProposal(int $id, string $text, ?string $deliveryKey = null): ?int
    {
        $initial = $this->row($id);
        $sourceId = (int)$initial['source_message_id'];
        $marker = '[[command-delivery:' . ($deliveryKey ?? 'source_' . $sourceId) . ']]';
        if (!preg_match('/^\[\[command-delivery:(?:source_\d+|work_[a-zA-Z0-9_-]+)\]\]$/D', $marker)) throw new RuntimeException('Invalid proposal delivery marker');
        $published = $this->ownerLock($initial['type'], (int)$initial['owner_id'], function () use ($id, $text, $sourceId, $marker) {
            $chat = new ChatManager();
            $existing = $chat->findBotReplyTo($sourceId, $marker);
            if ($existing) {
                $this->bindMessage($id, (int)$existing['id']);
                return (int)$existing['id'];
            }
            $botId = (int)ConfigManager::getInstance()->getOption('ai_bot_user_id', 0);
            try {
                return $chat->addInteractionMessageGuarded($botId, 'Лира', $text . ' ' . $marker, [$sourceId],
                    function () use ($id) {
                        $row = $this->row($id, true);
                        $this->guard($row, (int)$row['owner_id']);
                        $context = $this->requireContext($row);
                        if (!in_array($row['state'], ['pending', 'clarifying'], true) || $context['staged'] || $row['bot_message_id'] !== null) throw new UserError('Предложение уже неактуально.');
                        $rootSource = (int)$context['source_bindings'][0]['id'];
                        $this->assertLatestAccepted($row['type'], (int)$row['owner_id'], $rootSource);
                    },
                    function ($messageId) use ($id) { $this->bindMessage($id, $messageId); }, true);
            } catch (UserError $error) { return null; }
        });
        if ($published !== null) (new ChatManager())->publishInteractionMessage($published);
        return $published;
    }

    /** Terminal effect already committed; source guards still govern reply publication, without a new TTL effect. */
    public function publishResult(int $id, int $actorId, string $text, ?array $expectedWork = null): ?int
    {
        $initial = $this->row($id);
        if ((int)$initial['owner_id'] !== $actorId) throw new UserError('Этот выбор принадлежит другому пользователю.');
        $sourceId = (int)$initial['source_message_id'];
        $marker = '[[command-delivery:interaction_' . $id . ']]';
        $published = $this->ownerLock($initial['type'], $actorId, function () use ($id, $actorId, $text, $sourceId, $marker, $expectedWork) {
            $chat = new ChatManager();
            try {
                $existing = $chat->findBotReplyTo($sourceId, $marker);
                if ($existing) {
                    return $this->mutate($id, function ($row) use ($id, $actorId, $existing, $marker, $expectedWork) {
                        $this->guardTerminalPublication($row, $actorId, $expectedWork);
                        if ($row['outcome_json'] === null) throw new UserError('Результат ещё не сохранён.');
                        (new ChatManager())->lockInteractionMessages([['id' => (int)$existing['id'], 'user_id' => (int)$row['bot_user_id'], 'marker' => $marker]]);
                        $this->bindResultMessage($id, (int)$existing['id']);
                        return (int)$existing['id'];
                    });
                }
                $botId = (int)ConfigManager::getInstance()->getOption('ai_bot_user_id', 0);
                return $chat->addInteractionMessageGuarded($botId, 'Лира', $text . ' ' . $marker, [$sourceId],
                    function () use ($id, $actorId, $botId, $expectedWork) {
                        $row = $this->row($id, true);
                        $this->guardTerminalPublication($row, $actorId, $expectedWork);
                        if ($row['outcome_json'] === null || $row['result_message_id'] !== null || (int)$row['bot_user_id'] !== $botId) throw new UserError('Результат уже недоступен для публикации.');
                    },
                    function ($messageId) use ($id) { $this->bindResultMessage($id, $messageId); }, true);
            } catch (UserError $error) { return null; }
        });
        if ($published !== null) (new ChatManager())->publishInteractionMessage($published);
        return $published;
    }

    public function publishTerminalWork(int $id, int $revision, string $operationKey, string $token, string $text): ?int
    {
        $row = $this->row($id);
        return $this->publishResult($id, (int)$row['owner_id'], $text, ['revision' => $revision, 'operation_key' => $operationKey, 'lease_token' => $token]);
    }

    private function guardTerminalPublication(array $row, int $actor, ?array $expected): void
    {
        $this->guard($row, $actor, true, false);
        if ($expected === null) return;
        $context = $this->requireContext($row);
        if (($context['pending_work']['kind'] ?? '') !== 'terminal'
            || !$this->leaseMatches($row, $context, $expected['revision'], $expected['operation_key'], $expected['lease_token'])) throw new UserError('Ответ уже неактуален.');
    }

    private function controlBindings(int $actorId, ?array $binding): array
    {
        if ($binding === null) return [];
        if (!is_int($binding['id'] ?? null) || $binding['id'] < 1 || !preg_match('/^[a-f0-9]{64}$/D', $binding['version'] ?? '')) throw new RuntimeException('Invalid control source binding');
        return [['id' => $binding['id'], 'user_id' => $actorId, 'version' => $binding['version'], 'version_encoding' => $binding['version_encoding'] ?? 'storage_v2']];
    }

    private function appendControlSource(array $context, int $actorId, ?array $binding): array
    {
        if ($binding === null) return $context;
        foreach ($context['source_bindings'] as $existing) if ($existing['id'] === $binding['id']) return $context;
        if (count($context['source_bindings']) >= 9) throw new UserError('Лимит уточнений достигнут. Повтори команду.');
        $context['source_bindings'][] = $this->controlBindings($actorId, $binding)[0];
        return $context;
    }

    private function context(array $row): ?array
    {
        if (($row['context_json'] ?? null) === null) return null;
        $context = json_decode($row['context_json'], true, 512, JSON_THROW_ON_ERROR);
        $this->validateContext($context);
        return $context;
    }

    private function requireContext(array $row): array
    {
        return $this->context($row) ?? throw new UserError('Этот выбор не поддерживает уточнение.');
    }

    private function validateContext(array $context): void
    {
        if (($context['version'] ?? null) !== 1 || !is_int($context['root_id'] ?? null) || $context['root_id'] < 1
            || !is_int($context['revision'] ?? null) || $context['revision'] < 0
            || !is_array($context['handler_context'] ?? null) || !is_array($context['source_bindings'] ?? null)
            || count($context['source_bindings']) < 1 || count($context['source_bindings']) > 9
            || !is_array($context['operations'] ?? null) || count($context['operations']) > 24
            || !is_array($context['question_bindings'] ?? null) || count($context['question_bindings']) > 10
            || !is_bool($context['staged'] ?? null) || !is_string($context['root_expires_at'] ?? null)
            || strlen(json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) > 32768) throw new RuntimeException('Invalid continuation envelope');
        if (isset($context['source_version_encoding']) && $context['source_version_encoding'] !== 'storage_v2') throw new RuntimeException('Invalid source version encoding');
        foreach (['parent_id', 'child_id'] as $key) if (($context[$key] ?? null) !== null && (!is_int($context[$key]) || $context[$key] < 1)) throw new RuntimeException('Invalid lineage');
        if (($context['pending_work'] ?? null) !== null) {
            $work = $context['pending_work'];
            if (!is_array($work) || ($work['revision'] ?? null) !== $context['revision']
                || !is_string($work['operation_key'] ?? null) || strlen($work['operation_key']) > 160
                || !in_array($work['kind'] ?? '', ['prompt', 'resolve', 'terminal'], true)
                || !is_int($work['attempts'] ?? null) || $work['attempts'] < 0 || $work['attempts'] > 3
                || !is_int($work['delivery_attempts'] ?? null) || $work['delivery_attempts'] < 0 || $work['delivery_attempts'] > 3
                || !is_int($work['lease_until'] ?? null) || !is_int($work['available_at'] ?? null)
                || !is_int($work['delivery_expires_at'] ?? null)
                || !preg_match('/^[0-9]{16}$/D', $work['scanned_at'] ?? '')
                || ($work['lease_token'] !== null && !preg_match('/^[a-f0-9]{32}$/D', $work['lease_token']))) throw new RuntimeException('Invalid pending work');
        }
        foreach (array_merge($context['source_bindings'], $context['question_bindings'], isset($context['proposal_binding']) ? [$context['proposal_binding']] : [], isset($context['operations']['cancel_source']['source_binding']) ? [$context['operations']['cancel_source']['source_binding']] : []) as $binding) {
            if (isset($binding['version_encoding']) && $binding['version_encoding'] !== 'storage_v2') throw new RuntimeException('Invalid source binding encoding');
            if (!is_int($binding['id'] ?? null) || $binding['id'] < 1 || !is_int($binding['user_id'] ?? null)
                || !preg_match('/^[a-f0-9]{64}$/D', $binding['version'] ?? '')) throw new RuntimeException('Invalid source binding');
        }
    }

    private function saveContext(int $id, array $context, string $state): void
    {
        $this->validateContext($context);
        $json = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $stmt = $this->db->prepare('UPDATE command_interactions SET context_json=?,state=? WHERE id=?');
        $stmt->bind_param('ssi', $json, $state, $id); $stmt->execute();
    }

    private function capPermission(string $type, int $actor, array $context): void
    {
        if (($this->continuationHandler($type)['permission'])($actor, $context) === false) throw new UserError('Действие недоступно.');
    }

    private function guard(array $row, int $actor, bool $lock = true, bool $checkExpiry = true, array $extraBindings = []): void
    {
        if ((int)$row['owner_id'] !== $actor) throw new UserError('Этот выбор принадлежит другому пользователю.');
        $this->assertAllowed($actor);
        if ($checkExpiry && strtotime($row['expires_at'] . ' UTC') <= time()) throw new UserError('Срок выбора истёк. Повтори команду.');
        $context = $this->context($row);
        if ($context) $this->capPermission($row['type'], $actor, $context['handler_context']);
        $bindings = $context ? $this->contextSourceBindings($context) : [['id' => (int)$row['source_message_id'], 'user_id' => $actor, 'version' => $row['source_version'], 'version_encoding' => 'getter_read_v1']];
        if ($row['bot_message_id'] !== null) $bindings[] = isset($context['proposal_binding']) ? $this->historicalBinding($context['proposal_binding'], 'lock_decoded_v1') : ['id' => (int)$row['bot_message_id'], 'user_id' => (int)$row['bot_user_id'], 'marker' => '[[command:' . $row['id'] . ']]'];
        $questions = array_map(fn($binding) => $this->historicalBinding($binding, 'lock_decoded_v1'), $context['question_bindings'] ?? []);
        $cancel = isset($context['operations']['cancel_source']['source_binding']) ? [$this->historicalBinding($context['operations']['cancel_source']['source_binding'], 'getter_read_v1')] : [];
        $bindings = array_merge($bindings, $questions, $cancel, $extraBindings);
        if ($lock) (new ChatManager())->lockInteractionMessages($bindings);
        else {
            foreach ($bindings as $binding) {
                $message = (new ChatManager())->getMessageById($binding['id']);
                if (!$message || !empty($message['deleted']) || (int)$message['user_id'] !== $binding['user_id']
                    || isset($binding['version']) && !hash_equals($binding['version'], $this->sourceVersion($message, $binding['version_encoding'] ?? 'storage_v2'))
                    || isset($binding['marker']) && !str_contains($message['raw_message'] ?? '', $binding['marker'])) throw new UserError('Исходная команда недоступна.');
            }
        }
    }

    private function ownerLock(string $type, int $owner, callable $work): mixed
    {
        $name = 'ci_' . substr(hash('sha256', $owner . ':' . $type), 0, 56);
        $stmt = $this->db->prepare('SELECT GET_LOCK(?,2) AS acquired'); $stmt->bind_param('s', $name); $stmt->execute();
        if ((int)$stmt->get_result()->fetch_assoc()['acquired'] !== 1) throw new UserError('Выбор занят. Повтори действие.');
        try { return $work(); }
        finally { $stmt = $this->db->prepare('SELECT RELEASE_LOCK(?)'); $stmt->bind_param('s', $name); $stmt->execute(); }
    }

    private function mutate(int $id, callable $work): mixed
    {
        $initial = $this->row($id);
        return $this->ownerLock($initial['type'], (int)$initial['owner_id'], fn() => Transaction::run($this->db, function () use ($id, $work, $initial) {
            $stmt = $this->db->prepare('SELECT id FROM command_interactions WHERE owner_id=? AND type=? ORDER BY id FOR UPDATE');
            $owner = (int)$initial['owner_id']; $type = $initial['type']; $stmt->bind_param('is', $owner, $type); $stmt->execute(); $stmt->get_result()->fetch_all();
            return $work($this->row($id, true));
        }));
    }

    private function lockOwnerRows(string $type, int $owner): void
    {
        $stmt = $this->db->prepare('SELECT id FROM command_interactions WHERE owner_id=? AND type=? ORDER BY id FOR UPDATE');
        $stmt->bind_param('is', $owner, $type); $stmt->execute(); $stmt->get_result()->fetch_all();
    }

    private function supersedeLocked(string $type, int $actor, int $source, int $except = 0): void
    {
        $stmt = $this->db->prepare("SELECT * FROM command_interactions WHERE owner_id=? AND type=? ORDER BY id FOR UPDATE");
        $stmt->bind_param('is', $actor, $type); $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $context = $this->context($row);
            $rootSource = (int)($context['source_bindings'][0]['id'] ?? $row['source_message_id']);
            if ((int)$row['id'] === $except || $rootSource >= $source || !in_array($row['state'], ['pending', 'clarifying', 'resolving'], true)) continue;
            if ($context) { $context['revision']++; $context['pending_work'] = null; $this->saveContext((int)$row['id'], $context, 'superseded'); }
            else { $stmt = $this->db->prepare("UPDATE command_interactions SET state='superseded' WHERE id=?"); $id = (int)$row['id']; $stmt->bind_param('i', $id); $stmt->execute(); }
        }
    }

    private function invalidateChild(int $id, string $state): void
    {
        $row = $this->row($id, true); $context = $this->requireContext($row);
        $context['revision']++; $context['pending_work'] = null;
        $this->saveContext($id, $context, $state);
    }

    private function operation(array $row, array $context, string $key, string $code): array
    {
        return ['status' => 'accepted', 'code' => $code, 'facts' => [], 'interaction_id' => (int)$row['id'],
            'revision' => $context['revision'], 'operation_key' => $key];
    }

    private function persistedOperation(int $id, string $key): array
    {
        return $this->requireContext($this->row($id))['operations'][$key];
    }

    private function newWork(array $context, string $key, string $kind): array
    {
        return ['revision' => $context['revision'], 'operation_key' => $key, 'kind' => $kind,
            'attempts' => 0, 'delivery_attempts' => 0, 'lease_token' => null, 'lease_until' => 0, 'available_at' => 0, 'scanned_at' => '0000000000000000', 'delivery_expires_at' => $kind === 'terminal' ? time() + 900 : 0];
    }

    private function workMatches(array $row, array $context, int $revision, string $key): bool
    {
        $work = $context['pending_work'];
        if (!is_array($work) || $context['staged'] || $context['revision'] !== $revision || $work['operation_key'] !== $key) return false;
        if ($work['kind'] === 'terminal') {
            return in_array($row['state'], ['consumed','cancelled'], true) && $row['outcome_json'] !== null
                && $row['result_message_id'] === null && ($work['delivery_expires_at'] ?? 0) > time();
        }
        return in_array($row['state'], ['clarifying','resolving'], true) && strtotime($row['expires_at'] . ' UTC') > time();
    }

    private function leaseMatches(array $row, array $context, int $revision, string $key, string $token): bool
    {
        return $this->workMatches($row, $context, $revision, $key) && is_string($context['pending_work']['lease_token'])
            && hash_equals($context['pending_work']['lease_token'], $token) && $context['pending_work']['lease_until'] > time();
    }

    private function continuationOptions(array $options, string $state): array
    {
        $options = array_values(array_filter($options, static fn($option) => !in_array($option['key'] ?? '', ['refine', 'cancel'], true)));
        if (count($options) > 6 || ($state === 'pending' && !$options)) throw new RuntimeException('Invalid continuation candidates');
        $options[] = ['key' => 'refine', 'label' => 'Не то, уточнить', 'payload' => []];
        $options[] = ['key' => 'cancel', 'label' => 'Передумал', 'payload' => []];
        $keys = [];
        foreach ($options as $option) {
            $key = $option['key'] ?? ''; $label = $option['label'] ?? '';
            if (!is_string($key) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $key) || isset($keys[$key])
                || !is_string($label) || trim($label) === '' || mb_strlen($label) > 160 || !is_array($option['payload'] ?? [])) throw new RuntimeException('Invalid continuation option');
            $keys[$key] = true;
        }
        if (strlen(json_encode($options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) > 32768) throw new RuntimeException('Continuation options too large');
        return $options;
    }

    private function saveOutcome(int $id, string $key, array $outcome, string $state): void
    {
        $json = json_encode($outcome, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $stmt = $this->db->prepare('UPDATE command_interactions SET state=?,selected_key=?,outcome_json=? WHERE id=?');
        $stmt->bind_param('sssi', $state, $key, $json, $id); $stmt->execute();
    }

    private function setCanonicalRowSource(int $id, array $source): void
    {
        $version = $this->sourceVersion($source);
        $stmt = $this->db->prepare('UPDATE command_interactions SET source_version=? WHERE id=?');
        $stmt->bind_param('si', $version, $id); $stmt->execute();
    }

    private function rowSourceEncoding(array $row): string
    {
        $context = $this->context($row);
        if (isset($context['source_version_encoding'])) return $context['source_version_encoding'];
        return ($context['parent_id'] ?? null) !== null ? 'lock_decoded_v1' : 'getter_read_v1';
    }

    private function historicalBinding(array $binding, string $encoding): array
    {
        $binding['version_encoding'] ??= $encoding;
        return $binding;
    }

    private function contextSourceBindings(array $context): array
    {
        $result = [];
        foreach ($context['source_bindings'] as $index => $binding) {
            if (isset($binding['version_encoding'])) { $result[] = $binding; continue; }
            $key = 'input:' . $binding['id'] . ':' . $binding['version'];
            if ($index === 0 || isset($context['operations'][$key])) {
                $result[] = $this->historicalBinding($binding, 'lock_decoded_v1');
            } else {
                $this->assertHistoricalRefineSource($context);
                $result[] = $this->historicalBinding($binding, 'getter_read_v1');
            }
        }
        return $result;
    }

    private function assertHistoricalRefineSource(array $context): void
    {
        foreach ($context['operations'] as $key => $operation) {
            if (preg_match('/^refine:\d+$/D', $key)) return;
        }
        throw new UserError('Неизвестная версия исходного сообщения.');
    }
    /** Source guards have already validated the one immutable persisted binding. */
    private function acceptedInputOperation(array $context, int $messageId): ?array
    {
        foreach ($context['source_bindings'] as $binding) {
            if ($binding['id'] !== $messageId) continue;
            $key = 'input:' . $messageId . ':' . $binding['version'];
            if (isset($context['operations'][$key])) return $context['operations'][$key];
        }
        return null;
    }
}
