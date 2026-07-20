<?php

namespace Api;

use Domain\OnlineManager;

/**
 * API-обработчики онлайн-присутствия (MLP-245) — срез из легаси-цепочки api.php
 * в тонкий роутер. Ответы — глобальной sendResponse() (api.php), роль — роутер.
 */
class OnlineController {

    /** Heartbeat: отметиться в online_sessions и вернуть статистику. */
    public static function beat(): void {
        $sessionId = session_id();
        $userId = $_SESSION['user_id'] ?? null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $online = new OnlineManager();
        $online->beat($sessionId, $userId, $ip, $ua);

        // Return detailed stats (default window 3 mins)
        $stats = $online->getOnlineStats(3);

        // 1% chance to cleanup old sessions (> 1 hour)
        if (rand(1, 100) === 1) {
            $online->cleanup(60);
        }

        sendResponse(true, "Beat", 'success', ['online_stats' => $stats]);
    }

    /** Выход со страницы (beacon): убрать сессию из online. */
    public static function leave(): void {
        $sessionId = session_id();
        $online = new OnlineManager();
        $online->removeSession($sessionId);
        // Beacon не читает ответ; сохраняем исторический формат (без message/type).
        echo json_encode(['success' => true]);
        exit();
    }
}
