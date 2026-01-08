<?php

class UploadManager {
    private $uploadDir;
    private $allowedTypes = [];
    private $maxSize;
    private $context;

    public function __construct($context = 'avatar') {
        $this->context = $context;
        
        if ($context === 'chat') {
             $this->uploadDir = __DIR__ . '/../upload/chat/';
             $this->maxSize = 30 * 1024 * 1024; // 30 MB
             // Expanded list for chat
             $this->allowedTypes = [
                 // Images
                 'image/jpeg' => 'jpg',
                 'image/pjpeg' => 'jpg',
                 'image/png' => 'png',
                 'image/gif' => 'gif',
                 'image/webp' => 'webp',
                 // Audio/Video
                 'audio/mpeg' => 'mp3',
                 'audio/ogg' => 'ogg',
                 'audio/wav' => 'wav',
                 'video/mp4' => 'mp4',
                 'video/webm' => 'webm',
                 // Docs/Archives
                 'text/plain' => 'txt',
                 'application/pdf' => 'pdf',
                 'application/zip' => 'zip',
                 'application/x-rar-compressed' => 'rar',
                 'application/x-7z-compressed' => '7z',
                 'application/msword' => 'doc',
                 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx'
             ];
        } elseif ($context === 'sticker') {
             $this->uploadDir = __DIR__ . '/../upload/stickers/';
             $this->maxSize = 2 * 1024 * 1024; // 2 MB max for stickers
             $this->allowedTypes = [
                'image/jpeg' => 'jpg',
                'image/pjpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp'
            ];
        } elseif ($context === 'icon') {
             $this->uploadDir = __DIR__ . '/../upload/icons/';
             $this->maxSize = 1 * 1024 * 1024; // 1 MB max for icons
             $this->allowedTypes = [
                'image/jpeg' => 'jpg',
                'image/pjpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp'
            ];
        } else {
             // Default Avatar
             $this->uploadDir = __DIR__ . '/../upload/avatars/';
             $this->maxSize = 5 * 1024 * 1024; // 5 MB
             $this->allowedTypes = [
                'image/jpeg' => 'jpg',
                'image/pjpeg' => 'jpg', 
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp'
            ];
        }

        if (!is_dir($this->uploadDir)) {
            if (!mkdir($this->uploadDir, 0777, true)) {
                // If failed to create, we can't upload. 
                // However, constructor throwing exception might be annoying.
                // Let's rely on methods failing.
            }
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
                throw new Exception("Неизвестная ошибка загрузки (Code: {$file['error']}).");
        }

        if ($file['size'] > $this->maxSize) {
            $mb = $this->maxSize / 1024 / 1024;
            throw new Exception("Файл слишком большой (макс. $mb МБ).");
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);

        // Check mime against allowed types keys
        // Note: For some types, finfo might return specific mimes not in list.
        // We might need to be lenient or add more mimes.
        // For now, strict check.
        if (!isset($this->allowedTypes[$mime])) {
            // Fallback: check extension if mime is generic octet-stream (sometimes happens with rare files)
            // But better to trust mime.
            // Let's try to map some common issues if needed.
            throw new Exception("Недопустимый формат файла ($mime).");
        }

        $ext = $this->allowedTypes[$mime];
        
        // Use prefix based on context
        if ($this->context === 'chat') $prefix = 'chat_';
        elseif ($this->context === 'sticker') $prefix = 'st_';
        else $prefix = 'av_';
        
        $filename = uniqid($prefix) . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $targetPath = $this->uploadDir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new Exception("Не удалось сохранить файл на сервере.");
        }

        // Return relative path
        if ($this->context === 'chat') $relDir = '/upload/chat/';
        elseif ($this->context === 'sticker') $relDir = '/upload/stickers/';
        elseif ($this->context === 'icon') $relDir = '/upload/icons/';
        else $relDir = '/upload/avatars/';
        
        return $relDir . $filename;
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

        // Скачиваем контент
        $content = @file_get_contents($url, false, stream_context_create([
            'http' => ['timeout' => 5] // Таймаут 5 сек
        ]));

        if ($content === false) {
            throw new Exception("Ошибка загрузки файла по ссылке.");
        }

        if (strlen($content) > $this->maxSize) {
            $mb = $this->maxSize / 1024 / 1024;
            throw new Exception("Файл по ссылке слишком большой (макс $mb МБ).");
        }

        // Проверяем реальный MIME тип содержимого
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($content);

        if (!isset($this->allowedTypes[$mime])) {
            throw new Exception("Формат файла по ссылке не поддерживается ($mime).");
        }

        $ext = $this->allowedTypes[$mime];
        
        if ($this->context === 'chat') $prefix = 'chat_url_';
        elseif ($this->context === 'sticker') $prefix = 'st_url_';
        elseif ($this->context === 'icon') $prefix = 'icon_url_';
        else $prefix = 'av_url_';

        $filename = uniqid($prefix) . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $targetPath = $this->uploadDir . $filename;

        if (file_put_contents($targetPath, $content) === false) {
            throw new Exception("Не удалось сохранить файл на диск.");
        }

        if ($this->context === 'chat') $relDir = '/upload/chat/';
        elseif ($this->context === 'sticker') $relDir = '/upload/stickers/';
        elseif ($this->context === 'icon') $relDir = '/upload/icons/';
        else $relDir = '/upload/avatars/';
        
        return $relDir . $filename;
    }
}
