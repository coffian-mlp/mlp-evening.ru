<?php

namespace Components\Calendar;

use Core\Component;

class CalendarComponent extends Component {
    public function executeComponent() {
        // Данные загружаются через AJAX (get_public_events), 
        // так что здесь нам не нужно делать запросы к БД.
        $this->includeTemplate();
    }
}
