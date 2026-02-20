<?php
namespace Components\AdminPlaylist;

use Core\Component;
use EpisodeManager;
use Auth;

class AdminPlaylistComponent extends Component {
    public function executeComponent() {
        if (!Auth::isAdmin()) {
            echo "Access Denied";
            return;
        }

        $manager = new EpisodeManager();
        $eveningPlaylist = $manager->getEveningPlaylist();
        
        // Extract meta
        $this->result['meta'] = $eveningPlaylist['_meta'] ?? null;
        unset($eveningPlaylist['_meta']);
        
        $this->result['playlist'] = $eveningPlaylist;
        
        // Prepare IDs string for update button
        $ids_string = '';
        if (!empty($eveningPlaylist)) {
            $all_ids = [];
            foreach ($eveningPlaylist as $ep) {
                if (!empty($ep['ids'])) {
                    $all_ids = array_merge($all_ids, $ep['ids']);
                }
            }
            $ids_string = implode(',', $all_ids);
        }
        $this->result['ids_string'] = $ids_string;

        $this->includeTemplate();
    }
}
