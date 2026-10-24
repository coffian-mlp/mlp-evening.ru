<?php
namespace Domain;

/** Allowlisted dashboard projection. Raw diagnostic fields never cross the API boundary. */
final class AnnouncementJournal
{
    public static function describe(array $row, \DateTimeZone $timezone): array
    {
        $row=array_filter($row,static fn($value)=>is_scalar($value) || $value===null);
        $events=[
            'poll_failed'=>'Чтение личных сообщений', 'delivery_failed'=>'Ошибка доставки',
            'channel_sent'=>'Публикация в канале', 'preview_sent'=>'Черновик в личке',
            'controls_sent'=>'Кнопки согласования', 'editor_notification_failed'=>'Уведомление редактора',
            'owner_notification_failed'=>'Уведомление владельца', 'worker_failed'=>'Работа анонсов',
            'deployment_check'=>'Проверка журнала',
        ];
        $stages=['sending'=>'Публикация в канале','preview_sending'=>'Отправка черновика в личку','controls_sending'=>'Отправка кнопок согласования'];
        $event=is_string($row['event']??null)?$row['event']:'';
        $failed=isset($row['error_type']) || str_ends_with($event,'_failed');
        $reason=match ($row['reason']??null) {
            'network'=>match ((int)($row['curl_errno']??0)) {
                28=>'Истекло время ожидания Telegram.', 6=>'Не удалось найти адрес Telegram.',
                7=>'Не удалось подключиться к Telegram.', 35,60=>'Не удалось установить защищённое соединение с Telegram.',
                default=>'Сетевой сбой при обращении к Telegram.',
            },
            'transport_exception'=>'Не удалось выполнить запрос к Telegram.',
            'rate_limit'=>'Telegram ограничил частоту запросов.',
            'service_response'=>'Временная ошибка на стороне Telegram.',
            'invalid_response','invalid_result','missing_message_id'=>'Telegram не вернул корректное подтверждение.',
            'invalid_caption'=>'Текст не соответствует требованиям подписи Telegram.',
            'invalid_photo'=>'Изображение недоступно или не подходит для отправки.',
            'api_rejected','http_rejected'=>match ((int)($row['api_code']??$row['http_status']??0)) {
                403=>'Telegram запретил действие: проверь права бота и доступ к получателю.',
                400=>'Telegram отклонил параметры сообщения.',
                default=>'Telegram отклонил запрос.',
            },
            default=>$failed?'Внутренняя ошибка обработки. Подробный текст не сохраняется в журнале.':(in_array($event,['channel_sent','preview_sent','controls_sent'],true)?'Telegram подтвердил отправку.':'Диагностическая запись.'),
        };
        $outcome='';
        if ($event==='poll_failed') $outcome='Публикация согласованных постов продолжается независимо от чтения лички.';
        if (($row['uncertain']??false)===true || ($row['next_state']??null)==='uncertain') {
            $outcome='Доставка не подтверждена. Автоматический повтор отключён; проверь личку или канал.';
        } elseif (($row['next_state']??null)==='failed') {
            $outcome='Автоматические попытки закончились. Проверь статус поста.';
        } elseif (in_array($row['next_state']??null,['approved','preview_pending','controls_pending'],true)) {
            $retry=self::date($row['retry_at']??null,'Y-m-d H:i:s',$timezone);
            $outcome=$retry?'На момент записи повтор был назначен на '.$retry.'.':'На момент записи был запланирован повтор.';
        }
        $post='';
        if (($number=self::number($row['stream_number']??null))!==null) $post='#'.$number;
        if (in_array($row['kind']??null,['main','reminder'],true)) $post.=($post?' · ':'').($row['kind']==='main'?'Основной анонс':'Напоминание');
        $codes=[];
        foreach (['http_status'=>'HTTP','curl_errno'=>'cURL','api_code'=>'Telegram'] as $key=>$label) {
            $value=self::number($row[$key]??null,9999);
            if ($value) $codes[]=$label.' '.$value;
        }
        foreach (['version'=>'Версия','revision_id'=>'Ревизия','attempts'=>'Попытка','message_id'=>'Сообщение'] as $key=>$label) {
            $value=self::number($row[$key]??null);
            if ($value!==null) $codes[]=$label.' '.$value;
        }
        return [
            'at'=>self::date($row['at']??null,'Y-m-d\TH:i:s\Z',$timezone)??'Время не указано',
            'stage'=>$stages[$row['stage']??'']??$events[$event]??'Событие журнала',
            'post'=>$post, 'error'=>$failed, 'reason'=>$reason, 'outcome'=>$outcome, 'details'=>implode(' · ',$codes),
        ];
    }

    private static function number(mixed $value, int $max = PHP_INT_MAX): ?int
    {
        return is_int($value) && $value>=0 && $value<=$max?$value:null;
    }

    private static function date(mixed $value, string $format, \DateTimeZone $timezone): ?string
    {
        if (!is_string($value) || strlen($value)!==($format==='Y-m-d H:i:s'?19:20) || str_contains($value,"\0")) return null;
        $date=\DateTimeImmutable::createFromFormat('!'.$format,$value,new \DateTimeZone('UTC'));
        if (!$date || $date->format($format)!==$value) return null;
        return $date->setTimezone($timezone)->format('d.m.Y H:i:s');
    }
}
