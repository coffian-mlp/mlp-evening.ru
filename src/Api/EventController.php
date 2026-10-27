<?php

namespace Api;

use Domain\EventManager;
use Domain\EpisodeManager;


/**
 * Обработчики API-действий для событий (MLP-229) — первый срез, вынесенный из
 * гигантского switch в api.php через тонкий роутер (action → роль → менеджер).
 * Ответы — Api\Response (MLP-262);
 * Проверку роли делает роутер ДО вызова этих методов.
 */
class EventController {

    /** Публичный список событий + текущий плейлист (для календаря). */
    public static function getPublic(): void {
        $events   = (new EventManager())->getPublic();
        $playlist = (new EpisodeManager())->getSavedPlaylist();
        Response::json(true, "События загружены", 'success', ['events' => $events, 'playlist' => $playlist]);
    }

    /** Создать/обновить событие (admin). */
    public static function save(): void {
        $id    = (int)($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $start = trim($_POST['start_time_utc'] ?? '');

        if ($title === '' || $start === '') {
            Response::json(false, "Заголовок и время начала обязательны", 'error');
        }

        $duration = (int)($_POST['duration_minutes'] ?? 60);
        if ($duration < 1) $duration = 60;

        $data = [
            'title'                 => $title,
            'description'           => trim($_POST['description'] ?? ''),
            'start_time'            => $start,
            'duration_minutes'      => $duration,
            'is_recurring'          => (int)($_POST['is_recurring'] ?? 0),
            'recurrence_rule'       => trim($_POST['recurrence_rule'] ?? ''),
            'use_playlist'          => (int)($_POST['use_playlist'] ?? 0),
            'generate_new_playlist' => (int)($_POST['generate_new_playlist'] ?? 0),
            'color'                 => trim($_POST['color'] ?? '#6d2f8e'),
        ];
        foreach(['monitor_stream','streamer_user_id'] as $key)if(array_key_exists($key,$_POST))$data[$key]=(int)$_POST[$key];

        $events = new EventManager();

        // Только одно регулярное событие может работать с плейлистом.
        if ($data['is_recurring'] && ($data['use_playlist'] || $data['generate_new_playlist'])) {
            if ($events->hasOtherPlaylistRecurring($id)) {
                Response::json(false, "Уже есть другое регулярное событие, работающее с плейлистами!", 'error');
            }
        }

        if ($id > 0) {
            try { $ok = $events->update($id, $data); } catch(\Throwable $e){Response::caught($e);}
            Response::json($ok, $ok ? "Событие обновлено!" : "Ошибка обновления события", $ok ? 'success' : 'error');
        } else {
            try { $ok = $events->create($data); } catch(\Throwable $e){Response::caught($e);}
            Response::json($ok, $ok ? "Событие добавлено!" : "Ошибка создания события", $ok ? 'success' : 'error');
        }
    }

    /** Удалить событие (admin). */
    public static function delete(): void {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) Response::json(false, "ID не указан", 'error');

        $ok = (new EventManager())->delete($id);
        Response::json($ok, $ok ? "Событие удалено!" : "Ошибка удаления события", $ok ? 'success' : 'error');
    }

    public static function saveMonitoring(): void
    {
        $id=(int)($_POST['default_streamer']??0);
        if($id>0&&(!(new \Domain\UserManager())->getUserById($id)||\Domain\Privacy::isWithdrawn($id)))Response::fail('Выбери действующего пользователя.');
        \Infra\ConfigManager::getInstance()->setOption('stream_monitor_default_streamer',(string)$id);
        Response::ok('Стример по умолчанию сохранён.');
    }
}
