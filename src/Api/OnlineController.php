<?php

namespace Api;

use Domain\Auth;
use Domain\OnlineManager;
use Infra\ConfigManager;
use LLM\BotDispatch;

/**
 * API-обработчики онлайн-присутствия (MLP-245) — срез из легаси-цепочки api.php
 * в тонкий роутер. Ответы — Api\Response (MLP-262);
 */
class OnlineController {

    /** Heartbeat: отметиться в online_sessions и вернуть статистику. */
    public static function beat(): void {
        $sessionId = session_id();
        $userId = Auth::userId();
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

        // MLP-319: приветствие по появлению в онлайне. Логин-приветствие не видит зрителей
        // с живой remember-me сессией — они приходят «молча». Heartbeat штампует users.last_seen;
        // если с прошлой отметки прошло >= ai_greeting_absence_hours — это приход, здороваемся
        // тем же путём, что и при логине (троттл ai_greeting_cooldown и гейт «уже написал сам»
        // работают как прежде). Бота самого не приветствуем.
        $greetLogin = null;
        if ($userId) {
            $config = ConfigManager::getInstance();
            $absenceSec = (int)$config->getOption('ai_greeting_absence_hours', 4) * 3600;
            if ((int)$userId !== (int)$config->getOption('ai_bot_user_id', 0)) {
                $touch = $online->touchUser((int)$userId);
                if ($touch && OnlineManager::isArrival($touch['gap'], $absenceSec)) {
                    $greetLogin = $touch['login'];
                }
            }
        }

        $json = json_encode(Response::payload(true, "Beat", 'success', ['online_stats' => $stats]));
        if ($greetLogin !== null) {
            Response::finish($json); // клиент не ждёт бота (образец AuthController::finishThenGreet)
            BotDispatch::dispatch('greeting', [
                'username' => $greetLogin,
                'user_id'  => (int)$userId,
                'source'   => 'online',
            ]);
            exit();
        }

        echo $json;
        exit();
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
