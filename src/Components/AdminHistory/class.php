<?php
namespace Components\AdminHistory;

use Core\Component;
use Domain\EpisodeManager;
use Domain\Auth;

class AdminHistoryComponent extends Component {
    public function executeComponent() {
        if (!Auth::isAdmin()) {
            echo "Access Denied";
            return;
        }

        $manager = new EpisodeManager();
        $this->result['watchHistory'] = $manager->getWatchHistory();

        $this->includeTemplate();
    }
}
