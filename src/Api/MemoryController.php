<?php

namespace Api;

use Domain\Auth;
use Domain\BotMemoryManager;
use Domain\UserManager;

/**
 * Память Лиры (MLP-314) — карточка «Память Лиры» в дашборде (роль admin в карте роутов).
 * Ответы — Api\Response; данные — через владельца Domain\BotMemoryManager.
 */
class MemoryController {

    /** Страница записей; фильтр по виду опционален (admin). */
    public static function list(): void {
        $limit = (int)($_POST['limit'] ?? 50);
        $offset = (int)($_POST['offset'] ?? 0);
        $kind = $_POST['kind'] ?? null;
        if ($kind !== null && !in_array($kind, BotMemoryManager::KINDS, true)) {
            $kind = null;
        }

        $bm = new BotMemoryManager();
        $page = $bm->getPage($limit, $offset, $kind);

        // Ники владельцев досье для отображения; отсутствующий пользователь -> пометка в UI.
        $userIds = array_values(array_unique(array_filter(array_map(
            fn($r) => (int)($r['user_id'] ?? 0), $page['items']
        ))));
        $nicks = $userIds ? (new UserManager())->getUsersByIds($userIds) : [];
        foreach ($page['items'] as &$row) {
            $uid = (int)($row['user_id'] ?? 0);
            $row['owner_nick'] = $uid > 0 ? ($nicks[$uid]['nick'] ?? null) : null; // null при uid>0 = пользователь удалён
        }
        unset($row);

        Response::json(true, "Память получена", 'success', $page);
    }

    /** Правка текста записи (admin): нормализация + перевод в manual — у владельца. */
    public static function save(): void {
        $id = (int)($_POST['id'] ?? 0);
        $text = (string)($_POST['text'] ?? '');
        if (!$id) {
            Response::json(false, "Некорректные данные", 'error');
        }
        if ((new BotMemoryManager())->updateText($id, $text)) {
            Response::json(true, "Запись обновлена");
        }
        Response::json(false, "Запись не найдена или текст пуст", 'error');
    }

    /** Удаление записи (admin) с аудитом в audit_logs (target_id = владелец досье, NULL для мема). */
    public static function remove(): void {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            Response::json(false, "Некорректные данные", 'error');
        }
        $deleted = (new BotMemoryManager())->delete($id);
        if ($deleted === null) {
            Response::json(false, "Запись не найдена", 'error');
        }
        $targetId = $deleted['user_id'] !== null ? (int)$deleted['user_id'] : null;
        (new UserManager())->logAction(Auth::userId(), 'memory_delete', $targetId, json_encode([
            'memory_id' => (int)$deleted['id'],
            'kind' => $deleted['kind'],
            'text' => $deleted['text'],
        ], JSON_UNESCAPED_UNICODE));
        Response::json(true, "Запись удалена");
    }
}
