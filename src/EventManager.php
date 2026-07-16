<?php

require_once __DIR__ . '/Database.php';

/**
 * Владелец таблицы `events` (MLP-229).
 * Единая точка SQL к событиям — раньше запросы были размазаны по api.php,
 * AdminEvents, BotWorker и LLMManager. Раскрытие повторяющихся событий
 * (recurrence) остаётся в потребителях; здесь — только доступ к данным.
 */
class EventManager {
    private $db;

    /** Поля события, принимаемые из формы (без id). */
    private const FIELDS = [
        'title', 'description', 'start_time', 'duration_minutes',
        'is_recurring', 'recurrence_rule', 'use_playlist', 'generate_new_playlist', 'color',
    ];

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /** Все события как есть (для recurrence-раскрытия в BotWorker/LLMManager). */
    public function getAllRaw(): array {
        $rows = [];
        $res = $this->db->query("SELECT * FROM events");
        while ($res && $row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }

    /** Все события, отсортированные по времени начала (админка). */
    public function getAllOrdered(): array {
        $rows = [];
        $res = $this->db->query("SELECT * FROM events ORDER BY start_time ASC");
        while ($res && $row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }

    /** Публичный набор полей для календаря (без служебных). */
    public function getPublic(): array {
        $rows = [];
        $res = $this->db->query("SELECT id, title, description, start_time, duration_minutes, is_recurring, recurrence_rule, use_playlist, color FROM events ORDER BY start_time ASC");
        while ($res && $row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }

    /** Есть ли ДРУГОЕ регулярное событие, работающее с плейлистом (кроме $excludeId). */
    public function hasOtherPlaylistRecurring(int $excludeId = 0): bool {
        if ($excludeId > 0) {
            $stmt = $this->db->prepare("SELECT id FROM events WHERE is_recurring = 1 AND (use_playlist = 1 OR generate_new_playlist = 1) AND id != ?");
            $stmt->bind_param('i', $excludeId);
        } else {
            $stmt = $this->db->prepare("SELECT id FROM events WHERE is_recurring = 1 AND (use_playlist = 1 OR generate_new_playlist = 1)");
        }
        $stmt->execute();
        return $stmt->get_result()->num_rows > 0;
    }

    public function create(array $d): bool {
        $stmt = $this->db->prepare("INSERT INTO events (title, description, start_time, duration_minutes, is_recurring, recurrence_rule, use_playlist, generate_new_playlist, color) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param(
            "sssiisiis",
            $d['title'], $d['description'], $d['start_time'], $d['duration_minutes'],
            $d['is_recurring'], $d['recurrence_rule'], $d['use_playlist'], $d['generate_new_playlist'], $d['color']
        );
        return $stmt->execute();
    }

    public function update(int $id, array $d): bool {
        $stmt = $this->db->prepare("UPDATE events SET title=?, description=?, start_time=?, duration_minutes=?, is_recurring=?, recurrence_rule=?, use_playlist=?, generate_new_playlist=?, color=? WHERE id=?");
        $stmt->bind_param(
            "sssiisiisi",
            $d['title'], $d['description'], $d['start_time'], $d['duration_minutes'],
            $d['is_recurring'], $d['recurrence_rule'], $d['use_playlist'], $d['generate_new_playlist'], $d['color'], $id
        );
        return $stmt->execute();
    }

    public function delete(int $id): bool {
        $stmt = $this->db->prepare("DELETE FROM events WHERE id=?");
        $stmt->bind_param('i', $id);
        return $stmt->execute();
    }
}
