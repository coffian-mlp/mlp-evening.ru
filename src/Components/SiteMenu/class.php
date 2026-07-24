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
        $menu = new MenuManager();

        // Подачи: 'header' — шапка страниц (горизонталь + мобильный бургер),
        // 'burger' — только сенобургер, 'stream' — полоска главной (сенобургер
        // всегда + горизонталь header-набора справа от лого; мобилка — CSS).
        // MLP-290: панели бургеров строятся из ОБЪЕДИНЕНИЯ header+burger ('mobile'),
        // чтобы пункт «только шапка» не исчезал на мобиле, где горизонталь спрятана.
        // На главной сенобургер виден и на десктопе — header-only пункты там
        // помечаются классом menu-only-mobile (см. panel.php) и прячутся >768px.
        if ($this->templateName === 'header') {
            $this->result['items'] = $menu->getTreeForViewer('header');
            $this->result['burger_items'] = $menu->getTreeForViewer('mobile');
        } elseif ($this->templateName === 'stream') {
            $this->result['items'] = $menu->getTreeForViewer('mobile');      // панель сенобургера
            $this->result['nav_items'] = $menu->getTreeForViewer('header');  // горизонталь
        } else {
            $this->result['items'] = $menu->getTreeForViewer('burger');
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
