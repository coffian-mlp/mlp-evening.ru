<?php
namespace Components\AdminStickers;

use Core\Component;
use StickerManager;
use Auth;

class AdminStickersComponent extends Component {
    public function executeComponent() {
        if (!Auth::isAdmin()) {
            echo "Access Denied";
            return;
        }

        $this->includeTemplate();
    }
}
