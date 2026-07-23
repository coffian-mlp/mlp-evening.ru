<?php

namespace Api;

use Domain\MenuManager;

/**
 * Обработчики API-действий меню сайта (MLP-259). Ответы — Api\Response (MLP-262); роль (admin) и CSRF проверяет роутер ДО вызова.
 */
class MenuController {

    /** Полное дерево (включая выключенные) для редактора (admin). */
    public static function getItems(): void {
        Response::json(true, "Меню получено", 'success', ['items' => (new MenuManager())->getAllTree()]);
    }

    /** Создать (id пуст) или обновить пункт (admin). */
    public static function save(): void {
        $title = trim($_POST['title'] ?? '');
        if ($title === '') Response::json(false, "Заголовок обязателен", 'error');

        // url обязателен для внешних; для внутренних пустой = раскрывашка
        $url = MenuManager::sanitizeUrl($_POST['url'] ?? null);
        if (!empty($_POST['url']) && $url === null) {
            Response::json(false, "Некорректный адрес: только локальные пути (/...) или http(s)://", 'error');
        }

        $ok = (new MenuManager())->save([
            'id' => (int)($_POST['id'] ?? 0),
            'parent_id' => (int)($_POST['parent_id'] ?? 0),
            'title' => $title,
            'url' => $url,
            'visibility' => $_POST['visibility'] ?? 'all',
            'is_active' => !empty($_POST['is_active']),
            'is_external' => !empty($_POST['is_external']),
            'show_in_header' => !empty($_POST['show_in_header']),
            'show_in_burger' => !empty($_POST['show_in_burger']),
        ]);

        if ($ok) {
            Response::json(true, "Пункт сохранён");
        }
        Response::json(false, "Не сохранилось: проверь родителя (двух уровней достаточно) и заголовок", 'error');
    }

    /** Удалить пункт; его дети поднимаются на корень (admin). */
    public static function delete(): void {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) Response::json(false, "ID не указан", 'error');

        if ((new MenuManager())->delete($id)) {
            Response::json(true, "Пункт удалён");
        }
        Response::json(false, "Ошибка удаления", 'error');
    }

    /** Сдвинуть пункт вверх/вниз в пределах уровня (admin). */
    public static function move(): void {
        $id = (int)($_POST['id'] ?? 0);
        $dir = $_POST['dir'] ?? '';
        if (!$id || !in_array($dir, ['up', 'down'], true)) Response::json(false, "Данные неполные", 'error');

        if ((new MenuManager())->move($id, $dir)) {
            Response::json(true, "Порядок обновлён");
        }
        Response::json(false, "Ошибка перемещения", 'error');
    }
}
