<?php

namespace Api;

use Auth;
use ChatManager;


/**
 * API закреплённых сообщений (MLP-242) — срез тонкого роутера api.php.
 * Роль (logged-in) проверяет роутер; право модератора — здесь. Realtime-рассылку
 * pin_update делает ChatManager. get_pinned — публичное чтение.
 */
class PinController {

    public static function pin(): void {
        if (!Auth::isModerator()) {
            sendResponse(false, "Access Denied", 'error');
        }
        $id = (int)($_POST['message_id'] ?? 0);
        if (!$id) {
            sendResponse(false, "ID сообщения не указан", 'error');
        }
        $chat = new ChatManager();
        if (!$chat->pinMessage($id)) {
            sendResponse(false, "Сообщение не найдено", 'error');
        }
        sendResponse(true, "Сообщение закреплено", 'success', ['pinned' => $chat->getPinnedMessage()]);
    }

    public static function unpin(): void {
        if (!Auth::isModerator()) {
            sendResponse(false, "Access Denied", 'error');
        }
        (new ChatManager())->unpinMessage();
        sendResponse(true, "Закрепление снято", 'success');
    }

    public static function get(): void {
        sendResponse(true, "OK", 'success', ['pinned' => (new ChatManager())->getPinnedMessage()]);
    }
}
