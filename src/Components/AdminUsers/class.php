<?php
namespace Components\AdminUsers;

use Core\Component;
use UserManager;
use Auth;

class AdminUsersComponent extends Component {
    public function executeComponent() {
        if (!Auth::isAdmin()) {
            echo "Access Denied";
            return;
        }

        $this->includeTemplate();
    }
}
