<?php

namespace Api;

use Domain\StickerManager;
use Infra\UploadManager;

/**
 * Обработчики API-действий для стикеров и паков (MLP-255) — перенос из
 * legacy-switch api.php в тонкий роутер. Ответы — глобальной sendResponse()
 * (api.php); роли проверяет роутер ДО вызова.
 *
 * ВАЖНО: getPacks/getStickers — public (их зовёт пикер стикеров чата,
 * в том числе для гостей). Остальное — admin.
 */
class StickerController {

    /** Все паки (public — пикер чата). */
    public static function getPacks(): void {
        $sm = new StickerManager();
        $packs = $sm->getAllPacks();
        sendResponse(true, "Паки получены", 'success', ['packs' => $packs]);
    }

    /**
     * Стикеры (public — исторически; чат-пикер список не зовёт, инлайнит при рендере).
     * MLP-258: опциональные limit/offset — постраничный режим для админ-списка
     * (в ответ добавляется total); без параметров — прежний полный список.
     */
    public static function getStickers(): void {
        $sm = new StickerManager();

        if (isset($_POST['limit'])) {
            $limit = (int)$_POST['limit'];
            $offset = (int)($_POST['offset'] ?? 0);
            $packId = (int)($_POST['pack_id'] ?? 0) ?: null;
            $page = $sm->getStickersPage($limit, $offset, $packId);
            sendResponse(true, "Стикеры получены", 'success', ['stickers' => $page['stickers'], 'total' => $page['total']]);
        }

        $stickers = $sm->getAllStickers(true);
        sendResponse(true, "Стикеры получены", 'success', ['stickers' => $stickers]);
    }

    /** Создать пак (admin). Иконка — опциональный файл. */
    public static function createPack(): void {
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $iconUrl = null;

        if (empty($code) || empty($name)) sendResponse(false, "Код и имя обязательны", 'error');

        try {
            // Upload Icon if provided
            if (isset($_FILES['icon_file']) && $_FILES['icon_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                $uploadManager = new UploadManager('icon');
                $iconUrl = $uploadManager->uploadFromPost($_FILES['icon_file']);
            }

            $sm = new StickerManager();
            if ($sm->createPack($code, $name, $iconUrl)) {
                sendResponse(true, "Пак создан! 🎉");
            } else {
                sendResponse(false, "Ошибка (возможно, такой код уже есть)", 'error');
            }
        } catch (\Exception $e) {
            sendResponse(false, $e->getMessage(), 'error');
        }
    }

    /** Обновить пак (admin). */
    public static function updatePack(): void {
        $id = (int)($_POST['id'] ?? 0);
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $iconUrl = null;

        if (!$id || empty($code) || empty($name)) sendResponse(false, "Данные неполные", 'error');

        try {
            // Upload Icon if provided
            if (isset($_FILES['icon_file']) && $_FILES['icon_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                $uploadManager = new UploadManager('icon');
                $iconUrl = $uploadManager->uploadFromPost($_FILES['icon_file']);
            }

            $sm = new StickerManager();
            if ($sm->updatePack($id, $code, $name, $iconUrl)) {
                sendResponse(true, "Пак обновлен!");
            } else {
                sendResponse(false, "Ошибка обновления", 'error');
            }
        } catch (\Exception $e) {
            sendResponse(false, $e->getMessage(), 'error');
        }
    }

    /** Удалить пак со всеми стикерами (admin). */
    public static function deletePack(): void {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) sendResponse(false, "ID не указан", 'error');

        $sm = new StickerManager();
        if ($sm->deletePack($id)) {
            sendResponse(true, "Пак и все его стикеры удалены 🗑️");
        } else {
            sendResponse(false, "Ошибка удаления", 'error');
        }
    }

    /** Добавить стикер: файл приоритетнее URL (admin). */
    public static function add(): void {
        $code = trim($_POST['code'] ?? '');
        $packId = (int)($_POST['pack_id'] ?? 0);
        $url = trim($_POST['image_url'] ?? '');

        if (empty($code)) sendResponse(false, "Код обязателен", 'error');
        if (!$packId) sendResponse(false, "Выберите пак!", 'error');

        try {
            $uploadManager = new UploadManager('sticker');

            // 1. File Upload
            if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                $url = $uploadManager->uploadFromPost($_FILES['image_file']);
            }
            // 2. URL Download
            elseif (!empty($url) && strpos($url, '/upload/stickers/') !== 0 && filter_var($url, FILTER_VALIDATE_URL)) {
                $url = $uploadManager->uploadFromUrl($url);
            }

            if (empty($url)) sendResponse(false, "Нужно загрузить файл или указать ссылку", 'error');

            // MLP-258: превью сразу при добавлении (null → фронт покажет оригинал)
            $thumbUrl = \Infra\Thumbnailer::createFor($url);

            $sm = new StickerManager();
            $id = $sm->addSticker($code, $url, $packId, $thumbUrl);
            sendResponse(true, "Стикер :$code: добавлен!", 'success', ['id' => $id, 'url' => $url, 'thumb_url' => $thumbUrl]);

        } catch (\Exception $e) {
            sendResponse(false, $e->getMessage(), 'error');
        }
    }

    /** Импорт стикеров из ZIP-архива в пак (admin). */
    public static function importZip(): void {
        $packId = (int)($_POST['pack_id'] ?? 0);
        if (!$packId) sendResponse(false, "Пак не выбран", 'error');
        if (!isset($_FILES['zip_file'])) sendResponse(false, "Архив не загружен", 'error');

        try {
            $file = $_FILES['zip_file'];
            if ($file['error'] !== UPLOAD_ERR_OK) throw new \Exception("Ошибка загрузки файла");
            if (pathinfo($file['name'], PATHINFO_EXTENSION) !== 'zip') throw new \Exception("Только ZIP архивы!");

            $sm = new StickerManager();
            $count = $sm->importFromZip($packId, $file['tmp_name']);

            sendResponse(true, "Успешно импортировано $count стикеров! 📦✨");
        } catch (\Exception $e) {
            sendResponse(false, "ZIP Import Error: " . $e->getMessage(), 'error');
        }
    }

    /** Удалить стикер (admin). */
    public static function delete(): void {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) sendResponse(false, "ID не указан", 'error');

        $sm = new StickerManager();
        if ($sm->deleteSticker($id)) {
            sendResponse(true, "Стикер удален 🗑️");
        } else {
            sendResponse(false, "Ошибка удаления", 'error');
        }
    }
}
