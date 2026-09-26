<?php

namespace Domain;

/**
 * Иерархия санкций (MLP-350): одна политика для контекстного меню (Api\ModerationController)
 * и чат-команд /бан, /мут (LLM\ModerationCommand). Pure — роли передаются явно, потому что
 * воркер, исполняющий команды, сессии не видит.
 */
final class ModerationPolicy {
    /**
     * Можно ли $actorId (роль $actorRole) наказать $targetId (роль $targetRole, null — не найден).
     * true — можно, иначе текст отказа. Сам себя — нельзя; admin неприкосновенен;
     * moderator не трогает коллег; прочим санкции недоступны.
     */
    public static function check(int $actorId, string $actorRole, int $targetId, ?string $targetRole): bool|string {
        if ($targetId === $actorId) {
            return "Нельзя применять санкции к самому себе!";
        }
        if ($targetRole === null) {
            return "Пользователь не найден.";
        }
        if ($actorRole === 'admin') {
            if ($targetRole === 'admin') return "Администратор неприкосновенен!";
            return true; // Admin can moderate everyone else
        }
        if ($actorRole === 'moderator') {
            if ($targetRole === 'admin') return "Это Администратор. Не шали!";
            if ($targetRole === 'moderator') return "Модераторы не могут трогать своих коллег.";
            return true; // Can moderate users
        }
        return "У вас нет прав модератора.";
    }

    /** Роль из команды модерации (админ или модератор). */
    public static function isStaff(?string $role): bool {
        return in_array($role, ['admin', 'moderator'], true);
    }

    /** Pure (MLP-352): момент снятия бана в UTC для users.ban_until; null/0 минут — бессрочно. */
    public static function banUntil(?int $minutes, int $now): ?string {
        return ($minutes !== null && $minutes > 0) ? gmdate('Y-m-d H:i:s', $now + $minutes * 60) : null;
    }

    /** Pure (MLP-352): длительность по-человечески, две старшие единицы: «15 мин», «1 ч 30 мин», «2 дня 3 ч». */
    public static function durationLabel(int $minutes): string {
        $minutes = max(1, $minutes);
        $days = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);
        $mins = $minutes % 60;
        if ($days > 0) {
            $n = $days % 100;
            $word = ($n % 10 === 1 && $n !== 11) ? 'день' : (($n % 10 >= 2 && $n % 10 <= 4 && ($n < 12 || $n > 14)) ? 'дня' : 'дней');
            return "{$days} {$word}" . ($hours > 0 ? " {$hours} ч" : '');
        }
        if ($hours > 0) {
            return "{$hours} ч" . ($mins > 0 ? " {$mins} мин" : '');
        }
        return "{$mins} мин";
    }

    /**
     * Pure (MLP-352): текст для забаненного при попытке написать — вместо общей «ошибки сервера».
     * $banUntilUtc — users.ban_until (UTC) или null для бессрочного бана.
     */
    public static function bannedNotice(?string $reason, ?string $banUntilUtc, int $now): string {
        $reason = trim((string)$reason) !== '' ? trim((string)$reason) : 'нарушение правил';
        $until = $banUntilUtc !== null ? strtotime($banUntilUtc . ' UTC') : false;
        if ($until === false || $until <= $now) {
            return "🚫 Ты в бане — писать в чат нельзя. Причина: {$reason}.";
        }
        $msk = (new \DateTime('@' . $until))->setTimezone(new \DateTimeZone('Europe/Moscow'));
        $left = self::durationLabel((int)ceil(($until - $now) / 60));
        $when = $msk->format(($until - $now) < 86400 ? 'H:i' : 'd.m H:i');
        return "🚫 Ты в бане до {$when} МСК (ещё {$left}) — писать в чат нельзя. Причина: {$reason}.";
    }

    /** Pure (MLP-352): текст для заглушённого — сколько осталось и за что. */
    public static function mutedNotice(int $secondsLeft, ?string $reason): string {
        $left = self::durationLabel((int)ceil(max(1, $secondsLeft) / 60));
        $reason = trim((string)$reason);
        return "🤐 Ты в муте — писать можно будет через {$left}." . ($reason !== '' ? " Причина: {$reason}." : '');
    }
}
