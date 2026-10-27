<?php
namespace Domain;

use Core\UserError;
use Infra\ConfigManager;
use Infra\Database;
use Infra\Transaction;

/** Private bot access is independent of social login and always matches its verified identity. */
final class LyraNotifications
{
    private \mysqli $db;
    public function __construct() { $this->db = Database::getInstance()->getConnection(); }

    private function sql(string $sql, array $values = []): \mysqli_stmt
    {
        $s = $this->db->prepare($sql);
        if ($values) $s->bind_param(str_repeat('s', count($values)), ...$values);
        $s->execute(); return $s;
    }

    private function social(int $user): ?string
    {
        $r = $this->sql("SELECT provider_uid FROM user_socials WHERE user_id=? AND provider='telegram'", [$user])->get_result();
        if ($r->num_rows !== 1) return null;
        $uid = (string)$r->fetch_assoc()['provider_uid'];
        return preg_match('/^[1-9][0-9]{0,18}$/D', $uid) ? $uid : null;
    }

    public static function fingerprint(): string
    {
        $token = AnnouncementSettings::values()['announcements_token'];
        return $token === '' ? '' : hash('sha256', $token);
    }

    public function status(int $user): array
    {
        $eligible=$this->eligible($user);
        $uid = $this->social($user);
        $r = $this->sql('SELECT * FROM lyra_notification_links WHERE user_id=?', [$user])->get_result()->fetch_assoc();
        $ready = $eligible && $uid !== null && $r && $r['chat_id'] !== null && $r['disabled_reason'] === null
            && $r['telegram_uid'] === $uid && hash_equals($r['bot_fingerprint'], self::fingerprint());
        return ['eligible'=>$eligible,'telegram_linked'=>$uid !== null, 'configured'=>self::fingerprint() !== '', 'connected'=>(bool)$ready];
    }

    public function eligible(int $user): bool
    {
        $row=(new UserManager())->getUserById($user);
        return $row!==null&&UserRoles::has($row,'streamer');
    }

    public function begin(int $user, ?array $identity = null, ?int $now = null): string
    {
        $now ??= time();
        if(!$this->eligible($user))throw new UserError('Уведомления эфира доступны пользователям с ролью стримера.');
        if (Privacy::isWithdrawn($user)) throw new UserError('Сначала восстанови согласие на обработку данных.');
        $uid = $this->social($user);
        if ($uid === null) throw new UserError('Сначала привяжи Telegram в профиле.');
        $settings = AnnouncementSettings::values();
        if ($settings['announcements_token'] === '') throw new UserError('Администратор ещё не подключил бота Лиры.');
        if($identity===null){
            $store=new AnnouncementStore();$cachedId=$store->pollingBotId(self::fingerprint());
            $cachedName=$store->option('lyra_bot_verified_username','');
            $nameHash=$store->option('lyra_bot_verified_username_hash','');
            $identity=$cachedId&&hash_equals(self::fingerprint(),$nameHash)&&preg_match('/^[A-Za-z0-9_]{5,32}$/D',$cachedName)?['is_bot'=>true,'id'=>$cachedId,'username'=>$cachedName]:AnnouncementSettings::client($settings)->request('getMe');
        }
        if (empty($identity['is_bot']) || !is_int($identity['id']??null) || $identity['id']<=0 || !preg_match('/^[A-Za-z0-9_]{5,32}$/D', $identity['username'] ?? '')) throw new \RuntimeException('Invalid bot identity');
        $token = bin2hex(random_bytes(24)); $hash = hash('sha256', $token);
        $expiry = gmdate('Y-m-d H:i:s', $now + 600); $fingerprint = self::fingerprint();
        $this->sql('INSERT INTO lyra_notification_links(user_id,telegram_uid,bot_fingerprint,token_hash,token_expires) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE telegram_uid=VALUES(telegram_uid),bot_fingerprint=VALUES(bot_fingerprint),chat_id=NULL,connected_at=NULL,token_hash=VALUES(token_hash),token_expires=VALUES(token_expires),disabled_reason=NULL', [$user,$uid,$fingerprint,$hash,$expiry]);
        return 'https://t.me/'.$identity['username'].'?start=notify_'.$token;
    }

    public function accept(string $token, string $sender, string $chat, string $fingerprint, ?int $now = null): bool
    {
        $now ??= time();
        if (!preg_match('/^[a-f0-9]{48}$/D', $token) || $sender !== $chat || !hash_equals(self::fingerprint(), $fingerprint)) return false;
        return Transaction::run($this->db, function () use ($token,$sender,$chat,$fingerprint,$now): bool {
            $r = $this->sql('SELECT * FROM lyra_notification_links WHERE token_hash=? FOR UPDATE', [hash('sha256',$token)])->get_result()->fetch_assoc();
            if (!$r || strtotime($r['token_expires'].' UTC') <= $now || $r['telegram_uid'] !== $sender
                || !hash_equals($r['bot_fingerprint'],$fingerprint) || $this->social((int)$r['user_id']) !== $sender
                || Privacy::isWithdrawn((int)$r['user_id']) || !$this->eligible((int)$r['user_id'])) return false;
            $this->sql('UPDATE lyra_notification_links SET chat_id=?,connected_at=?,token_hash=NULL,token_expires=NULL,disabled_reason=NULL WHERE user_id=?', [$chat,gmdate('Y-m-d H:i:s',$now),$r['user_id']]);
            return true;
        });
    }

    public function disconnect(int $user): void
    {
        $this->sql('UPDATE lyra_notification_links SET chat_id=NULL,connected_at=NULL,token_hash=NULL,token_expires=NULL,disabled_reason=? WHERE user_id=?', ['disconnected',$user]);
    }

    public function contact(int $user): ?string
    {
        if (!$this->status($user)['connected'] || Privacy::isWithdrawn($user)) return null;
        return (string)$this->sql('SELECT chat_id FROM lyra_notification_links WHERE user_id=?',[$user])->get_result()->fetch_assoc()['chat_id'];
    }

    public function actor(string $sender, string $chat, string $fingerprint): ?int
    {
        if ($sender !== $chat || !hash_equals(self::fingerprint(),$fingerprint)) return null;
        $r = $this->sql('SELECT user_id FROM lyra_notification_links WHERE chat_id=? AND telegram_uid=? AND bot_fingerprint=? AND disabled_reason IS NULL',[$chat,$sender,$fingerprint])->get_result();
        if ($r->num_rows !== 1) return null;
        $id = (int)$r->fetch_assoc()['user_id'];
        return $this->contact($id) === $chat ? $id : null;
    }

    /** Locking revalidation after waiting for an incident; never trust an older Telegram update snapshot. */
    public function currentActor(string $sender,string $chat,string $fingerprint): ?int
    {
        if($sender!==$chat)return null;
        $token=$this->sql("SELECT value FROM site_options WHERE key_name='announcements_token' FOR UPDATE")->get_result()->fetch_assoc()['value']??\Infra\Env::get('TELEGRAM_ANNOUNCEMENTS_TOKEN','');
        if($token===''||!hash_equals(hash('sha256',$token),$fingerprint))return null;
        $rows=$this->sql("SELECT l.user_id,u.role,u.roles_json FROM lyra_notification_links l JOIN user_socials s ON s.user_id=l.user_id AND s.provider='telegram' AND s.provider_uid=BINARY l.telegram_uid JOIN users u ON u.id=l.user_id LEFT JOIN account_privacy p ON p.user_id=l.user_id WHERE l.telegram_uid=? AND l.chat_id=? AND l.bot_fingerprint=? AND l.disabled_reason IS NULL AND (p.state IS NULL OR p.state NOT IN ('withdrawn','deleted')) FOR UPDATE",[$sender,$chat,$fingerprint])->get_result();
        if($rows->num_rows!==1)return null;
        $row=$rows->fetch_assoc();return UserRoles::has($row,'streamer')?(int)$row['user_id']:null;
    }

    public function eraseUserData(int $user): void { $this->sql('DELETE FROM lyra_notification_links WHERE user_id=?',[$user]); }
}
