<?php

namespace Domain;
use Infra\Database;


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
    public function getAllRaw(bool $lock = false): array {
        return $this->fetch('*', false, $lock);
    }

    /** Все события, отсортированные по времени начала (админка). */
    public function getAllOrdered(): array {
        return $this->fetch('*', true);
    }

    /** Публичный набор полей для календаря (без служебных). */
    public function getPublic(): array {
        return $this->fetch('id, title, description, start_time, duration_minutes, is_recurring, recurrence_rule, use_playlist, color', true);
    }

    /** Единая выборка событий (AR3-4). $columns — внутренняя константа, не пользовательский ввод. */
    private function fetch(string $columns, bool $ordered, bool $lock = false): array {
        $rows = [];
        $sql = "SELECT $columns FROM events" . ($ordered ? " ORDER BY start_time ASC" : "") . ($lock ? ' FOR UPDATE' : '');
        $res = $this->db->query($sql);
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

    /**
     * Раскрыть повторяющиеся события в список occurrence'ов на горизонт $horizonDays (AR3-1).
     * Единая точка recurrence-раскрытия (раньше дублировалось в BotWorker и LLMManager).
     * Базовое вхождение включается всегда (в т.ч. прошедшее — потребитель фильтрует сам по
     * real_start_time/endTime). Повторы daily/weekly — пока в пределах горизонта. Каждый
     * occurrence получает `real_start_time` (UTC ts) и `run_id` ("<id>_<ts>"). Отсортировано.
     * $now — для детерминизма/тестов; по умолчанию текущее время.
     */
    public static function expandOccurrences(array $rawEvents, int $horizonDays = 7, ?int $now = null): array {
        $now = $now ?? time();
        $horizon = $now + 86400 * $horizonDays;
        $out = [];
        foreach ($rawEvents as $evt) {
            if (empty($evt['start_time'])) continue; // без даты — не occurrence (strtotime(' UTC') дал бы «сейчас»)
            $start = strtotime($evt['start_time'] . ' UTC');
            if ($start === false) continue;
            $out[] = array_merge($evt, ['real_start_time' => $start, 'run_id' => ($evt['id'] ?? '') . '_' . $start]);
            if (!empty($evt['is_recurring'])) {
                $next = $start;
                while ($next < $horizon) {
                    $rule = $evt['recurrence_rule'] ?? '';
                    if ($rule === 'daily')       $next += 86400;
                    elseif ($rule === 'weekly')  $next += 86400 * 7;
                    else break;
                    if ($next < $horizon) {
                        $out[] = array_merge($evt, ['real_start_time' => $next, 'run_id' => ($evt['id'] ?? '') . '_' . $next]);
                    }
                }
            }
        }
        usort($out, static fn($a, $b) => $a['real_start_time'] <=> $b['real_start_time']);
        return $out;
    }

    public function create(array $d): bool {
        $d=$this->withMonitoring($d);
        $stmt = $this->db->prepare("INSERT INTO events (title, description, start_time, duration_minutes, is_recurring, recurrence_rule, use_playlist, generate_new_playlist, color, monitor_stream, streamer_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param(
            "sssiisiisii",
            $d['title'], $d['description'], $d['start_time'], $d['duration_minutes'],
            $d['is_recurring'], $d['recurrence_rule'], $d['use_playlist'], $d['generate_new_playlist'], $d['color'], $d['monitor_stream'], $d['streamer_user_id']
        );
        return $stmt->execute();
    }

    public function update(int $id, array $d): bool {
        $existing=$this->db->prepare('SELECT monitor_stream,streamer_user_id FROM events WHERE id=?');
        $existing->bind_param('i',$id);$existing->execute();
        foreach($existing->get_result()->fetch_assoc()?:[] as $key=>$value)if(!array_key_exists($key,$d))$d[$key]=$value;
        $d=$this->withMonitoring($d);
        $stmt = $this->db->prepare("UPDATE events SET title=?, description=?, start_time=?, duration_minutes=?, is_recurring=?, recurrence_rule=?, use_playlist=?, generate_new_playlist=?, color=?, monitor_stream=?, streamer_user_id=? WHERE id=?");
        $stmt->bind_param(
            "sssiisiisiii",
            $d['title'], $d['description'], $d['start_time'], $d['duration_minutes'],
            $d['is_recurring'], $d['recurrence_rule'], $d['use_playlist'], $d['generate_new_playlist'], $d['color'], $d['monitor_stream'], $d['streamer_user_id'], $id
        );
        return $stmt->execute();
    }

    public function delete(int $id): bool {
        $stmt = $this->db->prepare("DELETE FROM events WHERE id=?");
        $stmt->bind_param('i', $id);
        return $stmt->execute();
    }

    private function withMonitoring(array $d): array
    {
        $d['monitor_stream']=(int)($d['monitor_stream']??0);
        $d['streamer_user_id']=(int)($d['streamer_user_id']??0)?:null;
        if(!in_array($d['monitor_stream'],[0,1],true))throw new \Core\UserError('Некорректная настройка наблюдения за эфиром.');
        $id=$d['streamer_user_id']??(int)\Infra\ConfigManager::getInstance()->getOption('stream_monitor_default_streamer','0');
        $user=$id>0?(new UserManager())->getUserById($id):null;
        if(($d['monitor_stream']||$d['streamer_user_id'])&&(!$user||!UserRoles::has($user,'streamer')||Privacy::isWithdrawn($id)))throw new \Core\UserError('Выбери пользователя с ролью стримера или задай стримера по умолчанию.');
        return $d;
    }
}
