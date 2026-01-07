<?php

class UploadManager {
    private $uploadDir;
    private $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/pjpeg' => 'jpg', // IE specific
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp'
    ];
    private $maxSize = 5 * 1024 * 1024; // 5 MB

    public function __construct($uploadDir = null) {
        if ($uploadDir === null) {
            $this->uploadDir = __DIR__ . '/../upload/avatars/';
        } else {
            $this->uploadDir = $uploadDir;
        }

        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0777, true);
        }
    }

    /**
     * Загрузка файла из $_FILES
     */
    public function uploadFromPost($file) {
        if (!isset($file['error']) || is_array($file['error'])) {
            throw new Exception("Некорректные параметры файла.");
        }

        switch ($file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_NO_FILE:
                throw new Exception("Файл не был отправлен.");
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                throw new Exception("Файл слишком большой.");
            default:
                throw new Exception("Неизвестная ошибка загрузки.");
        }

        if ($file['size'] > $this->maxSize) {
            throw new Exception("Файл слишком большой (макс. 5 МБ).");
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);

        if (!isset($this->allowedTypes[$mime])) {
            throw new Exception("Недопустимый формат файла ($mime). Разрешены: JPG, PNG, GIF, WEBP.");
        }

        $ext = $this->allowedTypes[$mime];
        $filename = uniqid('av_') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $targetPath = $this->uploadDir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new Exception("Не удалось сохранить файл.");
        }

        return '/upload/avatars/' . $filename;
    }

    /**
     * Скачивание и сохранение файла по URL
     */
    public function uploadFromUrl($url) {
        // Базовая валидация URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
             throw new Exception("Некорректная ссылка.");
        }

        // Пытаемся получить заголовки
        $context = stream_context_create(['http' => ['method' => 'HEAD']]);
        $headers = @get_headers($url, 1, $context);
        
        if (!$headers || strpos($headers[0], '200') === false) {
             throw new Exception("Не удалось получить доступ к файлу по ссылке.");
        }

        // Скачиваем контент (с ограничением размера можно повозиться через stream, но пока так)
        $content = @file_get_contents($url, false, stream_context_create([
            'http' => ['timeout' => 5] // Таймаут 5 сек
        ]));

        if ($content === false) {
            throw new Exception("Ошибка загрузки файла по ссылке.");
        }

        if (strlen($content) > $this->maxSize) {
            throw new Exception("Файл по ссылке слишком большой (макс 5 МБ).");
        }

        // Проверяем реальный MIME тип содержимого
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($content);

        if (!isset($this->allowedTypes[$mime])) {
            throw new Exception("По ссылке находится не картинка или формат не поддерживается ($mime).");
        }

        $ext = $this->allowedTypes[$mime];
        $filename = uniqid('av_url_') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $targetPath = $this->uploadDir . $filename;

        if (file_put_contents($targetPath, $content) === false) {
            throw new Exception("Не удалось сохранить файл на диск.");
        }

        return '/upload/avatars/' . $filename;
    }
}

