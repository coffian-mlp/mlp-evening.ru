<?php

namespace Api;

use Domain\Auth;
use Domain\ChatManager;
use Domain\UserManager;
use Domain\ModerationPolicy;

/**
 * Обработчики API-действий модерации (MLP-255) — перенос из legacy-switch
 * api.php в тонкий роутер. Ответы — Api\Response (MLP-262);
 * Роль (moderator) проверяет роутер ДО вызова; иерархия ролей — здесь,
 * в checkHierarchy() (переехала из api.php вместе с ветками).
 */
class ModerationController {

    /**
     * Иерархия санкций: сам себя — нельзя; admin неприкосновенен;
     * moderator не трогает коллег. true = можно, иначе — текст отказа.
     */
    private static function checkHierarchy(int $targetUserId): bool|string {
        // MLP-350: правила иерархии — в Domain\ModerationPolicy, общие с чат-командами /бан и /мут.
        $target = (new UserManager())->getUserById($targetUserId);
        return ModerationPolicy::check((int)Auth::userId(), Auth::role(), $targetUserId, $target['role'] ?? null);
    }

    /** Забанить пользователя (moderator+). */
    public static function ban(): void {
        $targetId = (int)($_POST['user_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? 'Нарушение правил');

        if (!$targetId) Response::json(false, "Не указан ID пользователя", 'error');

        $userManager = new UserManager();
        $result = $userManager->applyModeration((int)Auth::userId(), $targetId, 'ban', null, $reason);
        if ($result['error'] !== null) Response::json(false, $result['error'], 'error');
        Response::json(true, "Пользователь забанен! 🔨");
    }

    /** Разбанить пользователя (moderator+). */
    public static function unban(): void {
        $targetId = (int)($_POST['user_id'] ?? 0);
        if (!$targetId) Response::json(false, "Не указан ID пользователя", 'error');

        $userManager = new UserManager();
        $result = $userManager->applyModeration((int)Auth::userId(), $targetId, 'unban');
        if ($result['error'] !== null) Response::json(false, $result['error'], 'error');
        Response::json(true, "Пользователь разбанен! 🕊️");
    }

    /** Заглушить пользователя на N минут (moderator+). */
    public static function mute(): void {
        $targetId = (int)($_POST['user_id'] ?? 0);
        $minutes = (int)($_POST['minutes'] ?? 15);
        $reason = trim($_POST['reason'] ?? 'Нарушение правил');

        if (!$targetId) Response::json(false, "Не указан ID пользователя", 'error');

        if ($minutes < 1) $minutes = 15;

        $userManager = new UserManager();
        $result = $userManager->applyModeration((int)Auth::userId(), $targetId, 'mute', $minutes, $reason);
        if ($result['error'] !== null) Response::json(false, $result['error'], 'error');
        Response::json(true, "Пользователь заглушен на $minutes мин. 🤐");
    }

    /** Вернуть голос (moderator+). */
    public static function unmute(): void {
        $targetId = (int)($_POST['user_id'] ?? 0);
        if (!$targetId) Response::json(false, "Не указан ID пользователя", 'error');

        $userManager = new UserManager();
        $result = $userManager->applyModeration((int)Auth::userId(), $targetId, 'unmute');
        if ($result['error'] !== null) Response::json(false, $result['error'], 'error');
        Response::json(true, "Голос возвращен! 🗣️");
    }

    /** Удалить последние N сообщений пользователя (moderator+), с записью в аудит. */
    public static function purge(): void {
        $targetId = (int)($_POST['user_id'] ?? 0);
        $count = (int)($_POST['count'] ?? 50);
        if (!$targetId) Response::json(false, "Не указан ID пользователя", 'error');

        $check = self::checkHierarchy($targetId);
        if ($check !== true) Response::json(false, $check, 'error');

        if ($count > 100) $count = 100;
        if ($count < 1) $count = 1;

        $chat = new ChatManager();
        $deletedCount = $chat->purgeMessages($targetId, $count);

        $userManager = new UserManager();
        $userManager->logAction(Auth::userId(), 'purge', $targetId, "Deleted $deletedCount messages");

        Response::json(true, "Удалено $deletedCount сообщений! 🧹");
    }
}
