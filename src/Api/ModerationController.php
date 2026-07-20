<?php

namespace Api;

use Domain\ChatManager;
use Domain\UserManager;

/**
 * Обработчики API-действий модерации (MLP-255) — перенос из legacy-switch
 * api.php в тонкий роутер. Ответы — глобальной sendResponse() (api.php).
 * Роль (moderator) проверяет роутер ДО вызова; иерархия ролей — здесь,
 * в checkHierarchy() (переехала из api.php вместе с ветками).
 */
class ModerationController {

    /**
     * Иерархия санкций: сам себя — нельзя; admin неприкосновенен;
     * moderator не трогает коллег. true = можно, иначе — текст отказа.
     */
    private static function checkHierarchy(int $targetUserId): bool|string {
        // Self-check
        if ($targetUserId == $_SESSION['user_id']) {
            return "Нельзя применять санкции к самому себе!";
        }

        $um = new UserManager();
        $target = $um->getUserById($targetUserId);
        if (!$target) return "Пользователь не найден.";

        $actorRole = $_SESSION['role'] ?? 'user';
        $targetRole = $target['role'];

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

    /** Забанить пользователя (moderator+). */
    public static function ban(): void {
        $targetId = (int)($_POST['user_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? 'Нарушение правил');

        if (!$targetId) sendResponse(false, "Не указан ID пользователя", 'error');

        $check = self::checkHierarchy($targetId);
        if ($check !== true) sendResponse(false, $check, 'error');

        $userManager = new UserManager();
        if ($userManager->banUser($targetId, $reason, $_SESSION['user_id'])) {
            sendResponse(true, "Пользователь забанен! 🔨");
        } else {
            sendResponse(false, "Ошибка при бане пользователя.", 'error');
        }
    }

    /** Разбанить пользователя (moderator+). */
    public static function unban(): void {
        $targetId = (int)($_POST['user_id'] ?? 0);
        if (!$targetId) sendResponse(false, "Не указан ID пользователя", 'error');

        $check = self::checkHierarchy($targetId);
        if ($check !== true) sendResponse(false, $check, 'error');

        $userManager = new UserManager();
        if ($userManager->unbanUser($targetId, $_SESSION['user_id'])) {
            sendResponse(true, "Пользователь разбанен! 🕊️");
        } else {
            sendResponse(false, "Ошибка при разбане.", 'error');
        }
    }

    /** Заглушить пользователя на N минут (moderator+). */
    public static function mute(): void {
        $targetId = (int)($_POST['user_id'] ?? 0);
        $minutes = (int)($_POST['minutes'] ?? 15);
        $reason = trim($_POST['reason'] ?? 'Нарушение правил');

        if (!$targetId) sendResponse(false, "Не указан ID пользователя", 'error');

        $check = self::checkHierarchy($targetId);
        if ($check !== true) sendResponse(false, $check, 'error');

        if ($minutes < 1) $minutes = 15;

        $userManager = new UserManager();
        if ($userManager->muteUser($targetId, $minutes, $_SESSION['user_id'], $reason)) {
            sendResponse(true, "Пользователь заглушен на $minutes мин. 🤐");
        } else {
            sendResponse(false, "Ошибка при муте.", 'error');
        }
    }

    /** Вернуть голос (moderator+). */
    public static function unmute(): void {
        $targetId = (int)($_POST['user_id'] ?? 0);
        if (!$targetId) sendResponse(false, "Не указан ID пользователя", 'error');

        $check = self::checkHierarchy($targetId);
        if ($check !== true) sendResponse(false, $check, 'error');

        $userManager = new UserManager();
        if ($userManager->unmuteUser($targetId, $_SESSION['user_id'])) {
            sendResponse(true, "Голос возвращен! 🗣️");
        } else {
            sendResponse(false, "Ошибка при снятии мута.", 'error');
        }
    }

    /** Удалить последние N сообщений пользователя (moderator+), с записью в аудит. */
    public static function purge(): void {
        $targetId = (int)($_POST['user_id'] ?? 0);
        $count = (int)($_POST['count'] ?? 50);
        if (!$targetId) sendResponse(false, "Не указан ID пользователя", 'error');

        $check = self::checkHierarchy($targetId);
        if ($check !== true) sendResponse(false, $check, 'error');

        if ($count > 100) $count = 100;
        if ($count < 1) $count = 1;

        $chat = new ChatManager();
        $deletedCount = $chat->purgeMessages($targetId, $count);

        $userManager = new UserManager();
        $userManager->logAction($_SESSION['user_id'], 'purge', $targetId, "Deleted $deletedCount messages");

        sendResponse(true, "Удалено $deletedCount сообщений! 🧹");
    }
}
