<?php

namespace Api;

use Infra\ConfigManager;
use LLM\BotDispatch;

/**
 * Приём событий от демона стрима (MLP-308): демон на машине владельца сообщает
 * сайту, что произошло автопереключение (например, серия доиграла и включился
 * перерыв), а бот комментирует это в чате.
 *
 * Маршрут публичный (демон не авторизован), поэтому доступ гейтит общий секрет
 * `stream_command_token` из site_options. Пустой токен = приёмник выключен.
 */
class StreamEventController {

    /** Разрешённые события: белый список, чужие значения в очередь не попадают. */
    private const EVENTS = ['episode_ended'];

    /** POST action=stream_event: token, event (public + секрет). */
    public static function receive(): void {
        $token = (string)ConfigManager::getInstance()->getOption('stream_command_token', '');
        $given = (string)($_SERVER['HTTP_X_STREAM_TOKEN'] ?? $_POST['token'] ?? '');

        if ($token === '' || !hash_equals($token, $given)) {
            Response::json(false, 'Нет доступа', 'error');
        }
        if (!(int)ConfigManager::getInstance()->getOption('stream_command_enabled', 0)) {
            Response::json(true, 'Комментирование команд стрима выключено', 'success');
        }

        $event = (string)($_POST['event'] ?? '');
        if (!in_array($event, self::EVENTS, true)) {
            Response::json(false, 'Неизвестное событие', 'error');
        }

        // Отвечаем демону сразу — реплику сочинит воркер, демон не ждёт LLM.
        Response::finish(json_encode([
            'success' => true,
            'message' => 'Событие принято',
            'type' => 'success',
            'data' => [],
        ]));

        BotDispatch::dispatch('stream_command', [
            'event'   => $event,
            'episode' => (string)($_POST['episode'] ?? ''),
        ]);
        exit();
    }
}
