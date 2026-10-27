<?php

namespace Components\AdminEvents;

use Core\Component;
use Domain\Auth;
use Domain\EventManager;

class AdminEventsComponent extends Component {
    public function executeComponent() {
        if (!Auth::isAdmin()) {
            return;
        }


        $events = [];
        foreach ((new EventManager())->getAllOrdered() as $row) {
            // Конвертируем UTC из базы в MSK для удобного отображения и редактирования в админке
            $dt = new \DateTime($row['start_time'], new \DateTimeZone('UTC'));
            $dt->setTimezone(new \DateTimeZone('Europe/Moscow'));
            $row['start_time_msk'] = $dt->format('Y-m-d H:i');

            $events[] = $row;
        }

        $this->result['events'] = $events;
        $notifications=new \Domain\LyraNotifications();
        $users=(new \Domain\UserManager())->streamerDirectory();
        foreach($users as &$user)$user['telegram_ready']=$notifications->status((int)$user['id'])['connected'];
        unset($user);
        $this->result['streamers']=$users;
        $this->result['default_streamer']=(int)\Infra\ConfigManager::getInstance()->getOption('stream_monitor_default_streamer','0');
        $this->result['monitor_heartbeat']=\Infra\ConfigManager::getInstance()->getOption('stream_monitor_heartbeat','ещё не запускался');
        $this->result['bot_heartbeat']=\Infra\ConfigManager::getInstance()->getOption('lyra_bot_heartbeat','ещё не запускался');
        $this->result['monitor_journal']=(new \Domain\StreamMonitorStore())->journal();
        foreach($this->result['monitor_journal'] as &$entry){
            $entry['stage']=['site_warning'=>'Предупреждение в чате','private_warning'=>'Личное предупреждение','site_pause'=>'Приостановка в чате','channel_pause'=>'Приостановка в канале','private_reminder'=>'Напоминание стримеру','site_acknowledged'=>'Стример исправляет','site_intentional'=>'Намеренная остановка','site_recovery'=>'Восстановление в чате','channel_recovery'=>'Восстановление в канале'][$entry['stage']]??'Наблюдение';
            $entry['delivery_state']=['pending'=>'Ожидает отправки','sending'=>'Отправляется','sent'=>'Доставлено','cancelled'=>'Отменено','failed'=>'Ошибка','uncertain'=>'Доставка не подтверждена'][$entry['delivery_state']]??$entry['state'];
            $entry['reason']=['telegram_not_connected'=>'Стример не подключил Telegram или бот не настроен','stream_recovered'=>'Эфир восстановился','streamer_answered'=>'Стример ответил','event_ended'=>'Событие завершилось или наблюдение отключено','worker_interrupted'=>'Процесс прервался во время отправки','network'=>'Сетевая ошибка Telegram','rate_limit'=>'Ограничение частоты Telegram','http_rejected'=>'Telegram отклонил отправку','api_rejected'=>'Telegram отклонил отправку','service_response'=>'Сбой Telegram'][$entry['reason']]??($entry['reason']?'Ошибка отправки':'—');
        }
        unset($entry);
        $this->includeTemplate();
    }
}
