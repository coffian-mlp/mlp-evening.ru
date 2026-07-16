<?php

namespace Components\AdminEvents;

use Core\Component;
use Auth;
use EventManager;

class AdminEventsComponent extends Component {
    public function executeComponent() {
        if (!Auth::isAdmin()) {
            return;
        }

        require_once $_SERVER['DOCUMENT_ROOT'] . '/src/EventManager.php';

        $events = [];
        foreach ((new EventManager())->getAllOrdered() as $row) {
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
