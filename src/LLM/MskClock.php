<?php

namespace LLM;

/**
 * Время для человека и для модели — МСК (MLP-322).
 *
 * База и код живут в UTC (timestamp), участники чата — в Москве, и модель должна видеть
 * то же, что они. Все форматирования — явно через Europe/Moscow, результат не зависит от
 * date.timezone сервера (на проде — America/New_York, из-за чего Лира называла время
 * с отставанием в 7 часов от МСК и «завтра» про событие, идущее в эту минуту).
 */
final class MskClock {
    public const TZ = 'Europe/Moscow';

    private const WEEKDAYS = ['воскресенье', 'понедельник', 'вторник', 'среда', 'четверг', 'пятница', 'суббота'];

    /** date() в МСК для UTC-timestamp. */
    public static function format(int $ts, string $format): string {
        return (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone(self::TZ))->format($format);
    }

    /** День недели по-русски (по МСК-дате). */
    public static function weekday(int $ts): string {
        return self::WEEKDAYS[(int)self::format($ts, 'w')];
    }

    /** Строка «сейчас» для промпта: «суббота, 05.09.2026, 22:34 (МСК)». */
    public static function nowLine(int $now): string {
        return self::weekday($now) . ', ' . self::format($now, 'd.m.Y, H:i') . ' (МСК)';
    }

    /** «сегодня» / «завтра» / «вчера» / «четверг, 10.09» — день $ts относительно $now по МСК-датам. */
    public static function dayLabel(int $ts, int $now): string {
        $tz = new \DateTimeZone(self::TZ);
        $today = new \DateTimeImmutable(self::format($now, 'Y-m-d'), $tz);
        $day   = new \DateTimeImmutable(self::format($ts, 'Y-m-d'), $tz);
        $days  = (int)$today->diff($day)->format('%r%a');
        if ($days === 0)  return 'сегодня';
        if ($days === 1)  return 'завтра';
        if ($days === -1) return 'вчера';
        return self::weekday($ts) . ', ' . self::format($ts, 'd.m');
    }

    /** Интервал для человека: «меньше минуты», «26 мин», «3 ч 34 мин», «2 дня 1 ч». */
    public static function delta(int $sec): string {
        $sec = abs($sec);
        if ($sec < 60) return 'меньше минуты';
        $min = intdiv($sec, 60);
        if ($min < 60) return "{$min} мин";
        $h = intdiv($min, 60);
        $m = $min % 60;
        if ($h < 24) return "{$h} ч" . ($m ? " {$m} мин" : '');
        $d = intdiv($h, 24);
        $h %= 24;
        return self::plural($d, 'день', 'дня', 'дней') . ($h ? " {$h} ч" : '');
    }

    private static function plural(int $n, string $one, string $few, string $many): string {
        $form = ($n % 10 === 1 && $n % 100 !== 11) ? $one
            : (($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 12 || $n % 100 > 14)) ? $few : $many);
        return "{$n} {$form}";
    }
}
