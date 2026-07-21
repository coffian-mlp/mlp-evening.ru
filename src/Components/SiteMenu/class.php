<?php
namespace Components\SiteMenu;

use Core\Component;
use Domain\MenuManager;

/**
 * Меню сайта (MLP-259). Шаблоны — две подачи одного меню:
 *   'header' — горизонтальные ссылки в main-header (+бургер на мобиле),
 *   'burger' — кнопка-«сенобургер» с панелью (главная, поверх плеера).
 * Компонент только читает (через владельца MenuManager) и рендерит.
 */
class SiteMenuComponent extends Component {

    public function executeComponent() {
        $mode = $this->templateName === 'header' ? 'header' : 'burger';
        $menu = new MenuManager();

        $this->result['items'] = $menu->getTreeForViewer($mode);
        // Для мобильной шапки: header-подача сворачивается в бургер → нужен burger-набор
        if ($mode === 'header') {
            $this->result['burger_items'] = $menu->getTreeForViewer('burger');
        }
        $this->result['current_path'] = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');

        $this->includeTemplate();
    }

    /** Атрибуты ссылки пункта (общее для обоих шаблонов). */
    public static function linkAttrs(array $item): string {
        $attrs = 'href="' . htmlspecialchars($item['url']) . '"';
        if (!empty($item['is_external'])) {
            $attrs .= ' target="_blank" rel="noopener"';
        }
        return $attrs;
    }

    public static function isActive(array $item, string $currentPath): bool {
        return $item['url'] !== null && $item['url'] === $currentPath;
    }
}
