<?php
namespace Components\AdminPlaylist;

use Core\Component;
use Domain\EpisodeManager;
use Domain\Auth;

class AdminPlaylistComponent extends Component {
    public function executeComponent() {
        if (!Auth::isAdmin()) {
            echo "Access Denied";
            return;
        }

        $manager = new EpisodeManager();
        $manager->importLegacySnapshot();
        $eveningPlaylist = $manager->getEveningPlaylist();
        $selectedSnapshot = isset($_GET['snapshot_id']) ? $manager->getSnapshot((int)$_GET['snapshot_id']) : $manager->getCurrentSnapshot();
        $this->result['correction_snapshot'] = $selectedSnapshot;
        $this->result['completions'] = $selectedSnapshot ? $manager->getCompletions($selectedSnapshot['id']) : [];
        $this->result['selected_completion'] = null;
        foreach ($this->result['completions'] as $completion) {
            if ($this->result['selected_completion'] === null || ($completion['completion_key'] === ($_GET['completion_key'] ?? null))) $this->result['selected_completion'] = $completion;
            if ($completion['completion_key'] === ($_GET['completion_key'] ?? null)) break;
        }
        $this->result['recent_snapshots'] = $manager->getRecentSnapshots();
        $this->result['correction_key'] = 'correction:' . bin2hex(random_bytes(16));
        
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
