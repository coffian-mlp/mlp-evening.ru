<?php
namespace Components\Profile;

use Core\Component;
use Auth;
use UserManager;
use ConfigManager;

class ProfileComponent extends Component {
    public function executeComponent() {
        if (Auth::check()) {
            $userId = $_SESSION['user_id'];
            $userManager = new UserManager();
            $this->result['user'] = $userManager->getUserById($userId);
            $this->result['options'] = $userManager->getUserOptions($userId);
            $this->result['csrf_token'] = Auth::generateCsrfToken();
            $this->result['is_admin'] = Auth::isAdmin();
            
            $config = ConfigManager::getInstance();
            $this->result['telegram_auth_enabled'] = (bool)$config->getOption('telegram_auth_enabled', 0);
            $this->result['telegram_bot_username'] = $config->getOption('telegram_bot_username', '');
        } else {
            $this->result['user'] = null;
        }
        
        $this->includeTemplate();
    }
}
