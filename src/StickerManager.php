<?php

require_once __DIR__ . '/Database.php';

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

    public function addSticker($code, $imageUrl, $packId) {
        $code = trim($code, ':');
        $packId = (int)$packId;
        
        $stmt = $this->db->prepare("INSERT INTO chat_stickers (code, image_url, pack_id) VALUES (?, ?, ?)");
        $stmt->bind_param("ssi", $code, $imageUrl, $packId);
        
        if ($stmt->execute()) {
            return $stmt->insert_id;
        }
        return false;
    }

    public function deleteSticker($id) {
        $stmt = $this->db->prepare("DELETE FROM chat_stickers WHERE id = ?");
        $stmt->bind_param("i", $id);
        return $stmt->execute();
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
        // 1. Сначала удаляем файлы всех стикеров в этом паке
        $stmt = $this->db->prepare("SELECT image_url FROM chat_stickers WHERE pack_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $res = $stmt->get_result();
        
        while ($row = $res->fetch_assoc()) {
            $path = __DIR__ . '/..' . $row['image_url']; // image_url starts with /upload...
            if (file_exists($path)) {
                unlink($path);
            }
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
            $uploadDir = __DIR__ . '/../upload/stickers/';
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

                // 4. Add to DB
                $this->addSticker($stickerCode, '/upload/stickers/' . $newFilename, $packId);
                $count++;
            }
            $zip->close();
            return $count;
        } else {
            throw new Exception("Не удалось открыть ZIP архив");
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
