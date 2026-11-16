<?php

namespace Domain;
use Infra\Database;


/**
 * Владелец таблицы `events` (MLP-229).
 * Единая точка SQL к событиям — раньше запросы были размазаны по api.php,
 * AdminEvents, BotWorker и LLMManager. Раскрытие повторяющихся событий
 * и общие ограничения повторов принадлежат этому менеджеру.
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
        return $this->fetch('id, title, description, start_time, duration_minutes, is_recurring, recurrence_rule, recurrence_until, recurrence_skip_dates, use_playlist, color', true);
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
     * Базовое вхождение включается, если не исключено сроком или пропуском (в т.ч. прошедшее — потребитель фильтрует сам по
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
            if (self::occurrenceAllowed($evt, $start)) $out[] = array_merge($evt, ['real_start_time' => $start, 'run_id' => ($evt['id'] ?? '') . '_' . $start]);
            if (!empty($evt['is_recurring'])) {
                $next = $start;
                while ($next < $horizon) {
                    $rule = $evt['recurrence_rule'] ?? '';
                    if ($rule === 'daily')       $next += 86400;
                    elseif ($rule === 'weekly')  $next += 86400 * 7;
                    else break;
                    if (!empty($evt['recurrence_until']) && self::recurrenceDate($next) > $evt['recurrence_until']) break;
                    if ($next < $horizon && self::occurrenceAllowed($evt, $next)) {
                        $out[] = array_merge($evt, ['real_start_time' => $next, 'run_id' => ($evt['id'] ?? '') . '_' . $next]);
                    }
                }
            }
        }
        usort($out, static fn($a, $b) => $a['real_start_time'] <=> $b['real_start_time']);
        return $out;
    }

    public function create(array $d): bool {
        $d=$this->withMonitoring(self::withRecurrence($d));
        $stmt = $this->db->prepare("INSERT INTO events (title, description, start_time, duration_minutes, is_recurring, recurrence_rule, use_playlist, generate_new_playlist, color, monitor_stream, streamer_user_id, recurrence_until, recurrence_skip_dates) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param(
            "sssiisiisiiss",
            $d['title'], $d['description'], $d['start_time'], $d['duration_minutes'],
            $d['is_recurring'], $d['recurrence_rule'], $d['use_playlist'], $d['generate_new_playlist'], $d['color'], $d['monitor_stream'], $d['streamer_user_id'], $d['recurrence_until'], $d['recurrence_skip_dates']
        );
        return $stmt->execute();
    }

    public function update(int $id, array $d): bool {
        $existing=$this->db->prepare('SELECT monitor_stream,streamer_user_id,recurrence_until,recurrence_skip_dates FROM events WHERE id=?');
        $existing->bind_param('i',$id);$existing->execute();
        foreach($existing->get_result()->fetch_assoc()?:[] as $key=>$value)if(!array_key_exists($key,$d))$d[$key]=$value;
        $d=$this->withMonitoring(self::withRecurrence($d));
        $stmt = $this->db->prepare("UPDATE events SET title=?, description=?, start_time=?, duration_minutes=?, is_recurring=?, recurrence_rule=?, use_playlist=?, generate_new_playlist=?, color=?, monitor_stream=?, streamer_user_id=?, recurrence_until=?, recurrence_skip_dates=? WHERE id=?");
        $stmt->bind_param(
            "sssiisiisiissi",
            $d['title'], $d['description'], $d['start_time'], $d['duration_minutes'],
            $d['is_recurring'], $d['recurrence_rule'], $d['use_playlist'], $d['generate_new_playlist'], $d['color'], $d['monitor_stream'], $d['streamer_user_id'], $d['recurrence_until'], $d['recurrence_skip_dates'], $id
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
    /** Dates in the event form and public calendar are always Moscow dates. */
    public static function recurrenceDate(int $timestamp): string
    {
        return (new \DateTimeImmutable('@'.$timestamp))->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('Y-m-d');
    }

    public static function occurrenceAllowed(array $event, int $start): bool
    {
        $date = self::recurrenceDate($start);
        if (!empty($event['recurrence_until']) && $date > $event['recurrence_until']) return false;
        $skips = $event['recurrence_skip_dates'] ?? [];
        if (is_string($skips)) $skips = json_decode($skips, true) ?: [];
        return !in_array($date, is_array($skips) ? $skips : [], true);
    }

    /** Keep the normal window and include the nearest real future run after any skips. */
    public static function expandWithNextOccurrences(array $events, int $horizonDays = 7, ?int $now = null): array
    {
        $now ??= time();
        $rows = [];
        foreach ($events as $event) {
            $days = $horizonDays;
            $start = empty($event['start_time']) ? false : strtotime($event['start_time'].' UTC');
            $step = empty($event['is_recurring']) ? 0 : (['daily'=>86400, 'weekly'=>604800][$event['recurrence_rule'] ?? ''] ?? 0);
            if ($start !== false && $step && (!empty($event['recurrence_until']) || !empty($event['recurrence_skip_dates']))) {
                $next = $start > $now ? $start : $start + ((int)floor(($now - $start) / $step) + 1) * $step;
                while (empty($event['recurrence_until']) || self::recurrenceDate($next) <= $event['recurrence_until']) {
                    if (self::occurrenceAllowed($event, $next)) {
                        $days = max($days, (int)floor(($next - $now) / 86400) + 1);
                        break;
                    }
                    $next += $step;
                }
            }
            array_push($rows, ...self::expandOccurrences([$event], $days, $now));
        }
        usort($rows, static fn($a, $b) => $a['real_start_time'] <=> $b['real_start_time']);
        return $rows;
    }

    /** Server-side validation also protects callers other than the admin controller. */
    private static function withRecurrence(array $data): array
    {
        $until = $data['recurrence_until'] ?? null;
        $skips = $data['recurrence_skip_dates'] ?? null;
        if ($until === '') $until = null;
        if ($skips === '' || $skips === null) $skips = [];
        if (is_string($skips)) {
            try { $skips = json_decode($skips, false, 512, JSON_THROW_ON_ERROR); }
            catch (\JsonException $e) { throw new \Core\UserError('Пропуски должны быть списком дат в формате ГГГГ-ММ-ДД.'); }
        }
        $validDate = static function ($date): bool {
            if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) return false;
            [$year, $month, $day] = array_map('intval', explode('-', $date));
            return $year >= 1000 && checkdate($month, $day, $year);
        };
        if ($until !== null && !$validDate($until)) throw new \Core\UserError('Укажи корректную дату окончания повторов.');
        if (!is_array($skips) || !array_is_list($skips) || count($skips) > 1000) throw new \Core\UserError('Пропуски должны быть списком дат (не более 1000).');
        foreach ($skips as $date) if (!$validDate($date)) throw new \Core\UserError('Укажи корректные даты пропусков в формате ГГГГ-ММ-ДД.');
        if (empty($data['is_recurring'])) {
            $data['recurrence_until'] = $data['recurrence_skip_dates'] = null;
            return $data;
        }
        if ($until !== null || $skips) {
            $start = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string)$data['start_time'], new \DateTimeZone('UTC'));
            if (!$start || $start->format('Y-m-d H:i:s') !== $data['start_time']) throw new \Core\UserError('Укажи корректное время начала события.');
            $base = self::recurrenceDate($start->getTimestamp());
            if ($until !== null && $until < $base) throw new \Core\UserError('Дата окончания повторов не может быть раньше даты начала (МСК).');
            $step = ['daily'=>1, 'weekly'=>7][$data['recurrence_rule'] ?? ''] ?? 0;
            if (!$step) throw new \Core\UserError('Для ограничений повторов выбери ежедневное или еженедельное повторение.');
            foreach ($skips as $date) {
                $delta = (int)((strtotime($date.' UTC') - strtotime($base.' UTC')) / 86400);
                if ($delta < 0 || $delta % $step !== 0 || ($until !== null && $date > $until)) throw new \Core\UserError('Дата пропуска '.$date.' не является датой вхождения события в пределах срока повторов (МСК).');
            }
        }
        $skips = array_values(array_unique($skips));
        sort($skips);
        $data['recurrence_until'] = $until;
        $data['recurrence_skip_dates'] = $skips ? json_encode($skips, JSON_THROW_ON_ERROR) : null;
        return $data;
    }
}
