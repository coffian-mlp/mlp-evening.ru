<?php
namespace Components\Poll;

use Core\Component;

/**
 * Компонент опроса (MLP-239). Тонкая обёртка: отдаёт точку монтирования
 * (.poll-widget) и подключает poll.js/style.css. Клиентский виджет (PollWidget)
 * гидратирует её данными через API. Переиспользуем на любых серверных страницах
 * (будущая CMS, расписание): $app->includeComponent('Poll', 'default', ['pollId' => N]).
 * В чате виджет монтируется на маркер [[poll:id]] — ассеты подключает Chat-компонент.
 */
class PollComponent extends Component {
    public function executeComponent() {
        $this->result['pollId'] = (int)($this->params['pollId'] ?? 0);
        $this->includeTemplate();
    }
}
