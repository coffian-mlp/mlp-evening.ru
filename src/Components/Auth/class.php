<?php
namespace Components\Auth;

use Core\Component;
use ConfigManager;

class AuthComponent extends Component {
    public function executeComponent() {
        $config = ConfigManager::getInstance();
        $this->result['telegram_auth_enabled'] = (bool)$config->getOption('telegram_auth_enabled', 0);
        $this->result['telegram_bot_username'] = $config->getOption('telegram_bot_username', '');
        
        $this->includeTemplate();
    }
}
