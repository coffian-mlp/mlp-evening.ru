<?php
namespace Components\AdminStatistics;

use Core\Component;
use Domain\Auth;
use Domain\UxStatistics;

final class AdminStatisticsComponent extends Component
{
    public function executeComponent()
    {
        Auth::requireAdmin();
        $this->result = UxStatistics::dashboard((int)($_GET['stats_days'] ?? 30));
        $this->includeTemplate();
    }
}
