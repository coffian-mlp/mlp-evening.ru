<?php
namespace Components\AdminSettings;

use Core\Component;
use ConfigManager;
use Auth;

class AdminSettingsComponent extends Component {
    public function executeComponent() {
        if (!Auth::isAdmin()) {
            echo "Access Denied";
            return;
        }

        $config = ConfigManager::getInstance();
        $this->result['config'] = $config;
        $this->result['currentStreamUrl'] = $config->getOption('stream_url', 'https://goodgame.ru/player?161438#autoplay');
        $this->result['currentChatMode'] = $config->getOption('chat_mode', 'local');
        $this->result['currentRateLimit'] = $config->getOption('chat_rate_limit', 0);

        $this->includeTemplate();
    }
}
