<?php
namespace Domain;
use Core\UserError;
use Infra\Database;
use Infra\Transaction;

/** Short-lived anonymous events; account linkage exists only in the rate limiter. */
final class StreamReactions
{
    public const CATALOG = [
        'heart'=>['❤️','Нравится'], 'laugh'=>['😂','Смешно'], 'wow'=>['😮','Удивительно'], 'fire'=>['🔥','Огонь'],
        'skull'=>['💀','Наповал'], 'clown'=>['🤡','Клоун'], 'hundred'=>['💯','Сто процентов'], 'poop'=>['💩','Не нравится'],
    ];
    public const LIFETIME = 8;
    private \mysqli $db;
    public function __construct(){ $this->db=Database::getInstance()->getConnection(); }

    public function send(int $userId,string $reaction): array
    {
        if(!isset(self::CATALOG[$reaction]))throw new UserError('Такой эмоции нет в наборе.');
        if($userId<=0)throw new UserError('Войди, чтобы отправлять эмоции.');
        Privacy::lockAccount($userId);
        if(!Privacy::isActive($userId))throw new UserError('Подтверди согласие на данные аккаунта в профиле.');
        $lock=$this->db->query("SELECT GET_LOCK('stream_reactions_send',1) n")->fetch_assoc();
        if((int)$lock['n']!==1)throw new UserError('Эмоций сейчас много. Попробуй через несколько секунд.');
        try{
            $event=Transaction::run($this->db,function() use($userId,$reaction){
                $s=$this->db->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$s->bind_param('i',$userId);$s->execute();
                if(!$s->get_result()->fetch_assoc())throw new UserError('Участник не найден.');
                (new ChatManager())->assertCanSend($userId,true);
                $s=$this->db->prepare('SELECT last_at>UTC_TIMESTAMP()-INTERVAL 3 SECOND AS limited FROM stream_reaction_limits WHERE user_id=?');$s->bind_param('i',$userId);$s->execute();
                if((int)($s->get_result()->fetch_assoc()['limited']??0))throw new UserError('Следующую эмоцию можно отправить через 3 секунды.');
                $recent=$this->db->query('SELECT COUNT(*) n FROM stream_reactions WHERE created_at>UTC_TIMESTAMP()-INTERVAL 2 SECOND')->fetch_assoc();
                if((int)$recent['n']>=20)throw new UserError('Эмоций сейчас много. Попробуй через несколько секунд.');
                $s=$this->db->prepare('INSERT INTO stream_reactions(reaction,created_at) VALUES(?,UTC_TIMESTAMP())');$s->bind_param('s',$reaction);$s->execute();$id=$this->db->insert_id;
                $s=$this->db->prepare('INSERT INTO stream_reaction_limits(user_id,last_at) VALUES(?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE last_at=VALUES(last_at)');$s->bind_param('i',$userId);$s->execute();
                $s=$this->db->prepare('SELECT id,reaction,UNIX_TIMESTAMP(created_at) created_at FROM stream_reactions WHERE id=?');$s->bind_param('i',$id);$s->execute();
                return self::event($s->get_result()->fetch_assoc());
            });
        }finally{$this->db->query("SELECT RELEASE_LOCK('stream_reactions_send')");}
        if(\Infra\Env::get('CHAT_DRIVER','sse')==='centrifugo'){
            try{(new \Infra\CentrifugoService(2))->publish('public:chat',$event);}catch(\Throwable $error){error_log('Stream reaction realtime unavailable');}
        }
        return $event;
    }

    public static function event(array $row): array
    {
        return ['type'=>'stream_reaction','event_id'=>'sr-'.(int)$row['id'],'reaction'=>$row['reaction'],'expires_at'=>((int)$row['created_at']+self::LIFETIME)*1000];
    }
    public function latestId(): int {return (int)$this->db->query('SELECT COALESCE(MAX(id),0) id FROM stream_reactions')->fetch_assoc()['id'];}
    public function after(int $id): array
    {
        $s=$this->db->prepare('SELECT id,reaction,UNIX_TIMESTAMP(created_at) created_at FROM stream_reactions WHERE id>? AND created_at>=UTC_TIMESTAMP()-INTERVAL 8 SECOND ORDER BY id LIMIT 30');
        $s->bind_param('i',$id);$s->execute();return $s->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    public function eraseUserData(int $userId): void
    {
        $s=$this->db->prepare('DELETE FROM stream_reaction_limits WHERE user_id=?');$s->bind_param('i',$userId);$s->execute();
    }
    public function purgeExpired(): void
    {
        $this->db->query('DELETE FROM stream_reactions WHERE created_at<UTC_TIMESTAMP()-INTERVAL 1 MINUTE LIMIT 500');
        $this->db->query('DELETE FROM stream_reaction_limits WHERE last_at<UTC_TIMESTAMP()-INTERVAL 1 DAY LIMIT 500');
    }
}
