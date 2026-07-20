<?php
namespace Components\AdminLibrary;

use Core\Component;
use Domain\EpisodeManager;
use Domain\Auth;

class AdminLibraryComponent extends Component {
    public function executeComponent() {
        if (!Auth::isAdmin()) {
            echo "Access Denied";
            return;
        }

        $manager = new EpisodeManager();
        $this->result['allEpisodes'] = $manager->getAllEpisodes();
        
        $this->result['twoPartEpisodes'] = array_filter($this->result['allEpisodes'], function($ep) {
            return $ep['LENGTH'] > 1;
        });

        $this->includeTemplate();
    }
}
