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
        return [
            'announcements_enabled'=>(string)$c->getOption('announcements_enabled','0'),
            'announcements_token'=>(string)$c->getOption('announcements_token',Env::get('TELEGRAM_ANNOUNCEMENTS_TOKEN','')),
            'announcements_owner_id'=>(string)$c->getOption('announcements_owner_id',Env::get('TELEGRAM_ANNOUNCEMENTS_OWNER_ID','0')),
            'announcements_channel'=>(string)$c->getOption('announcements_channel',Env::get('TELEGRAM_ANNOUNCEMENTS_CHANNEL','@mlp_evening')),
            'announcements_proxy_url'=>(string)$c->getOption('announcements_proxy_url',''),
            'announcements_event_id'=>(string)$c->getOption('announcements_event_id','0'),
            'announcements_first_number'=>(string)$c->getOption('announcements_first_number','1'),
            'announcements_not_before'=>(string)$c->getOption('announcements_not_before','0'),
        ];
    }

    public static function normalize(array $post, array $old): array
    {
        $v = $old;
        foreach (['announcements_enabled','announcements_token','announcements_owner_id','announcements_channel','announcements_proxy_url','announcements_event_id','announcements_first_number','announcements_first_date','announcements_clear_proxy'] as $key) {
            if (!isset($post[$key])) continue;
            if (!is_string($post[$key])) throw new \InvalidArgumentException('Некорректный формат настройки.');
            $raw = trim($post[$key]);
            if (in_array($key,['announcements_token','announcements_proxy_url'],true) && $raw==='') continue;
            $v[$key]=$raw;
        }
        if (($post['announcements_clear_proxy'] ?? '')==='1') $v['announcements_proxy_url']='';
        unset($v['announcements_clear_proxy']);
        if (!in_array($v['announcements_enabled'], ['0','1'], true)) throw new \InvalidArgumentException('Некорректное состояние анонсов.');
        foreach (['announcements_owner_id','announcements_event_id','announcements_first_number'] as $key) {
            if (!preg_match('/\A\d{1,18}\z/D',$v[$key])) throw new \InvalidArgumentException('ID и номер вечерка должны быть целыми положительными числами.');
        }
        if ((int)$v['announcements_first_number']<1 || (int)$v['announcements_first_number']>3999) throw new \InvalidArgumentException('Номер вечерка должен быть от 1 до 3999.');
        if ($v['announcements_token']!=='' && !preg_match('/\A[0-9]{5,20}:[A-Za-z0-9_-]{20,100}\z/D',$v['announcements_token'])) throw new \InvalidArgumentException('Некорректный формат токена Telegram.');
        if (!preg_match('/\A(?:@[A-Za-z0-9_]{5,32}|-100\d+)\z/D',$v['announcements_channel'])) throw new \InvalidArgumentException('Канал должен быть указан как @username или числовой ID -100….');
        AnnouncementProxy::validate($v['announcements_proxy_url']);
        if (isset($v['announcements_first_date'])) {
            $raw=$v['announcements_first_date']; unset($v['announcements_first_date']);
            $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$raw,new \DateTimeZone('Europe/Moscow'));
            if (!$date || $date->format('Y-m-d')!==$raw) throw new \InvalidArgumentException('Укажи дату первого вечерка.');
            $v['announcements_not_before']=(string)$date->getTimestamp();
        }
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
            if ($v['announcements_enabled']==='1') self::ready($v);
            Transaction::run($db,static function () use ($c,$v) { foreach ($v as $key=>$value) $c->setOption($key,$value); });
        } finally { $c->flushCache(); $db->query("SELECT RELEASE_LOCK('telegram_announcements')"); }
    }
}
