<?php
namespace Components\VideoPlayer;

use Core\Component;
use ConfigManager;

class VideoPlayerComponent extends Component {
    public function executeComponent() {
        $config = ConfigManager::getInstance();
        $this->result['stream_url'] = $config->getOption('stream_url', 'https://goodgame.ru/player?161438#autoplay');
        
        $this->includeTemplate();
    }
}
