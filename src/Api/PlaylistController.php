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
            (new EpisodeManager())->voteForEpisode($_POST['episode_id']);
            Response::json(true, "✅ Голос за эпизод #{$_POST['episode_id']} принят!");
        } else {
            Response::json(false, "❌ Не указан ID эпизода.", 'error');
        }
    }

    /** Отметить эпизоды просмотренными и сразу сгенерировать новый плейлист (admin). */
    public static function markWatched(): void {
        if (!empty($_POST['ids'])) {
            $ids = explode(',', $_POST['ids']);
            $ids = array_filter($ids, 'is_numeric');
            if (!empty($ids)) {
                $manager = new EpisodeManager();
                $manager->markAsWatched($ids);

                // Сразу генерируем новый плейлист на следующий раз
                $manager->regeneratePlaylist();

                Response::json(true, "✅ Плейлист отмечен и сгенерирован новый!", 'success', ['reload' => true]);
            } else {
                Response::json(false, "❌ Некорректный список ID.", 'error');
            }
        }
        // Отличие от исходной ветки: там пустой ids давал пустое тело ответа —
        // теперь явная ошибка (Fail Fast).
        Response::json(false, "❌ Не указан список ID.", 'error');
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
