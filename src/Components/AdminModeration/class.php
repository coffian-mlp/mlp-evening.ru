<?php
namespace Components\AdminModeration;

use Core\Component;
use Auth;

class AdminModerationComponent extends Component {
    public function executeComponent() {
        if (!Auth::isAdmin()) {
            echo "Access Denied";
            return;
        }

        $this->includeTemplate();
    }
}
