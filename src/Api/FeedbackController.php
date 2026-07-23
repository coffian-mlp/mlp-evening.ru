<?php

namespace Api;

use Domain\FeedbackManager;

/**
 * Беклог фидбека из чата (MLP-270) — просмотр и статусы для дашборда.
 * Ответы — Api\Response; роль admin проверяет роутер ДО вызова.
 */
class FeedbackController {

    /** Страница записей; фильтр по статусу опционален (admin). */
    public static function list(): void {
        $limit = (int)($_POST['limit'] ?? 50);
        $offset = (int)($_POST['offset'] ?? 0);
        $status = $_POST['status'] ?? null;
        if ($status !== null && !in_array($status, FeedbackManager::STATUSES, true)) {
            $status = null;
        }

        $fm = new FeedbackManager();
        $page = $fm->getPage($limit, $offset, $status);
        Response::json(true, "Беклог получен", 'success', $page + ['new_count' => $fm->countNew()]);
    }

    /** Смена статуса записи (admin). */
    public static function setStatus(): void {
        $id = (int)($_POST['id'] ?? 0);
        $status = (string)($_POST['status'] ?? '');
        if (!$id || !in_array($status, FeedbackManager::STATUSES, true)) {
            Response::json(false, "Некорректные данные", 'error');
        }

        if ((new FeedbackManager())->setStatus($id, $status)) {
            Response::json(true, "Статус обновлён");
        }
        Response::json(false, "Запись не найдена", 'error');
    }
}
