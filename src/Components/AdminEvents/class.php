<?php

namespace Components\AdminEvents;

use Core\Component;
use Database;
use Auth;

class AdminEventsComponent extends Component {
    public function executeComponent() {
        if (!Auth::isAdmin()) {
            return;
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM events ORDER BY start_time ASC");
        $stmt->execute();
        $res = $stmt->get_result();
        
        $events = [];
        while ($row = $res->fetch_assoc()) {
            // Конвертируем UTC из базы в MSK для удобного отображения и редактирования в админке
            $dt = new \DateTime($row['start_time'], new \DateTimeZone('UTC'));
            $dt->setTimezone(new \DateTimeZone('Europe/Moscow'));
            $row['start_time_msk'] = $dt->format('Y-m-d H:i');
            
            $events[] = $row;
        }

        $this->result['events'] = $events;
        $this->includeTemplate();
    }
}
