<?php

namespace Api;

use Components\DbAdmin\DbAdminComponent;
use Infra\Database;

/**
 * Обработчики API-действий панели БД (MLP-255): транспорт переехал сюда с
 * db_action-блока в dashboard/index.php. Тонкий адаптер над существующими
 * методами DbAdminComponent — внутренняя логика (whitelist MLP-230, формат
 * ответов get_row/update_row) не меняется до MLP-257. Роль (admin) и CSRF
 * проверяет api.php ДО вызова.
 *
 * ВНИМАНИЕ: get_row/update_row отвечают собственным JSON-форматом компонента
 * (не sendResponse) — контракт фронта сохранён; export отдаёт CSV,
 * переопределяя заголовки api.php до первого вывода.
 */
class DbAdminController {

    private static function component(): DbAdminComponent {
        return new DbAdminComponent('DbAdmin', 'default', []);
    }

    /** Строка таблицы для модалки редактирования (admin). Бывший db_action=get_row. */
    public static function getRow(): void {
        $db = Database::getInstance()->getConnection();
        self::component()->ajaxGetRow($db, $_POST['table'] ?? '', $_POST['id'] ?? null);
        exit();
    }

    /** Сохранить строку таблицы (admin). Бывший db_action=update_row. */
    public static function updateRow(): void {
        $db = Database::getInstance()->getConnection();
        self::component()->ajaxUpdateRow($db, $_POST['table'] ?? '');
        exit();
    }

    /** Экспорт таблицы в CSV с учётом фильтра (admin). Бывший db_action=export (GET). */
    public static function export(): void {
        // exportCsv сам выставляет text/csv + Content-Disposition (до вывода — перекрывает JSON-заголовок api.php)
        self::component()->exportCsv($_POST['table'] ?? '', $_POST);
        exit();
    }
}
