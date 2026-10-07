<?php
namespace Components\EpisodeCatalogue;

use Core\Component;
use Domain\Auth;
use Domain\EpisodeRatingManager;

class EpisodeCatalogueComponent extends Component {
    public function executeComponent() {
        $this->result['admin'] = !empty($this->params['admin']) && Auth::isAdmin();
        try {
            $this->result['catalogue'] = (new EpisodeRatingManager())->getCatalogueProjection(Auth::userId(), $this->result['admin']);
        } catch (\Throwable $error) {
            error_log('Episode catalogue unavailable: ' . get_class($error));
            $this->result['catalogue'] = null;
        }
        $this->includeTemplate();
    }
}
