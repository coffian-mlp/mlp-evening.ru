<?php

namespace Api;

use Domain\Auth;
use Domain\ChatManager;


/**
 * API закреплённых сообщений (MLP-242) — срез тонкого роутера api.php.
 * Роль (logged-in) проверяет роутер; право модератора — здесь. Realtime-рассылку
 * pin_update делает ChatManager. get_pinned — публичное чтение.
 */
class PinController {

    public static function pin(): void {
        if (!Auth::isModerator()) {
            Response::json(false, "Access Denied", 'error');
        }
        $id = (int)($_POST['message_id'] ?? 0);
        if (!$id) {
            Response::json(false, "ID сообщения не указан", 'error');
        }
        $chat = new ChatManager();
        if (!$chat->pinMessage($id)) {
            Response::json(false, "Сообщение не найдено", 'error');
        }
        Response::json(true, "Сообщение закреплено", 'success', ['pinned' => $chat->getPinnedMessage()]);
    }

    public static function unpin(): void {
        if (!Auth::isModerator()) {
            Response::json(false, "Access Denied", 'error');
        }
        (new ChatManager())->unpinMessage();
        Response::json(true, "Закрепление снято", 'success');
    }

    public static function get(): void {
        Response::json(true, "OK", 'success', ['pinned' => (new ChatManager())->getPinnedMessage()]);
    }
}
