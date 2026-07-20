<?php

namespace Domain;
use Infra\Database;


/**
 * Владелец таблицы `bot_commands` (A3, MLP-228).
 * Единая точка чтения/записи команд бота — раньше SQL был размазан по
 * LLMManager, BotWorker, api.php и компоненту админки, а DDL+seed гонялись
 * на каждый вызов бота. Схема живёт в migrations/2026_07_bot_commands.sql.
 */
class BotCommandManager {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /** Таблица существует? (для graceful-fallback, если миграция ещё не прогнана). */
    public function isAvailable(): bool {
        $res = $this->db->query("SHOW TABLES LIKE 'bot_commands'");
        return $res && $res->num_rows > 0;
    }

    /** Активные команды. Пустой массив, если таблицы нет или команд нет. */
    public function getActive(): array {
        $rows = [];
        $res = $this->db->query("SELECT * FROM bot_commands WHERE is_active = 1");
        while ($res && $row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }

    /** Все команды (для админки), по алфавиту префикса. */
    public function getAll(): array {
        $rows = [];
        $res = $this->db->query("SELECT * FROM bot_commands ORDER BY command_prefix ASC");
        while ($res && $row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }

    /** Первая активная команда-расписание (для авто-анонсов BotWorker). */
    public function getScheduleCommand(): ?array {
        $res = $this->db->query("SELECT * FROM bot_commands WHERE handler_type='schedule' AND is_active=1 LIMIT 1");
        if ($res && $row = $res->fetch_assoc()) {
            return $row;
        }
        return null;
    }

    /**
     * Pure: найти команду, под которую подходит сообщение. Префикс матчится
     * с ведущим слешем или без ('/schedule' и 'schedule'), но только как целое
     * слово ('scheduler' НЕ считается командой). Возвращает строку команды или null.
     */
    public static function matchCommand(array $activeCommands, string $message): ?array {
        $msg = trim($message);
        foreach ($activeCommands as $row) {
            $cleanPrefix = ltrim($row['command_prefix'] ?? '', '/');
            if ($cleanPrefix === '') continue;
            $pattern = '/^\/?' . preg_quote($cleanPrefix, '/') . '(?:\s|$)/ui';
            if (preg_match($pattern, $msg)) {
                return $row;
            }
        }
        return null;
    }

    public function create(string $prefix, string $description, string $handlerType, string $systemPrompt, int $isActive): bool {
        $stmt = $this->db->prepare("INSERT INTO bot_commands (command_prefix, description, handler_type, system_prompt, is_active) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssi", $prefix, $description, $handlerType, $systemPrompt, $isActive);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function update(int $id, string $prefix, string $description, string $handlerType, string $systemPrompt, int $isActive): bool {
        $stmt = $this->db->prepare("UPDATE bot_commands SET command_prefix=?, description=?, handler_type=?, system_prompt=?, is_active=? WHERE id=?");
        $stmt->bind_param("ssssii", $prefix, $description, $handlerType, $systemPrompt, $isActive, $id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function delete(int $id): bool {
        $stmt = $this->db->prepare("DELETE FROM bot_commands WHERE id=?");
        $stmt->bind_param("i", $id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}
