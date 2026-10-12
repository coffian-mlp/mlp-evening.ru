<?php
namespace Domain;

use Infra\Database;
use Infra\ConfigManager;
use LLM\AnnouncementContent;

/** Administrator commands share the same lock and revision checks as Telegram. */
final class AnnouncementDashboard
{
    public static function execute(array $post): string
    {
        $op=$post['operation']??'';
        if (!is_string($op) || !in_array($op,['prepare','approve','cancel','text','image','both','edit'],true)) throw new \InvalidArgumentException('Неизвестное действие с анонсом.');
        foreach (['revision_id','nonce','feedback','caption'] as $key) if (isset($post[$key]) && !is_string($post[$key])) throw new \InvalidArgumentException('Некорректный формат запроса.');
        $db=Database::getInstance()->getConnection();
        if (!(int)$db->query("SELECT GET_LOCK('telegram_announcements',5) n")->fetch_assoc()['n']) throw new \RuntimeException('Воркер сейчас обрабатывает анонс. Повтори действие чуть позже.');
        try {
            ConfigManager::getInstance()->flushCache();
            $store=new AnnouncementStore($db);
            if ($op==='prepare') {
                $v=AnnouncementSettings::values();
                if ($v['announcements_enabled']!=='1') throw new \InvalidArgumentException('Сначала включи и настрой анонсы.');
                foreach (EventManager::expandOccurrences((new EventManager())->getAllRaw(),21,time()) as $event) {
                    if ((int)$event['id']!==(int)$v['announcements_event_id'] || (int)$event['real_start_time']<=time()) continue;
                    $schedule=AnnouncementSchedule::calculate($event,$v);
                    $facts=(new AnnouncementContent())->facts($event,time());
                    if (!$schedule || !$facts) throw new \RuntimeException('У ближайшего события нет закреплённого плейлиста. Подготовь его в календаре.');
                    $store->ensure($facts,AnnouncementContent::fingerprint($facts),$schedule,(int)$v['announcements_next_number']);
                    return 'Черновики ближайшего события поставлены в очередь. Готовые посты не перегенерируются повторно.';
                }
                throw new \RuntimeException('Ближайшее событие не найдено. Проверь календарь.');
            }
            $id=$post['revision_id']??''; $nonce=$post['nonce']??'';
            if (!preg_match('/\A[1-9][0-9]{0,17}\z/D',$id) || !preg_match('/\A[a-f0-9]{16}\z/D',$nonce)) throw new \InvalidArgumentException('Некорректная версия поста.');
            $r=$store->revision((int)$id);
            if ($op!=='cancel' && $r && (int)$r['current_revision']===(int)$id && hash_equals($r['nonce'],$nonce)) {
                $old=json_decode($r['facts_json'],true,512,JSON_THROW_ON_ERROR);
                $fresh=(new AnnouncementContent())->facts($old['occurrence'],time());
                if (!$fresh || !hash_equals($r['fingerprint'],AnnouncementContent::fingerprint($fresh))) throw new \RuntimeException('Событие или плейлист изменились. Обнови черновики через «Подготовить ближайший вечерок».');
            }
            $status=$op==='edit'?$store->edit((int)$id,$nonce,$post['caption']??''):$store->action((int)$id,$nonce,$op,mb_substr($post['feedback']??'',0,2000),null,true);
            $messages=['approved'=>'Пост одобрен и будет опубликован по расписанию.','cancelled'=>'Пост отменён.','regenerating'=>'Создана новая версия. Предыдущее одобрение снято.','edited'=>'Текст сохранён в новой версии. Для публикации нужно повторное одобрение.'];
            if (!isset($messages[$status])) throw new \RuntimeException(match($status) {'stale'=>'Пост уже изменился. Обнови страницу.','expired'=>'Срок публикации поста истёк.',default=>'Действие недоступно в текущем состоянии поста. Обнови страницу.'});
            return $messages[$status];
        } finally {ConfigManager::getInstance()->flushCache();$db->query("SELECT RELEASE_LOCK('telegram_announcements')");}
    }
}
