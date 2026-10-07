<?php
namespace Components\AdminLibrary;

use Core\Component;
use Domain\Auth;

class AdminLibraryComponent extends Component {
    public function executeComponent() {
        if (!Auth::isAdmin()) {
            echo 'Access Denied';
            return;
        }
        $this->includeTemplate();
    }
}
