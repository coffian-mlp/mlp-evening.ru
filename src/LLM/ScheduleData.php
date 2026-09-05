<?php

namespace LLM;

/**
 * Данные расписания для промпта Лиры (MLP-322). Pure: вход — occurrence'ы из
 * EventManager::expandOccurrences() (отсортированы по real_start_time) и $now,
 * выход — снимок (идущее / следующее / целевое событие) и текст блока для модели.
 *
 * Раньше хендлер schedule брал «первое незакончившееся» событие и просил модель назвать
 * «сколько осталось до начала»: для идущего события выходило «до начала 3,5 часа»,
 * а анонс одного события получал данные другого (05.09: анонс Феникса Райта с данными
 * StarGate). Теперь интервалы посчитаны здесь, в МСК, а идущее и предстоящее различены.
 */
final class ScheduleData {
    /**
     * @param array       $occurrences из EventManager::expandOccurrences()
     * @param int         $now         UTC timestamp
     * @param string|null $targetRunId run_id анонсируемого события (анонсы воркера), иначе null
     * @return array{now:int, current:?array, next:?array, target:?array}
     */
    public static function snapshot(array $occurrences, int $now, ?string $targetRunId = null): array {
        $current = $next = $target = null;
        foreach ($occurrences as $evt) {
            $start = (int)$evt['real_start_time'];
            $end   = self::endOf($evt);
            if ($targetRunId !== null && self::key($evt) === $targetRunId) {
                $target = $evt;
            }
            if ($current === null && $start <= $now && $now < $end) {
                $current = $evt;
            } elseif ($next === null && $start > $now) {
                $next = $evt;
            }
        }
        return ['now' => $now, 'current' => $current, 'next' => $next, 'target' => $target];
    }

    /**
     * Событие, идущее следом за $runId: стартует после него, не позже чем через $withinSec
     * после его конца, и само ещё не закончилось. Для finished-анонса: «вечер продолжается»,
     * а не «всем спасибо, расходимся», когда части идут стык в стык.
     */
    public static function following(array $occurrences, string $runId, int $now, int $withinSec = 3600): ?array {
        $base = null;
        foreach ($occurrences as $evt) {
            if (self::key($evt) === $runId) { $base = $evt; break; }
        }
        if ($base === null) return null;
        $baseStart = (int)$base['real_start_time'];
        $baseEnd   = self::endOf($base);
        foreach ($occurrences as $evt) {
            if (self::key($evt) === $runId) continue;
            $start = (int)$evt['real_start_time'];
            if ($start > $baseStart && $start <= $baseEnd + $withinSec && self::endOf($evt) > $now) {
                return $evt;
            }
        }
        return null;
    }

    /** Блок «ДАННЫЕ ДЛЯ ОТВЕТА» для промпта. */
    public static function dataBlock(array $snap): string {
        $now = (int)$snap['now'];
        $lines = ['ДАННЫЕ ДЛЯ ОТВЕТА (опирайся ТОЛЬКО на них; если твои прошлые реплики им противоречат — верны данные):'];
        $lines[] = '- Сейчас: ' . MskClock::nowLine($now) . '.';
        $shown = [];
        $labels = ['target' => 'Событие, о котором тебя просят написать', 'current' => 'Идёт прямо сейчас', 'next' => 'Следующее событие'];
        foreach ($labels as $slot => $label) {
            $evt = $snap[$slot] ?? null;
            if ($evt === null || in_array(self::key($evt), $shown, true)) continue;
            $shown[] = self::key($evt);
            $lines[] = "- {$label}: " . self::describe($evt, $now);
        }
        if (count($lines) === 2) {
            $lines[] = '- Расписание пусто: ни идущих, ни запланированных событий нет.';
        }
        return implode("\n", $lines);
    }

    /** Инструкция модели — зависит от того, есть ли что рассказывать. */
    public static function taskLine(array $snap): string {
        if (($snap['target'] ?? null) === null && ($snap['current'] ?? null) === null && ($snap['next'] ?? null) === null) {
            return 'ТВОЯ ЗАДАЧА: Напиши ответ об отсутствии ближайших событий.';
        }
        return 'ТВОЯ ЗАДАЧА: Напиши красивый ответ на основе этих данных (и системного промпта). '
            . 'Время называй по МСК, интервалы бери готовыми из данных — сам ничего не пересчитывай. '
            . 'Про идущее событие не пиши «до начала осталось» — оно уже идёт. Если есть следующее событие — упомяни и его.';
    }

    /** Одна строка про событие: состояние (предстоит / идёт / закончилось), время МСК, интервалы, описание. */
    public static function describe(array $evt, int $now): string {
        $start = (int)$evt['real_start_time'];
        $end   = self::endOf($evt);
        $title = "'" . ($evt['title'] ?? '') . "'";
        $day   = MskClock::dayLabel($start, $now);
        if ($day === 'завтра' && (int)MskClock::format($start, 'G') < 6) {
            $day = 'сегодня ночью'; // 00:00 МСК для людей — ещё «сегодня», а не «завтра вечером»
        }
        $at    = MskClock::format($start, 'H:i');
        $till  = MskClock::format($end, 'H:i');
        if ($now < $start) {
            $s = "{$title} — {$day} в {$at} МСК, до начала " . MskClock::delta($start - $now)
               . " (длится около " . MskClock::delta($end - $start) . ").";
        } elseif ($now < $end) {
            $s = "{$title} — началось {$day} в {$at} МСК, идёт уже " . MskClock::delta($now - $start)
               . ", закончится около {$till} (через " . MskClock::delta($end - $now) . ").";
        } else {
            $s = "{$title} — уже закончилось: шло {$day} с {$at} до {$till} МСК, завершилось "
               . MskClock::delta($now - $end) . " назад.";
        }
        $desc = trim((string)($evt['description'] ?? ''));
        if ($desc !== '') {
            $s .= " Описание: {$desc}";
        }
        return $s;
    }

    private static function endOf(array $evt): int {
        return (int)$evt['real_start_time'] + (int)($evt['duration_minutes'] ?? 0) * 60;
    }

    private static function key(array $evt): string {
        return (string)($evt['run_id'] ?? (($evt['id'] ?? '') . '_' . ($evt['real_start_time'] ?? '')));
    }
}
