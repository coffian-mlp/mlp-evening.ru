<?php

namespace Core;

/**
 * Файловый JSON-кеш (MLP-263, AR6-2) — промоция трёх самописных кешей
 * (Episode/User/MenuManager) по правилу «2+ потребителя → core».
 *
 * Файл: <root>/<subdir>/<key>.json — пути легаси-кешей сохранены 1:1,
 * поэтому живые кеш-файлы прода остаются валидными после деплоя.
 * TTL задаёт вызывающий при чтении (у владельцев свои константы).
 * Инвалидация — обязанность менеджера-владельца (architecture.md §Кеширование).
 */
final class FileCache {

    private string $dir;

    /** @param ?string $root корень кеша; null = <project>/cache (переопределяется только в тестах) */
    public function __construct(string $subdir = '', ?string $root = null) {
        $root = $root ?? dirname(__DIR__, 2) . '/cache';
        $this->dir = rtrim($root . ($subdir !== '' ? '/' . $subdir : ''), '/');
    }

    /** Данные или null (нет файла / протух / битый JSON). */
    public function get(string $key, int $ttl): ?array {
        $file = $this->path($key);
        if (!is_file($file) || time() - filemtime($file) >= $ttl) {
            return null;
        }
        $content = file_get_contents($file);
        if ($content === false || $content === '') {
            return null;
        }
        $data = json_decode($content, true);
        return is_array($data) ? $data : null;
    }

    public function set(string $key, array $data): bool {
        if (!is_dir($this->dir) && !mkdir($this->dir, 0777, true) && !is_dir($this->dir)) {
            return false;
        }
        return file_put_contents($this->path($key), json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
    }

    public function delete(string $key): void {
        $file = $this->path($key);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    private function path(string $key): string {
        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $key) || str_contains($key, '..')) {
            throw new \InvalidArgumentException("FileCache: недопустимый ключ '{$key}'");
        }
        return $this->dir . '/' . $key . '.json';
    }

    /** Serialize the entire read/modify/write operation; readers see an atomic replacement. */
    public function updateAtomic(string $key, callable $update): array {
        $file = $this->path($key);
        if (!is_dir($this->dir) && !mkdir($this->dir, 0777, true) && !is_dir($this->dir)) throw new \RuntimeException('Cache directory unavailable');
        $lock = fopen($file.'.lock', 'c');
        if (!$lock) throw new \RuntimeException('Cache lock unavailable');
        $temporary = null;
        try {
            if (!flock($lock, LOCK_EX)) throw new \RuntimeException('Cache lock failed');
            clearstatcache(true, $file);
            $data = $update($this->get($key, PHP_INT_MAX) ?? []);
            $temporary = tempnam($this->dir, '.atomic_');
            if ($temporary !== false) chmod($temporary,is_file($file) ? (fileperms($file)&0777) : (0666&~umask()));
            if ($temporary === false || file_put_contents($temporary, json_encode($data, JSON_THROW_ON_ERROR)) === false
                || !rename($temporary, $file)) throw new \RuntimeException('Cache replacement failed');
            $temporary = null;
            return $data;
        } finally {
            if (is_string($temporary) && is_file($temporary)) unlink($temporary);
            flock($lock, LOCK_UN); fclose($lock);
        }
    }
}
