<?php
namespace Domain;

use Infra\ConfigManager;
use Infra\Database;
use Infra\Env;
use Infra\Transaction;
use Social\AnnouncementProxy;
use Social\TelegramBotClient;

/** Shared dashboard/CLI configuration; existing environment values remain a fallback. */
final class AnnouncementSettings
{
    public static function values(): array
    {
        $c = ConfigManager::getInstance();
        $v = [
            'announcements_enabled'=>(string)$c->getOption('announcements_enabled','0'),
            'announcements_token'=>(string)$c->getOption('announcements_token',Env::get('TELEGRAM_ANNOUNCEMENTS_TOKEN','')),
            'announcements_owner_id'=>(string)$c->getOption('announcements_owner_id',Env::get('TELEGRAM_ANNOUNCEMENTS_OWNER_ID','0')),
            'announcements_channel'=>(string)$c->getOption('announcements_channel',Env::get('TELEGRAM_ANNOUNCEMENTS_CHANNEL','@mlp_evening')),
            'announcements_proxy_url'=>(string)$c->getOption('announcements_proxy_url',''),
            'announcements_event_id'=>(string)$c->getOption('announcements_event_id','0'),
            'announcements_next_number'=>(string)(new AnnouncementStore())->nextNumber(),
        ];
        foreach (AnnouncementPrompts::defaults()+AnnouncementSchedule::defaults()+AnnouncementContext::defaults() as $key=>$default) $v[$key]=(string)$c->getOption($key,$default);
        foreach (['main','reminder'] as $kind) {
            $key='announcements_prompt_image_'.$kind;
            $v[$key]=(string)$c->getOption($key,$c->getOption('announcements_prompt_image',AnnouncementPrompts::defaults()[$key]));
        }
        if ($v['announcements_prompt_reminder']==='Напиши короткую напоминалку: до просмотра три часа. Программу назови очень кратко, без подробного пересказа всех серий.') $v['announcements_prompt_reminder']=AnnouncementPrompts::defaults()['announcements_prompt_reminder'];
        return $v;
    }

    public static function normalize(array $post, array $old): array
    {
        $v = $old + AnnouncementPrompts::defaults() + AnnouncementSchedule::defaults() + AnnouncementContext::defaults();
        $v['announcements_next_number'] ??= $old['announcements_first_number'] ?? '1';
        unset($v['announcements_prompt_debut'],$v['announcements_first_number'],$v['announcements_not_before']);
        foreach (AnnouncementPrompts::defaults() as $key=>$default) {
            if (!isset($post[$key])) continue;
            if (!is_string($post[$key])) throw new \InvalidArgumentException('Некорректный формат настройки генерации.');
            $value=trim($post[$key]);
            if (str_contains($key,'_prompt_')) {
                if ($value==='' || mb_strlen($value)>6000) throw new \InvalidArgumentException('Промпт должен содержать от 1 до 6000 символов.');
            } elseif (!preg_match('/\A[0-9]{3}\z/D',$value) || (int)$value<200 || (int)$value>900) {
                throw new \InvalidArgumentException('Длина текста должна быть от 200 до 900 символов.');
            }
            $v[$key]=$value;
        }
        foreach (AnnouncementContext::defaults() as $key=>$default) {
            if (!isset($post[$key])) continue;
            if (!is_string($post[$key]) || !in_array($post[$key],['0','1'],true)) throw new \InvalidArgumentException('Флажок контекста должен быть включён или выключен.');
            $v[$key]=$post[$key];
        }
        unset($v['announcements_prompt_image']);
        foreach (['announcements_enabled','announcements_token','announcements_owner_id','announcements_channel','announcements_proxy_url','announcements_event_id','announcements_next_number','announcements_timezone','announcements_prepare_days','announcements_prepare_time','announcements_main_days','announcements_main_time','announcements_reminder_minutes','announcements_clear_proxy'] as $key) {
            if (!isset($post[$key])) continue;
            if (!is_string($post[$key])) throw new \InvalidArgumentException('Некорректный формат настройки.');
            $raw = trim($post[$key]);
            if (in_array($key,['announcements_token','announcements_proxy_url'],true) && $raw==='') continue;
            $v[$key]=$raw;
        }
        if (($post['announcements_clear_proxy'] ?? '')==='1') $v['announcements_proxy_url']='';
        unset($v['announcements_clear_proxy']);
        if (!in_array($v['announcements_enabled'], ['0','1'], true)) throw new \InvalidArgumentException('Некорректное состояние анонсов.');
        foreach (['announcements_owner_id','announcements_event_id','announcements_next_number'] as $key) {
            if (!preg_match('/\A\d{1,18}\z/D',$v[$key])) throw new \InvalidArgumentException('ID и номер вечерка должны быть целыми положительными числами.');
        }
        if ((int)$v['announcements_next_number']<1 || (int)$v['announcements_next_number']>3999) throw new \InvalidArgumentException('Номер вечерка должен быть от 1 до 3999.');
        if ($v['announcements_token']!=='' && !preg_match('/\A[0-9]{5,20}:[A-Za-z0-9_-]{20,100}\z/D',$v['announcements_token'])) throw new \InvalidArgumentException('Некорректный формат токена Telegram.');
        if (!preg_match('/\A(?:@[A-Za-z0-9_]{5,32}|-100\d+)\z/D',$v['announcements_channel'])) throw new \InvalidArgumentException('Канал должен быть указан как @username или числовой ID -100….');
        AnnouncementProxy::validate($v['announcements_proxy_url']);
        if (!in_array($v['announcements_timezone'],\DateTimeZone::listIdentifiers(),true)) throw new \InvalidArgumentException('Выбери корректный часовой пояс.');
        foreach (['prepare','main'] as $kind) {
            if (!preg_match('/\A(?:0|[1-9][0-9]?)\z/D',$v['announcements_'.$kind.'_days']) || (int)$v['announcements_'.$kind.'_days']>6) throw new \InvalidArgumentException('Для еженедельной серии срок до события должен быть от 0 до 6 дней.');
            if (!preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/D',$v['announcements_'.$kind.'_time'])) throw new \InvalidArgumentException('Укажи время в формате ЧЧ:ММ.');
        }
        if (!preg_match('/\A[0-9]{1,4}\z/D',$v['announcements_reminder_minutes']) || (int)$v['announcements_reminder_minutes']<1 || (int)$v['announcements_reminder_minutes']>10080) throw new \InvalidArgumentException('Напоминание: от 1 до 10080 минут до события.');
        return $v;
    }

    public static function client(array $v): TelegramBotClient
    {
        return new TelegramBotClient($v['announcements_token'], null, $v['announcements_proxy_url']);
    }

    public static function fingerprint(array $v): string
    {
        return hash('sha256', json_encode($v, JSON_THROW_ON_ERROR));
    }

    public static function ready(array $v): string
    {
        if ((int)$v['announcements_owner_id']<=0 || $v['announcements_token']==='') throw new \InvalidArgumentException('Для включения нужны токен и Telegram ID владельца.');
        $event=null;
        foreach ((new EventManager())->getAllRaw() as $row) if ((int)$row['id']===(int)$v['announcements_event_id']) $event=$row;
        if (!$event || empty($event['use_playlist']) || empty($event['is_recurring']) || ($event['recurrence_rule']??'')!=='weekly') throw new \InvalidArgumentException('Выбери еженедельное событие с плейлистом.');
        $c=self::client($v); $me=$c->request('getMe');
        if (empty($me['is_bot']) || empty($me['id'])) throw new \RuntimeException('Telegram не подтвердил учётную запись бота.');
        if (!empty($c->request('getWebhookInfo')['url'])) throw new \RuntimeException('У бота уже настроен webhook. Нужен отдельный бот для анонсов.');
        $member=$c->request('getChatMember',['chat_id'=>$v['announcements_channel'],'user_id'=>$me['id']]);
        if (($member['status']??'')!=='administrator' || empty($member['can_post_messages'])) throw new \RuntimeException('У бота нет права публикации в канале.');
        $chat=$c->request('getChat',['chat_id'=>(int)$v['announcements_owner_id']]);
        if (($chat['type']??'')!=='private' || (int)($chat['id']??0)!==(int)$v['announcements_owner_id']) throw new \RuntimeException('Сначала отправь /start боту в личку с указанного Telegram-аккаунта.');
        return (string)($me['username']??'');
    }

    public static function save(array $post): void
    {
        $db=Database::getInstance()->getConnection(); $c=ConfigManager::getInstance();
        // Serialize configuration changes with publication to prevent a destination switch in flight.
        if (!(int)$db->query("SELECT GET_LOCK('telegram_announcements',5) AS n")->fetch_assoc()['n']) throw new \RuntimeException('Бот сейчас обрабатывает анонс. Попробуй сохранить настройки чуть позже.');
        try {
            $c->flushCache(); $old=self::values(); $v=self::normalize($post,$old);
            $bindingChanged=false;
            foreach (['announcements_token','announcements_owner_id','announcements_channel','announcements_event_id'] as $key) if ($v[$key]!==$old[$key]) $bindingChanged=true;
            if ($bindingChanged && $db->query("SELECT r.id FROM telegram_announcement_revisions r JOIN telegram_announcement_posts p ON p.current_revision=r.id WHERE r.state NOT IN ('published','cancelled','expired') LIMIT 1")->num_rows) throw new \RuntimeException('Сначала заверши или отмени текущие анонсы в Telegram. Затем можно менять бота, владельца, канал или событие.');
            $store=new AnnouncementStore($db);
            if ((int)$v['announcements_next_number'] < $store->numberFloor()) throw new \InvalidArgumentException('Следующий номер должен быть больше уже созданных вечерков. Обнови страницу и проверь номер.');
            $event=null;
            foreach (EventManager::expandOccurrences((new EventManager())->getAllRaw(),21,time()) as $candidate) {
                if ((int)$candidate['id']===(int)$v['announcements_event_id'] && (int)$candidate['real_start_time']>time()) { $event=$candidate; break; }
            }
            if (!$event && $v['announcements_enabled']==='1') throw new \InvalidArgumentException('Выбери активное еженедельное событие с ближайшим показом.');
            if ($event && !AnnouncementSchedule::calculate($event,$v)) throw new \InvalidArgumentException('Выбери еженедельное событие с плейлистом.');
            if ($v['announcements_enabled']==='1' && ($old['announcements_enabled']!=='1' || $bindingChanged || $v['announcements_proxy_url']!==$old['announcements_proxy_url'])) self::ready($v);
            Transaction::run($db,static function () use ($c,$v) { foreach ($v as $key=>$value) $c->setOption($key,$value); (new AnnouncementStore())->reschedule($v); });
        } finally { $c->flushCache(); $db->query("SELECT RELEASE_LOCK('telegram_announcements')"); }
    }
}
