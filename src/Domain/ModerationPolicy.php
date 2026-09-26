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
}
