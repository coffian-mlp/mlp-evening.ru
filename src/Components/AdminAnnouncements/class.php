<?php
namespace Components\AdminAnnouncements;

use Core\Component;
use Domain\Auth;
use Domain\AnnouncementStore;
use Domain\AnnouncementSettings;
use Domain\AnnouncementSchedule;
use Domain\EventManager;
use Infra\ConfigManager;

class AdminAnnouncementsComponent extends Component
{
    public function executeComponent()
    {
        if (!Auth::isAdmin()) {echo 'Access Denied';return;}
        $this->result['config']=ConfigManager::getInstance();
        $this->result['posts']=(new AnnouncementStore())->listing();
        $v=AnnouncementSettings::values(); $this->result['upcoming']=null;
        foreach (EventManager::expandOccurrences((new EventManager())->getAllRaw(),21,time()) as $event) {
            if ((int)$event['id']!==(int)$v['announcements_event_id'] || (int)$event['real_start_time']<=time()) continue;
            try {$this->result['upcoming']=['event'=>$event,'schedule'=>AnnouncementSchedule::calculate($event,$v)];}
            catch (\InvalidArgumentException $e) {$this->result['scheduleError']=$e->getMessage();}
            break;
        }
        $this->includeTemplate();
    }
}
