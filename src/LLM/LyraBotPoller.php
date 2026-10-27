<?php
namespace LLM;

use Domain\AnnouncementStore;
use Domain\LyraNotifications;
use Domain\StreamMonitor;
use Infra\Database;
use Infra\AnnouncementLog;
use Social\TelegramBotClient;
use Core\UserError;

/** The only getUpdates consumer for this bot, shared by links, incidents and announcement editing. */
final class LyraBotPoller
{
    public function __construct(private AnnouncementStore $store,private TelegramBotClient $client,private ?AnnouncementWorker $editor=null,private $writer=null,private int $pollTimeout=0) {}

    public function tick(?int $now=null): void
    {
        $now??=time();$db=Database::getInstance()->getConnection();
        if(!(int)$db->query("SELECT GET_LOCK('lyra_telegram_poll',0) AS ok")->fetch_assoc()['ok'])return;
        try {
            $hash=$this->client->credentialFingerprint();$bot=$this->store->pollingBotId($hash);
            if($bot===null){
                $me=$this->client->request('getMe');
                if(empty($me['is_bot'])||!is_int($me['id']??null)||$me['id']<=0)throw new \RuntimeException('Invalid bot identity');
                $bot=$me['id'];$this->store->activatePollingBot($bot,$hash);
                if(preg_match('/^[A-Za-z0-9_]{5,32}$/D',$me['username']??'')){
                    $this->store->setOption('lyra_bot_verified_username',$me['username']);
                    $this->store->setOption('lyra_bot_verified_username_hash',$hash);
                }
            }
            $updates=$this->client->request('getUpdates',['offset'=>$this->store->pollingOffset($bot),'timeout'=>$this->pollTimeout]);
            foreach($updates as $update){
                if(!is_array($update)||!is_int($update['update_id']??null))continue;
                $reply=null;$ownerReply=null;
                $accepted=$this->store->consume($update['update_id'],function()use($update,$now,$hash,&$reply,&$ownerReply){
                    $reply=$this->route($update,$hash,$now);
                    if($reply===null && $this->editor!==null)$ownerReply=$this->editor->handleIncoming($update,$now);
                },$bot);
                if(!$accepted)continue;
                if($reply!==null)$this->respond($update,$reply);
                elseif($ownerReply!==null)$this->editor->respondIncoming($update,$ownerReply);
            }
        } finally { $db->query("DO RELEASE_LOCK('lyra_telegram_poll')"); }
    }

    private function route(array $update,string $hash,int $now): ?string
    {
        $callback=$update['callback_query']??null;$message=$callback['message']??$update['message']??[];
        $sender=(string)($callback['from']['id']??$message['from']['id']??'');$chat=(string)($message['chat']['id']??'');
        if(($message['chat']['type']??'')!=='private'||$sender===''||$sender!==$chat)return null;
        $notifications=new LyraNotifications();$text=(string)($message['text']??'');
        if(!$callback&&preg_match('/^\/start notify_([a-f0-9]{48})$/D',$text,$m)){
            return $notifications->accept($m[1],$sender,$chat,$hash,$now)
                ?'Уведомления подключены. Теперь я смогу писать тебе о сбоях эфира, если ты назначен стримером события. Вернись в профиль — там появится подтверждение.'
                :'Не удалось подключить уведомления. Открой новую ссылку из профиля и используй тот же Telegram, который привязан на сайте.';
        }
        if($callback&&str_starts_with((string)($callback['data']??''),'stream:')){
            $actor=$notifications->actor($sender,$chat,$hash);
            if(!$actor)return 'Подключение к профилю недоступно. Проверь уведомления Лиры в профиле сайта.';
            try{
                $outcome=(new StreamMonitor())->telegramAction((string)$callback['data'],$actor,$now,['sender'=>$sender,'chat'=>$chat,'fingerprint'=>$hash]);
                return $outcome['code']==='intentional'?'Принято: эфир остановлен намеренно. Дальнейшие тревоги отключены.':'Принято: ты видишь проблему и занимаешься восстановлением. Зрителям сообщу, напоминание в канал отменено.';
            }catch(UserError $e){return $e->getMessage();}
        }
        return null;
    }

    private function respond(array $update,string $fallback): void
    {
        try {
            $instruction='Коротко подтверди результат служебного действия от своего лица. Сохрани все факты и смысл, без новых обещаний: '.$fallback;
            try {
                $text=$this->writer?($this->writer)($instruction):(new LLMManager())->liveTextBounded($instruction,null,time()+8,6,null,
                    ['actor_id'=>0,'include_pinned'=>false,'data'=>['original_query'=>'Подключение личных уведомлений и ответ стримера']]);
            }catch(\Throwable $ignored){$text=null;}
            if(!is_string($text)||trim($text)===''||mb_strlen($text)>700)$text=$fallback;
            $callback=$update['callback_query']??null;
            if($callback)$this->client->request('answerCallbackQuery',['callback_query_id'=>$callback['id'],'text'=>mb_substr($text,0,190),'show_alert'=>true]);
            else $this->client->request('sendMessage',['chat_id'=>$update['message']['chat']['id'],'text'=>$text]);
        }catch(\Throwable $e){(new AnnouncementLog())->record('notification_reply_failed',[],$e);}
    }
}
