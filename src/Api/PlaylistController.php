<?php

namespace Api;

use Domain\EpisodeManager;

/**
 * Обработчики API-действий для плейлиста и статистики просмотров (MLP-255) —
 * перенос из legacy-switch api.php в тонкий роутер. Ответы — Api\Response (MLP-262); роль (admin) проверяет роутер ДО вызова.
 */
class PlaylistController {

    /** Сгенерировать и сохранить новый плейлист (admin). */
    public static function regenerate(): void {
        (new EpisodeManager())->regeneratePlaylist();
        Response::json(true, "🎲 Новый плейлист успешно сгенерирован и сохранен!", 'success', ['reload' => true]);
    }

    /** Ручной голос за эпизод (admin). Бывший action vote. */
    public static function vote(): void {
        if (!empty($_POST['episode_id'])) {
            if (!ctype_digit((string)$_POST['episode_id']) || !(new EpisodeManager())->voteForEpisode($_POST['episode_id'])) Response::json(false, 'Серия не найдена.', 'error');
            Response::json(true, "✅ Голос за эпизод #{$_POST['episode_id']} принят!");
        } else {
            Response::json(false, "❌ Не указан ID эпизода.", 'error');
        }
    }

    /** Отметить эпизоды просмотренными и сразу сгенерировать новый плейлист (admin). */
    public static function markWatched(): void {
        $snapshotId = (int)($_POST['snapshot_id'] ?? 0);
        if ($snapshotId <= 0) Response::json(false, 'Не указан снимок плейлиста.', 'error');
        $manager = new EpisodeManager();
        $db = \Infra\Database::getInstance()->getConnection();
        try { $out = \Infra\Transaction::run($db, function() use ($manager, $snapshotId) {
            $occurrence = $manager->findSnapshotOccurrence($snapshotId);
            if ($occurrence) return $manager->finishOccurrence($occurrence['run_id']);
            $out = $manager->completeSnapshot($snapshotId);
            if ($out['code'] === 'completed') $manager->regeneratePlaylist();
            return $out;
        }); } finally { \Infra\ConfigManager::getInstance()->flushCache(); }
        Response::json(true, 'Просмотр подтверждён.', 'success', ['reload' => true, 'outcome' => $out]);
    }

    /** Correct a specific completed snapshot using full canonical stories. */
    public static function correct(): void {
        $id = (int)($_POST['snapshot_id'] ?? 0);
        $key = (string)($_POST['operation_key'] ?? '');
        $stories = $_POST['story_ids'] ?? [];
        if ($id <= 0 || !is_array($stories) || !preg_match('/^[a-zA-Z0-9:_-]{16,190}$/', $key)) Response::json(false, 'Некорректная корректировка.', 'error');
        $out = (new EpisodeManager())->correctCompletion($id, array_map('strval', $stories), (int)\Domain\Auth::userId(), $key, isset($_POST['completion_key']) ? (string)$_POST['completion_key'] : null);
        Response::json(true, 'Корректировка сохранена.', 'success', ['reload' => true, 'outcome' => $out]);
    }

    /** Сбросить голоса Wanna Watch (admin). */
    public static function clearVotes(): void {
        (new EpisodeManager())->clearWannaWatch();
        Response::json(true, "🗑️ Все голоса (Wanna Watch) сброшены.");
    }

    /** Сбросить счётчики просмотров (admin). */
    public static function resetTimesWatched(): void {
        (new EpisodeManager())->resetTimesWatched();
        Response::json(true, "🔄 Счетчики просмотров (TIMES_WATCHED) сброшены!");
    }

    /** Очистить лог истории просмотров (admin). */
    public static function clearWatchingLog(): void {
        (new EpisodeManager())->clearWatchingNowLog();
        Response::json(true, "🗑️ Лог истории просмотров очищен.");
    }
}
