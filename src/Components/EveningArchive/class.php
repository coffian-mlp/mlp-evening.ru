<?php
namespace Components\EveningArchive;

use Core\Component;
use Domain\EpisodeManager;
use Domain\EveningArchive;

class EveningArchiveComponent extends Component
{
    public function executeComponent()
    {
        $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
        $page = max(1, min(10000, $page ?: 1));
        $rows = (new EpisodeManager())->getArchivePage(10, ($page - 1) * 10);
        $this->result = [
            'items'=>array_map([EveningArchive::class,'present'], array_slice($rows, 0, 10)),
            'page'=>$page, 'has_next'=>count($rows) > 10,
        ];
        $this->includeTemplate();
    }
}
