<?php
namespace Components\AdminMenu;

use Core\Component;
use Domain\Auth;

/**
 * Вкладка «Меню» в дашборде (MLP-259). Чистый рендер каркаса —
 * данные грузит dashboard.js через api.php (get_menu_items).
 */
class AdminMenuComponent extends Component {
    public function executeComponent() {
        if (!Auth::isAdmin()) {
            echo "Access Denied";
            return;
        }
        $this->includeTemplate();
    }
}
