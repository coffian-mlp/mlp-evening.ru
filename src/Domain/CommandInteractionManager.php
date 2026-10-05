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
        $version = $this->sourceVersion($source);
        $expiry = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);
        $stmt = $this->db->prepare('INSERT INTO command_interactions (type,owner_id,source_message_id,source_version,options_json,expires_at) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)');
        $stmt->bind_param('siisss', $type, $ownerId, $sourceMessageId, $version, $json, $expiry);
        $stmt->execute();
        return (int)$this->db->insert_id;
    }

    public function bindMessage(int $interactionId, int $botMessageId): void
    {
        Transaction::run($this->db, function () use ($interactionId, $botMessageId) {
            $row = $this->row($interactionId, true);
            $botId = (int)ConfigManager::getInstance()->getOption('ai_bot_user_id', 0);
            $message = (new ChatManager())->getMessageById($botMessageId);
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
        });
    }

    public function readPublic(int $interactionId, ?int $viewerId = null, ?int $messageId = null): array
    {
        $row = $this->row($interactionId);
        $handler = $this->handler($row['type']);
        $owner = $viewerId !== null && $viewerId === (int)$row['owner_id'];
        $state = $row['state'];
        $valid = $messageId !== null && $messageId === (int)$row['bot_message_id'] && $this->validBinding($row);
        if ($state === 'pending' && strtotime($row['expires_at'] . ' UTC') <= time()) $state = 'expired';
        if (!$valid) $state = 'unavailable';
        $public = ($handler['visibility'] ?? 'owner') === 'public';
        $result = ['id' => $interactionId, 'message_id' => (int)$row['bot_message_id'], 'state' => $state,
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($row['expires_at'] . ' UTC')),
            'can_act' => false, 'options' => []];
        if (!$valid || (!$owner && !$public)) return $result;
        $result['options'] = array_map(static fn($option) => ['key' => $option['key'], 'label' => $option['label']],
            json_decode($row['options_json'], true, 512, JSON_THROW_ON_ERROR));
        if ($owner && $state === 'pending') {
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
        return Transaction::run($this->db, function () use ($interactionId, $optionKey, $actorId, $now) {
            $row = $this->row($interactionId, true);
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
            $stmt = $this->db->prepare('UPDATE command_interactions SET state=?,selected_key=?,outcome_json=? WHERE id=?');
            $stmt->bind_param('sssi', $state, $optionKey, $json, $interactionId);
            $stmt->execute();
            // MySQL JSON canonicalizes key order; the first response and replay share persisted form.
            return json_decode($this->row($interactionId)['outcome_json'], true, 512, JSON_THROW_ON_ERROR);
        });
    }

    public function getResult(int $id, int $actorId): array
    {
        $row = $this->row($id);
        if ((int)$row['owner_id'] !== $actorId) throw new UserError('Этот выбор принадлежит другому пользователю.');
        return ['outcome' => $row['outcome_json'] === null ? null : json_decode($row['outcome_json'], true, 512, JSON_THROW_ON_ERROR),
            'reply_message_id' => $row['result_message_id'] === null ? null : (int)$row['result_message_id'],
            'bot_message_id' => $row['bot_message_id'] === null ? null : (int)$row['bot_message_id'],
            'source_message_id' => (int)$row['source_message_id']];
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

    private function sourceVersion(array $message): string
    {
        return hash('sha256', ($message['raw_message'] ?? '') . '|' . ($message['edited_at'] ?? ''));
    }

    private function validBinding(array $row): bool
    {
        if ($row['bot_message_id'] === null) return false;
        $chat = new ChatManager();
        $source = $chat->getMessageById((int)$row['source_message_id']);
        $bot = $chat->getMessageById((int)$row['bot_message_id']);
        return $source && $bot && empty($source['deleted']) && empty($bot['deleted'])
            && (int)$source['user_id'] === (int)$row['owner_id'] && (int)$bot['user_id'] === (int)$row['bot_user_id']
            && hash_equals($row['source_version'], $this->sourceVersion($source))
            && str_contains($bot['raw_message'] ?? '', '[[command:' . $row['id'] . ']]');
    }

    private function assertAllowed(int $userId): void
    {
        if ($userId < 1 || !(new UserManager())->getUserById($userId)) throw new UserError('Нужна авторизация.');
        (new ChatManager())->assertCanSend($userId);
    }
}
