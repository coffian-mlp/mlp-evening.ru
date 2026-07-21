<?php

namespace Domain;

use Infra\Database;

/**
 * Меню сайта (MLP-259) — владелец таблицы menu_items.
 *
 * Одно меню, две подачи ('header' — горизонталь в шапке, 'burger' — панель
 * «сенобургера»), два уровня (parent_id; родитель с url NULL — раскрывашка).
 * Кешируется ПОЛНОЕ дерево (cache/menu.json), фильтрация по роли/подаче — после
 * кеша (общий кеш не должен утечь admin-пунктами гостю). Инвалидация — при
 * любой мутации (обязанность владельца, architecture.md §Кеширование).
 */
class MenuManager {

    private const CACHE_FILE = '/cache/menu.json';
    private const CACHE_TTL = 60;

    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    // --- Чтение ---

    /** Дерево для зрителя: фильтр по роли и подаче, скрытие пустых раскрывашек. */
    public function getTreeForViewer(string $mode): array {
        $role = Auth::isAdmin() ? 'admins' : (Auth::check() ? 'users' : 'all');
        return self::filterForViewer($this->getFullTree(), $role, $mode);
    }

    /** Полное дерево для админ-редактора (без фильтров, включая выключенные). */
    public function getAllTree(): array {
        return self::buildTree($this->fetchAll());
    }

    /** Полное дерево активных пунктов (кешируется). */
    private function getFullTree(): array {
        $file = dirname(__DIR__, 2) . self::CACHE_FILE;
        if (is_file($file) && time() - filemtime($file) < self::CACHE_TTL) {
            $cached = json_decode((string)file_get_contents($file), true);
            if (is_array($cached)) return $cached;
        }

        $tree = self::buildTree(array_values(array_filter($this->fetchAll(), fn($r) => (int)$r['is_active'] === 1)));
        @file_put_contents($file, json_encode($tree, JSON_UNESCAPED_UNICODE), LOCK_EX);
        return $tree;
    }

    private function fetchAll(): array {
        $rows = [];
        $res = $this->db->query("SELECT * FROM menu_items ORDER BY sort_order ASC, id ASC");
        while ($res && $row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }

    // --- Чистые статики (юнит-тестируются без БД) ---

    /** Плоские строки (уже отсортированные) → дерево из двух уровней. */
    public static function buildTree(array $rows): array {
        $roots = [];
        $children = [];
        foreach ($rows as $r) {
            if (empty($r['parent_id'])) {
                $r['children'] = [];
                $roots[(int)$r['id']] = $r;
            } else {
                $children[] = $r;
            }
        }
        foreach ($children as $c) {
            $pid = (int)$c['parent_id'];
            if (isset($roots[$pid])) {
                $roots[$pid]['children'][] = $c; // сироты (родитель удалён/выключен) не рендерятся
            }
        }
        return array_values($roots);
    }

    /**
     * Фильтр видимости: роль зрителя ('all' — гость, 'users', 'admins' — админ
     * видит всё) × подача ('header'|'burger'). Раскрывашка без url и без
     * видимых детей скрывается.
     */
    public static function filterForViewer(array $tree, string $role, string $mode): array {
        $showFlag = $mode === 'header' ? 'show_in_header' : 'show_in_burger';
        $roleAllows = function (string $vis) use ($role): bool {
            if ($vis === 'all') return true;
            if ($vis === 'users') return $role === 'users' || $role === 'admins';
            return $role === 'admins'; // vis === 'admins'
        };

        $out = [];
        foreach ($tree as $item) {
            if (!$roleAllows($item['visibility']) || !(int)$item[$showFlag]) continue;

            $item['children'] = array_values(array_filter(
                $item['children'] ?? [],
                fn($c) => $roleAllows($c['visibility']) && (int)$c[$showFlag]
            ));

            // Раскрывашка (url NULL) без видимых детей — пустышка, скрываем
            if (($item['url'] === null || $item['url'] === '') && !$item['children']) continue;

            $out[] = $item;
        }
        return $out;
    }

    /** Валидация URL пункта: локальный путь или http(s); javascript: и прочее — режем. */
    public static function sanitizeUrl(?string $url): ?string {
        $url = trim((string)$url);
        if ($url === '') return null;
        // /\evil.com браузеры нормализуют в //evil.com — бэкслеш тоже режем (ревью L2)
        if ($url[0] === '/' && (!isset($url[1]) || ($url[1] !== '/' && $url[1] !== '\\'))) return $url;
        if (preg_match('#^https?://#i', $url)) return $url;
        return null;
    }

    // --- Мутации (после каждой — сброс кеша) ---

    public function save(array $d): bool {
        $id = (int)($d['id'] ?? 0);
        $parentId = (int)($d['parent_id'] ?? 0) ?: null;
        $title = trim($d['title'] ?? '');
        $url = self::sanitizeUrl($d['url'] ?? null);
        $visibility = in_array($d['visibility'] ?? '', ['all', 'users', 'admins'], true) ? $d['visibility'] : 'all';
        $isActive = (int)!empty($d['is_active']);
        $isExternal = (int)!empty($d['is_external']);
        $inHeader = (int)!empty($d['show_in_header']);
        $inBurger = (int)!empty($d['show_in_burger']);

        if ($title === '') return false;

        // Два уровня max: родителем может быть только корневая РАСКРЫВАШКА
        // (url NULL — иначе шаблоны не рендерят детей и они молча исчезают с сайта; ревью M1).
        if ($parentId !== null) {
            if ($parentId === $id) return false;
            $stmt = $this->db->prepare("SELECT parent_id, url FROM menu_items WHERE id = ?");
            $stmt->bind_param("i", $parentId);
            $stmt->execute();
            $p = $stmt->get_result()->fetch_assoc();
            if (!$p || $p['parent_id'] !== null) return false;
            if ($p['url'] !== null && $p['url'] !== '') return false;
            // и у пункта, становящегося ребёнком, не должно быть своих детей
            if ($id > 0 && $this->hasChildren($id)) return false;
        }

        if ($id > 0) {
            $stmt = $this->db->prepare(
                "UPDATE menu_items SET parent_id = ?, title = ?, url = ?, visibility = ?,
                 is_active = ?, is_external = ?, show_in_header = ?, show_in_burger = ? WHERE id = ?"
            );
            $stmt->bind_param("isssiiiii", $parentId, $title, $url, $visibility, $isActive, $isExternal, $inHeader, $inBurger, $id);
        } else {
            $sort = (int)($this->db->query("SELECT COALESCE(MAX(sort_order), 0) + 10 AS s FROM menu_items")->fetch_assoc()['s']);
            $stmt = $this->db->prepare(
                "INSERT INTO menu_items (parent_id, title, url, visibility, is_active, is_external, show_in_header, show_in_burger, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param("isssiiiii", $parentId, $title, $url, $visibility, $isActive, $isExternal, $inHeader, $inBurger, $sort);
        }

        $ok = $stmt->execute();
        if ($ok) $this->flushCache();
        return $ok;
    }

    /** Удаление пункта; дети не теряются — поднимаются на корень. */
    public function delete(int $id): bool {
        $stmt = $this->db->prepare("UPDATE menu_items SET parent_id = NULL WHERE parent_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();

        $stmt = $this->db->prepare("DELETE FROM menu_items WHERE id = ?");
        $stmt->bind_param("i", $id);
        $ok = $stmt->execute();
        if ($ok) $this->flushCache();
        return $ok;
    }

    /** Перестановка с соседом по уровню (dir: up|down). */
    public function move(int $id, string $dir): bool {
        $stmt = $this->db->prepare("SELECT id, parent_id, sort_order FROM menu_items WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();
        if (!$item) return false;

        $cmp = $dir === 'up' ? '<' : '>';
        $ord = $dir === 'up' ? 'DESC' : 'ASC';
        $parentCond = $item['parent_id'] === null ? "parent_id IS NULL" : "parent_id = " . (int)$item['parent_id'];

        $stmt = $this->db->prepare(
            "SELECT id, sort_order FROM menu_items
             WHERE $parentCond AND (sort_order $cmp ? OR (sort_order = ? AND id $cmp ?))
             ORDER BY sort_order $ord, id $ord LIMIT 1"
        );
        $stmt->bind_param("iii", $item['sort_order'], $item['sort_order'], $item['id']);
        $stmt->execute();
        $neighbor = $stmt->get_result()->fetch_assoc();
        if (!$neighbor) return true; // уже крайний — не ошибка

        // Обмен sort_order (при равенстве — разводим на ±1 через нормализацию простым свапом с поправкой)
        $a = (int)$item['sort_order'];
        $b = (int)$neighbor['sort_order'];
        if ($a === $b) { $b = $dir === 'up' ? $a - 1 : $a + 1; }

        $stmt = $this->db->prepare("UPDATE menu_items SET sort_order = ? WHERE id = ?");
        $stmt->bind_param("ii", $b, $item['id']);
        $stmt->execute();
        $stmt = $this->db->prepare("UPDATE menu_items SET sort_order = ? WHERE id = ?");
        $stmt->bind_param("ii", $a, $neighbor['id']);
        $ok = $stmt->execute();

        if ($ok) $this->flushCache();
        return $ok;
    }

    private function hasChildren(int $id): bool {
        $stmt = $this->db->prepare("SELECT COUNT(*) AS c FROM menu_items WHERE parent_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        return (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0) > 0;
    }

    public function flushCache(): void {
        $file = dirname(__DIR__, 2) . self::CACHE_FILE;
        if (is_file($file)) @unlink($file);
    }
}
