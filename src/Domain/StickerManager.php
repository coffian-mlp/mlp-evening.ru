<?php

namespace Domain;

use ZipArchive;

use Core\UserError;
use Infra\Database;


class StickerManager {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function getAllStickers($flat = true) {
        if ($flat) {
            $query = "SELECT s.*, p.code as pack_code, p.name as pack_name 
                      FROM chat_stickers s 
                      LEFT JOIN sticker_packs p ON s.pack_id = p.id 
                      ORDER BY p.sort_order ASC, s.sort_order ASC, s.code ASC";
            $result = $this->db->query($query);
            $stickers = [];
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $stickers[] = $row;
                }
            }
            return $stickers;
        }
        // TODO: Implement grouped return if needed
        return [];
    }

    // Возвращает массив [code => url] для быстрой замены на фронтенде
    public function getStickerMap() {
        $stickers = $this->getAllStickers();
        $map = [];
        foreach ($stickers as $s) {
            $map[$s['code']] = $s['image_url'];
        }
        return $map;
    }

    public function addSticker($code, $imageUrl, $packId, $thumbUrl = null) {
        $code = trim($code, ':');
        $packId = (int)$packId;

        // MLP-258: thumb_url — превью (NULL = нет, фронт показывает оригинал)
        $stmt = $this->db->prepare("INSERT INTO chat_stickers (code, image_url, pack_id, thumb_url) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssis", $code, $imageUrl, $packId, $thumbUrl);

        if ($stmt->execute()) {
            return $stmt->insert_id;
        }
        return false;
    }

    /** MLP-258: страница стикеров для админ-списка (полный список — getAllStickers). */
    public function getStickersPage(int $limit, int $offset, ?int $packId = null): array {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);

        $where = $packId ? "WHERE s.pack_id = ?" : "";

        $stmt = $this->db->prepare("SELECT COUNT(*) AS cnt FROM chat_stickers s $where");
        if ($packId) $stmt->bind_param("i", $packId);
        $stmt->execute();
        $total = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);

        $stmt = $this->db->prepare(
            "SELECT s.*, p.code as pack_code, p.name as pack_name
             FROM chat_stickers s
             LEFT JOIN sticker_packs p ON s.pack_id = p.id
             $where
             ORDER BY p.sort_order ASC, s.sort_order ASC, s.code ASC
             LIMIT ? OFFSET ?"
        );
        if ($packId) {
            $stmt->bind_param("iii", $packId, $limit, $offset);
        } else {
            $stmt->bind_param("ii", $limit, $offset);
        }
        $stmt->execute();
        $res = $stmt->get_result();

        $stickers = [];
        while ($row = $res->fetch_assoc()) {
            $stickers[] = $row;
        }
        return ['stickers' => $stickers, 'total' => $total];
    }

    public function deleteSticker($id) {
        // MLP-258: чистим и файлы (раньше файл оставался сиротой; превью — тоже).
        // Общий файл (стикер-алиас по URL) не трогаем, пока на него ссылается другая запись.
        $id = (int)$id;
        $stmt = $this->db->prepare("SELECT image_url, thumb_url FROM chat_stickers WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        if ($row = $stmt->get_result()->fetch_assoc()) {
            if (!$this->isFileShared($row['image_url'], $id)) $this->unlinkStickerFile($row['image_url']);
            if (!$this->isFileShared($row['thumb_url'], $id)) $this->unlinkStickerFile($row['thumb_url']);
        }

        $stmt = $this->db->prepare("DELETE FROM chat_stickers WHERE id = ?");
        $stmt->bind_param("i", $id);
        return $stmt->execute();
    }

    /** Файл используется другой записью (алиас по URL)? */
    private function isFileShared(?string $webPath, int $excludeId): bool {
        if (!$webPath) return false;
        $stmt = $this->db->prepare("SELECT COUNT(*) AS cnt FROM chat_stickers WHERE (image_url = ? OR thumb_url = ?) AND id != ?");
        $stmt->bind_param("ssi", $webPath, $webPath, $excludeId);
        $stmt->execute();
        return (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0) > 0;
    }

    /** Файл используется стикером вне указанного пака? (для deletePack) */
    private function isFileSharedOutsidePack(?string $webPath, int $packId): bool {
        if (!$webPath) return false;
        $stmt = $this->db->prepare("SELECT COUNT(*) AS cnt FROM chat_stickers WHERE (image_url = ? OR thumb_url = ?) AND pack_id != ?");
        $stmt->bind_param("ssi", $webPath, $webPath, $packId);
        $stmt->execute();
        return (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0) > 0;
    }

    /** Удаление файла стикера/превью — строго внутри /upload/stickers/ (анти-traversal). */
    private function unlinkStickerFile(?string $webPath): void {
        if (!$webPath || strpos($webPath, '/upload/stickers/') !== 0 || str_contains($webPath, '..')) {
            return;
        }
        $root = dirname(__DIR__, 2);
        $real = realpath($root . $webPath);
        // Префикс с завершающим слэшем (ревью MLP-258): соседние каталоги/symlink не проходят
        if ($real !== false && strpos($real, realpath($root . '/upload/stickers') . '/') === 0 && is_file($real)) {
            @unlink($real);
        }
    }

    // --- Packs Methods ---

    public function getAllPacks() {
        $result = $this->db->query("SELECT * FROM sticker_packs ORDER BY sort_order ASC, name ASC");
        $packs = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $packs[] = $row;
            }
        }
        return $packs;
    }

    public function createPack($code, $name, $iconUrl = null) {
        $stmt = $this->db->prepare("INSERT INTO sticker_packs (code, name, icon_url) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $code, $name, $iconUrl);
        if ($stmt->execute()) {
            return $stmt->insert_id;
        }
        return false;
    }

    /** Проставить превью стикеру (MLP-266, AR6-6) — для бэкфилла и регенераций. */
    public function setThumb(int $id, ?string $thumbUrl): bool {
        $stmt = $this->db->prepare("UPDATE chat_stickers SET thumb_url = ? WHERE id = ?");
        $stmt->bind_param("si", $thumbUrl, $id);
        return $stmt->execute();
    }

    public function updatePack($id, $code, $name, $iconUrl = null) {
        if ($iconUrl !== null) {
            $stmt = $this->db->prepare("UPDATE sticker_packs SET code = ?, name = ?, icon_url = ? WHERE id = ?");
            $stmt->bind_param("sssi", $code, $name, $iconUrl, $id);
        } else {
            $stmt = $this->db->prepare("UPDATE sticker_packs SET code = ?, name = ? WHERE id = ?");
            $stmt->bind_param("ssi", $code, $name, $id);
        }
        return $stmt->execute();
    }

    public function deletePack($id) {
        // 1. Сначала удаляем файлы всех стикеров в этом паке (MLP-258: и превью;
        // файлы, на которые ссылаются стикеры вне пака, не трогаем)
        $stmt = $this->db->prepare("SELECT id, image_url, thumb_url FROM chat_stickers WHERE pack_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($row = $res->fetch_assoc()) {
            if (!$this->isFileSharedOutsidePack($row['image_url'], (int)$id)) $this->unlinkStickerFile($row['image_url']);
            if (!$this->isFileSharedOutsidePack($row['thumb_url'], (int)$id)) $this->unlinkStickerFile($row['thumb_url']);
        }
        $stmt->close();

        // 2. Удаляем сам пак (стикеры удалятся каскадно из БД, но файлы мы уже почистили)
        $stmt = $this->db->prepare("DELETE FROM sticker_packs WHERE id = ?");
        $stmt->bind_param("i", $id);
        return $stmt->execute();
    }

    // --- ZIP Import Magic ---

    public function importFromZip($packId, $zipFilePath) {
        $zip = new ZipArchive;
        if ($zip->open($zipFilePath) === TRUE) {
            $uploadDir = __DIR__ . '/../../upload/stickers/';
            if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);

            $count = 0;
            $packId = (int)$packId;

            // Get Pack Code for prefixes
            $packQuery = $this->db->query("SELECT code FROM sticker_packs WHERE id = $packId");
            $packRow = $packQuery->fetch_assoc();
            $packCode = $packRow ? $packRow['code'] : 'pack'.$packId;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $filename = $zip->getNameIndex($i);
                
                // Skip directories and hidden files
                if (substr($filename, -1) == '/' || strpos($filename, '__MACOSX') === 0) continue;
                
                // Check extension
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'])) continue;

                // 1. Transliterate and Clean Filename for Code
                $baseName = pathinfo($filename, PATHINFO_FILENAME);
                $transliteratedName = self::transliterate($baseName);
                $cleanName = preg_replace('/[^a-z0-9_-]/i', '_', strtolower($transliteratedName));
                // Remove multiple underscores
                $cleanName = preg_replace('/_+/', '_', $cleanName);
                $cleanName = trim($cleanName, '_');
                
                if (empty($cleanName)) $cleanName = 'sticker';

                // 2. Generate Unique Code (Loop until free)
                $baseCode = $packCode . '_' . $cleanName;
                $stickerCode = $baseCode;
                $counter = 1;

                while ($this->codeExists($stickerCode)) {
                    $stickerCode = $baseCode . '_' . $counter;
                    $counter++;
                }

                // 3. Save File
                // Use the FINAL unique code for the filename too, to keep things clean
                $newFilename = $stickerCode . '.' . $ext;
                $targetPath = $uploadDir . $newFilename;
                
                // If file with this name exists (from another pack maybe?), unique it too
                // Actually, if code is unique, we should be mostly fine, but let's be safe
                if (file_exists($targetPath)) {
                    $newFilename = $stickerCode . '_' . uniqid() . '.' . $ext;
                    $targetPath = $uploadDir . $newFilename;
                }
                
                copy("zip://" . $zipFilePath . "#" . $filename, $targetPath);

                // 4. Add to DB (MLP-258: с превью; null → фронт покажет оригинал)
                $webPath = '/upload/stickers/' . $newFilename;
                $thumbUrl = \Infra\Thumbnailer::createFor($webPath);
                $this->addSticker($stickerCode, $webPath, $packId, $thumbUrl);
                $count++;
            }
            $zip->close();
            return $count;
        } else {
            throw new UserError("Не удалось открыть ZIP архив");
        }
    }

    private function codeExists($code) {
        $stmt = $this->db->prepare("SELECT id FROM chat_stickers WHERE code = ?");
        $stmt->bind_param("s", $code);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();
        return $exists;
    }

    private static function transliterate($string) {
        $converter = [
            'а' => 'a',   'б' => 'b',   'в' => 'v',
            'г' => 'g',   'д' => 'd',   'е' => 'e',
            'ё' => 'e',   'ж' => 'zh',  'з' => 'z',
            'и' => 'i',   'й' => 'y',   'к' => 'k',
            'л' => 'l',   'м' => 'm',   'н' => 'n',
            'о' => 'o',   'п' => 'p',   'р' => 'r',
            'с' => 's',   'т' => 't',   'у' => 'u',
            'ф' => 'f',   'х' => 'h',   'ц' => 'c',
            'ч' => 'ch',  'ш' => 'sh',  'щ' => 'sch',
            'ь' => '',    'ы' => 'y',   'ъ' => '',
            'э' => 'e',   'ю' => 'yu',  'я' => 'ya',
            
            'А' => 'A',   'Б' => 'B',   'В' => 'V',
            'Г' => 'G',   'Д' => 'D',   'Е' => 'E',
            'Ё' => 'E',   'Ж' => 'Zh',  'З' => 'Z',
            'И' => 'I',   'Й' => 'Y',   'К' => 'K',
            'Л' => 'L',   'М' => 'M',   'Н' => 'N',
            'О' => 'O',   'П' => 'P',   'Р' => 'R',
            'С' => 'S',   'Т' => 'T',   'У' => 'U',
            'Ф' => 'F',   'Х' => 'H',   'Ц' => 'C',
            'Ч' => 'Ch',  'Ш' => 'Sh',  'Щ' => 'Sch',
            'Ь' => '',    'Ы' => 'Y',   'Ъ' => '',
            'Э' => 'E',   'Ю' => 'Yu',  'Я' => 'Ya',
        ];
        return strtr($string, $converter);
    }
}
