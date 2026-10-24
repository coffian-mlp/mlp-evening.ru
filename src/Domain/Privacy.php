<?php
namespace Domain;

use Core\UserError;
use Infra\ConfigManager;
use Infra\Database;
use Infra\Transaction;

/** Consent decisions and account access; no browser fingerprinting. */
final class Privacy
{
    public const VERSION = '2026-10-16';
    public const COOKIE = 'mlp_privacy';
    private static array $locks = [];

    public static function version(): string
    {
        return self::VERSION . '-' . substr(hash('sha256', json_encode(self::operator(), JSON_UNESCAPED_UNICODE)), 0, 8);
    }

    public static function operator(): array
    {
        $config = ConfigManager::getInstance();
        return [
            'name' => $config->getOption('privacy_operator', 'Овчаров Константин Александрович'),
            'email' => $config->getOption('privacy_contact_email', '') ?: \Infra\Mailer::DEFAULT_FROM_EMAIL,
        ];
    }

    public static function account(int $userId): array
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare('SELECT state,policy_version,accepted_at,withdrawn_at,delete_after FROM account_privacy WHERE user_id=?');
        $stmt->bind_param('i',$userId);$stmt->execute();$state=$stmt->get_result()->fetch_assoc()?:['state'=>null];
        return (new UserManager())->privacyIdentity($userId) ? $state : ['state'=>'deleted'];
    }

    public static function isWithdrawn(int $userId): bool
    {
        return in_array(self::account($userId)['state'] ?? '', ['withdrawn', 'deleted'], true);
    }

    public static function isActive(int $userId): bool
    {
        $state = self::account($userId);
        return ($state['state'] ?? '') === 'active' && ($state['policy_version'] ?? '') === self::version();
    }

    /** Serialize account mutation against withdrawal/erasure; held until request completion. */
    public static function lock(string $key, int $wait = 5): void
    {
        if (isset(self::$locks[$key])) return;
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare('SELECT GET_LOCK(?,?) AS acquired');
        $stmt->bind_param('si', $key, $wait);
        $stmt->execute();
        if ((int)$stmt->get_result()->fetch_assoc()['acquired'] !== 1) {
            throw new UserError('Операция ещё выполняется. Попробуй снова через несколько секунд.');
        }
        self::$locks[$key] = true;
        register_shutdown_function(static function () use ($db, $key): void {
            $stmt = $db->prepare('SELECT RELEASE_LOCK(?)');
            $stmt->bind_param('s', $key);
            $stmt->execute();
        });
    }

    public static function lockAccount(int $userId): void { self::lock('privacy-user-' . $userId); }

    public static function unlockAccount(int $userId): void
    {
        $key='privacy-user-'.$userId;
        if (!isset(self::$locks[$key])) return;
        $db=Database::getInstance()->getConnection();
        $stmt=$db->prepare('SELECT RELEASE_LOCK(?)');$stmt->bind_param('s',$key);$stmt->execute();
        unset(self::$locks[$key]);
    }

    public static function payloadAllowed(array $payload): bool
    {
        foreach (['user_id','owner_id'] as $key) if (!empty($payload[$key]) && self::isWithdrawn((int)$payload[$key])) return false;
        $quotes=$payload['confirmation']['quotes'] ?? $payload['prepared_reply']['quotes'] ?? [];
        if(!is_array($quotes))$quotes=[];
        foreach (['message_id','source_message_id'] as $key) if (!empty($payload[$key])) $quotes[]=(int)$payload[$key];
        $ids=array_values(array_unique(array_filter(array_map('intval',is_array($quotes)?$quotes:[]))));
        if (!$ids) return true;
        foreach((new ChatManager())->privacySources($ids) as $row){
            if(($row['user_id']!==null && self::isWithdrawn((int)$row['user_id'])) || ($row['user_id']===null && $row['username']==='Удалённый пользователь'))return false;
        }
        return true;
    }

    public static function rememberSession(int $userId): void
    {
        $id=session_id();
        if (!preg_match('/^[a-zA-Z0-9,-]{1,128}$/D',$id)) return;
        $db=Database::getInstance()->getConnection();
        $stmt=$db->prepare('INSERT INTO privacy_sessions(session_id,user_id,updated_at) VALUES (?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),delete_pending=0,updated_at=VALUES(updated_at)');
        $stmt->bind_param('si',$id,$userId);$stmt->execute();
    }

    public static function actionAllowed(string $action, array $account): bool
    {
        if (in_array($action, ['get_chat_input','get_messages','search_messages','get_message_context','get_public_events',
            'get_poll','get_pinned','get_command_interaction','get_packs','get_stickers','heartbeat','ux_measure'],true)) return true;
        if (($account['state'] ?? '') !== 'withdrawn' && in_array($action, ['get_lyra_metrics','get_llm_usage','get_announcement_log','get_feedback','get_memory',
            'get_users','get_user_options','get_audit_logs','get_user_socials_admin','get_menu_items','db_get_row','db_export'],true)) return true;
        if (($account['state'] ?? '') === 'active' && ($account['policy_version'] ?? '') === self::version()) return true;
        if(($account['state']??'')==='withdrawn' && strtotime($account['delete_after'].' UTC')<=time())return in_array($action,['login','social_login','logout','privacy_status','privacy_choices','privacy_account','privacy_exit','leave'],true);
        return in_array($action, ['login','social_login','logout','update_profile','save_user_option','get_user_socials',
            'unlink_social','bind_social','privacy_account','privacy_choices','privacy_status','privacy_exit','leave'], true);
    }

    public static function tokenHash(): ?string
    {
        $raw = $_COOKIE[self::COOKIE] ?? '';
        return is_string($raw) && preg_match('/^[a-f0-9]{64}$/D', $raw) ? hash('sha256', $raw) : null;
    }

    public static function choices(): array
    {
        $empty = ['decided' => false, 'analytics' => false, 'external_media' => false];
        $hash = self::tokenHash();
        $db = Database::getInstance()->getConnection();
        $row=null;
        if($hash){
            $stmt=$db->prepare('SELECT c.analytics,c.external_media,o.user_id FROM privacy_choices c LEFT JOIN privacy_browser_owners o ON o.token_hash=c.token_hash WHERE c.token_hash=? AND c.expires_at>UTC_TIMESTAMP()');
            $stmt->bind_param('s',$hash);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();
        }
        $userId=Auth::userId();
        if($userId){
            self::lockAccount($userId);
            $stmt=$db->prepare('SELECT analytics,external_media FROM privacy_account_choices WHERE user_id=?');
            $stmt->bind_param('i',$userId);$stmt->execute();$account=$stmt->get_result()->fetch_assoc();
            // Carry an explicit guest/legacy-browser choice into the account once. Never copy another account's choice.
            if(!$account && $row && ($row['user_id']===null || (int)$row['user_id']===$userId)){
                self::saveChoices((bool)$row['analytics'],(bool)$row['external_media']);
                $account=['analytics'=>$row['analytics'],'external_media'=>$row['external_media']];
            }
            if($account){
                $analytics=(bool)$account['analytics'] && !self::isWithdrawn($userId);
                $external=(bool)$account['external_media'];
                if(!$row || (int)$row['user_id']!==$userId || (bool)$row['analytics']!==$analytics || (bool)$row['external_media']!==$external){
                    self::saveBrowserChoices($analytics,$external,$userId);
                }
                return ['decided'=>true,'analytics'=>$analytics,'external_media'=>$external];
            }
            return $empty;
        }
        if(!$row)return $empty;
        return ['decided' => true, 'analytics' => (bool)$row['analytics'], 'external_media' => (bool)$row['external_media']];
    }

    public static function saveChoices(bool $analytics, bool $external): array
    {
        $userId=Auth::userId();
        if($userId){
            self::lockAccount($userId);
            if(self::isWithdrawn($userId))$analytics=false;
            $db=Database::getInstance()->getConnection();$version=self::version();$a=(int)$analytics;$e=(int)$external;
            $stmt=$db->prepare('INSERT INTO privacy_account_choices(user_id,analytics,external_media,policy_version,updated_at) VALUES (?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE analytics=VALUES(analytics),external_media=VALUES(external_media),policy_version=VALUES(policy_version),updated_at=VALUES(updated_at)');
            $stmt->bind_param('iiis',$userId,$a,$e,$version);$stmt->execute();
            if(!$analytics){
                $stmt=$db->prepare('SELECT token_hash FROM privacy_browser_owners WHERE user_id=?');$stmt->bind_param('i',$userId);$stmt->execute();
                foreach($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $browser)UxStatistics::forgetBrowser($browser['token_hash']);
            }
        }
        self::saveBrowserChoices($analytics,$external,$userId?:null);
        return ['decided'=>true,'analytics'=>$analytics,'external_media'=>$external];
    }

    private static function saveBrowserChoices(bool $analytics,bool $external,?int $userId): void
    {
        $db = Database::getInstance()->getConnection();
        $raw = $_COOKIE[self::COOKIE] ?? '';
        if (!self::tokenHash()) $raw = bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);
        // Separate browser identifiers when switching accounts on a shared device.
        $owner=$db->prepare('SELECT user_id FROM privacy_browser_owners WHERE token_hash=?');$owner->bind_param('s',$hash);$owner->execute();
        $previous=$owner->get_result()->fetch_assoc();
        if($previous && (!$userId || (int)$previous['user_id']!==$userId)){
            if(!$analytics)UxStatistics::forgetBrowser($hash);
            $raw=bin2hex(random_bytes(32));$hash=hash('sha256',$raw);
        }
        self::lock('privacy-choice-' . substr($hash, 0, 40));
        Transaction::run($db, static function () use ($db, $hash, $analytics, $external,$userId): void {
            $version = self::version();
            $stmt = $db->prepare('INSERT INTO privacy_choices(token_hash,analytics,external_media,policy_version,updated_at,expires_at) VALUES (?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP()+INTERVAL 400 DAY) ON DUPLICATE KEY UPDATE analytics=VALUES(analytics),external_media=VALUES(external_media),policy_version=VALUES(policy_version),updated_at=VALUES(updated_at),expires_at=VALUES(expires_at)');
            $a = (int)$analytics; $e = (int)$external;
            $stmt->bind_param('siis', $hash, $a, $e, $version);
            $stmt->execute();
            if($userId){$owner=$db->prepare('INSERT INTO privacy_browser_owners(token_hash,user_id) VALUES (?,?) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id)');$owner->bind_param('si',$hash,$userId);$owner->execute();}
            if (!$analytics) {
                UxStatistics::forgetBrowser($hash);
                unset($_SESSION['ux_visit']);
            }
        });
        setcookie(self::COOKIE, $raw, ['expires'=>time()+400*86400,'path'=>'/','httponly'=>true,'samesite'=>'Lax',
            'secure'=>(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https']);
        $_COOKIE[self::COOKIE] = $raw;
    }

    public static function setAccountConsent(int $userId, bool $accept, bool $broadcast = true): array
    {
        self::lockAccount($userId);
        $db = Database::getInstance()->getConnection();
        Transaction::run($db, static function () use ($db, $userId, $accept): void {
            $current = self::account($userId);
            if (($current['state'] ?? '') === 'deleted') throw new UserError('Учётная запись уже удалена.');
            if ($accept && ($current['state'] ?? '') === 'withdrawn' && strtotime($current['delete_after'].' UTC') <= time()) throw new UserError('Срок восстановления истёк. Данные ожидают удаления.');
            if (!$accept && ($current['state'] ?? '') === 'withdrawn') return; // Preserve original deadline.
            $version = self::version();
            $state = $accept ? 'active' : 'withdrawn';
            $stmt = $db->prepare('INSERT INTO account_privacy(user_id,state,policy_version,accepted_at,withdrawn_at,delete_after) VALUES (?,?,?,IF(?=1,UTC_TIMESTAMP(),NULL),IF(?=0,UTC_TIMESTAMP(),NULL),IF(?=0,UTC_TIMESTAMP()+INTERVAL 29 DAY+INTERVAL 23 HOUR,NULL)) ON DUPLICATE KEY UPDATE state=VALUES(state),policy_version=VALUES(policy_version),accepted_at=IF(VALUES(state)="active",VALUES(accepted_at),accepted_at),withdrawn_at=VALUES(withdrawn_at),delete_after=VALUES(delete_after)');
            $flag = (int)$accept;
            $stmt->bind_param('issiii', $userId, $state, $version, $flag, $flag, $flag); $stmt->execute();
            $kind = $accept ? 'accepted' : 'withdrawn';
            $stmt = $db->prepare('INSERT INTO privacy_consent_events(user_id,kind,policy_version,created_at) VALUES (?,?,?,UTC_TIMESTAMP())');
            $stmt->bind_param('iss', $userId, $kind, $version); $stmt->execute();
            (new ChatManager())->touchPrivacy($userId);
            (new EpisodeManager())->suspendPrivacy($userId,!$accept);
            if (!$accept) {
                $identity=(new UserManager())->privacyIdentity($userId);
                if(!empty($identity['email'])){
                    $email=$identity['email'];$mail=$db->prepare('INSERT IGNORE INTO privacy_mail_cleanup(user_id,email,created_at) VALUES (?,?,UTC_TIMESTAMP())');$mail->bind_param('is',$userId,$email);$mail->execute();
                }
                (new UserManager())->clearPrivacyPresence($userId);
                (new CommandInteractionManager())->cancelPrivacy($userId);
                (new OnlineManager())->eraseUserData($userId);
                Auth::eraseUserData($userId);
                (new ReminderManager())->cancelPrivacy($userId);
            }
        });
        UserMention::invalidateDirectory();
        if (!$accept) self::saveChoices(false, false);
        if (!$accept) self::reviewLegacyFiles($userId);
        if($broadcast)(new ChatManager())->publishPrivacyChange($userId);
        return self::account($userId);
    }

    /** Register attributable local files, including replacements and unposted uploads. */
    public static function registerUpload(string $path, ?int $ownerId = null): void
    {
        $userId = $ownerId ?? Auth::userId();
        if (!$userId || $userId<1 || !preg_match('~^/upload/(?:avatars|chat|lyra)/[a-zA-Z0-9][a-zA-Z0-9_.-]*\.[a-zA-Z0-9]{1,10}$~D', $path)) return;
        $pending=(new UserManager())->privacyIdentity($userId)?0:1;
        if($pending)$userId=null;
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare('INSERT IGNORE INTO privacy_files(path,user_id,delete_pending,created_at) VALUES (?,?,?,UTC_TIMESTAMP())');
        $stmt->bind_param('sii', $path, $userId,$pending); $stmt->execute();
    }

    public static function redactRows(array $rows): array
    {
        if (!$rows) return [];
        $ids = array_unique(array_filter(array_map(static fn($row)=>(int)($row['user_id'] ?? 0), $rows)));
        if (!$ids) return $rows;
        $db = Database::getInstance()->getConnection();
        $hidden = $db->query('SELECT user_id FROM account_privacy WHERE state="withdrawn" AND user_id IN (' . implode(',', $ids) . ')')->fetch_all(MYSQLI_ASSOC);
        $hidden = array_fill_keys(array_column($hidden, 'user_id'), true);
        foreach ($rows as &$row) if (isset($hidden[$row['user_id'] ?? 0])) {
            $row['message'] = ''; $row['text'] = ''; $row['username'] = 'Неактивный участник';
            $row['user_id'] = null; $row['is_deleted'] = 1; $row['role'] = 'user';
            $row['avatar_url'] = '/assets/img/default-avatar.png'; $row['chat_color'] = '#888888';
            $row['quoted_msg_ids'] = null;
        }
        return $rows;
    }

    public static function sqlFilter(string $column): string {
        if(!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)?$/D',$column))throw new \InvalidArgumentException('Invalid privacy filter column');
        $rows=Database::getInstance()->getConnection()->query('SELECT user_id FROM account_privacy WHERE state="withdrawn"')->fetch_all(MYSQLI_ASSOC);
        $ids=array_map('intval',array_column($rows,'user_id'));
        return $ids?' AND ('.$column.' IS NULL OR '.$column.' NOT IN ('.implode(',',$ids).')) ':'';
    }
    public static function dueAccounts(): array {
        return Database::getInstance()->getConnection()->query('SELECT user_id FROM account_privacy WHERE state="withdrawn" AND delete_after<=UTC_TIMESTAMP() ORDER BY delete_after LIMIT 20')->fetch_all(MYSQLI_ASSOC);
    }
    public static function dueAccount(int $userId): bool {
        $db=Database::getInstance()->getConnection();$stmt=$db->prepare('SELECT 1 FROM account_privacy WHERE user_id=? AND state="withdrawn" AND delete_after<=UTC_TIMESTAMP() FOR UPDATE');
        $stmt->bind_param('i',$userId);$stmt->execute();return $stmt->get_result()->num_rows>0;
    }
    public static function stageErasure(int $userId,array $legacyPaths): void {
        $db=Database::getInstance()->getConnection();
        $stmt=$db->prepare('SELECT token_hash FROM privacy_browser_owners WHERE user_id=?');$stmt->bind_param('i',$userId);$stmt->execute();
        foreach($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $browser)UxStatistics::forgetBrowser($browser['token_hash']);
        $stmt=$db->prepare('DELETE FROM privacy_choices WHERE token_hash IN (SELECT token_hash FROM privacy_browser_owners WHERE user_id=?)');$stmt->bind_param('i',$userId);$stmt->execute();
        self::reviewLegacyFiles($userId,$legacyPaths);
        foreach(['DELETE FROM privacy_browser_owners WHERE user_id=?','DELETE FROM privacy_account_choices WHERE user_id=?','UPDATE privacy_sessions SET delete_pending=1,user_id=NULL WHERE user_id=?','UPDATE privacy_files SET delete_pending=1,user_id=NULL WHERE user_id=? AND delete_pending=0','UPDATE privacy_files SET user_id=NULL WHERE user_id=?','UPDATE privacy_mail_cleanup SET user_id=NULL WHERE user_id=?','DELETE FROM privacy_consent_events WHERE user_id=?','DELETE FROM account_privacy WHERE user_id=?'] as $sql){
            $stmt=$db->prepare($sql);$stmt->bind_param('i',$userId);$stmt->execute();
        }
    }
    public static function pendingFiles(): array {
        return Database::getInstance()->getConnection()->query('SELECT path FROM privacy_files WHERE delete_pending=1 LIMIT 200')->fetch_all(MYSQLI_ASSOC);
    }
    public static function finishFile(string $path): void {
        $db=Database::getInstance()->getConnection();$stmt=$db->prepare('DELETE FROM privacy_files WHERE path=? AND delete_pending=1');$stmt->bind_param('s',$path);$stmt->execute();
    }
    public static function pendingSessions(): array {
        $db=Database::getInstance()->getConnection();
        $owners=$db->query('SELECT DISTINCT user_id FROM privacy_sessions WHERE user_id IS NOT NULL')->fetch_all(MYSQLI_ASSOC);
        foreach($owners as $owner)if(!(new UserManager())->privacyIdentity((int)$owner['user_id'])){
            $id=(int)$owner['user_id'];$stmt=$db->prepare('UPDATE privacy_sessions SET user_id=NULL,delete_pending=1 WHERE user_id=?');$stmt->bind_param('i',$id);$stmt->execute();
        }
        return $db->query('SELECT session_id FROM privacy_sessions WHERE delete_pending=1 LIMIT 200')->fetch_all(MYSQLI_ASSOC);
    }
    public static function forgetSession(string $id): void {
        $db=Database::getInstance()->getConnection();$stmt=$db->prepare('DELETE FROM privacy_sessions WHERE session_id=?');$stmt->bind_param('s',$id);$stmt->execute();
    }
    public static function maintain(): void {
        $db=Database::getInstance()->getConnection();
        $db->query('DELETE FROM privacy_choices WHERE expires_at<UTC_TIMESTAMP()');
        $db->query('DELETE FROM privacy_browser_owners WHERE token_hash NOT IN (SELECT token_hash FROM privacy_choices)');
    }

    public static function reviewLegacyFiles(int $userId, ?array $paths = null): void {
        if($paths===null){$paths=(new ChatManager())->privacyExport($userId)['files'];$options=(new UserManager())->getUserOptions($userId);if(!empty($options['avatar_url']))$paths[]=$options['avatar_url'];}
        $db=Database::getInstance()->getConnection();
        foreach(array_unique($paths) as $path){
            if(!preg_match('~^/upload/(?:avatars|chat|lyra)/[a-zA-Z0-9][a-zA-Z0-9_.-]*\.[a-zA-Z0-9]{1,10}$~D',$path))continue;
            $stmt=$db->prepare('INSERT IGNORE INTO privacy_files(path,user_id,delete_pending,created_at) VALUES (?,?,2,UTC_TIMESTAMP())');$stmt->bind_param('si',$path,$userId);$stmt->execute();
        }
    }
    public static function fileReview(): array {
        return Database::getInstance()->getConnection()->query('SELECT path,user_id,created_at FROM privacy_files WHERE delete_pending=2 ORDER BY created_at LIMIT 100')->fetch_all(MYSQLI_ASSOC);
    }
    public static function withdrawals(): array {
        return Database::getInstance()->getConnection()->query('SELECT user_id,withdrawn_at,delete_after FROM account_privacy WHERE state="withdrawn" ORDER BY delete_after LIMIT 100')->fetch_all(MYSQLI_ASSOC);
    }
    public static function decideFile(string $path,bool $belongs): bool {
        $db=Database::getInstance()->getConnection();
        $sql=$belongs?'UPDATE privacy_files SET delete_pending=IF(user_id IS NULL,1,0) WHERE path=? AND delete_pending=2':'DELETE FROM privacy_files WHERE path=? AND delete_pending=2';
        $stmt=$db->prepare($sql);$stmt->bind_param('s',$path);$stmt->execute();
        return $stmt->affected_rows>0;
    }

    public static function exitWebsite(): void {
        if(Auth::userId())self::saveChoices(false,false);
        $hash=self::tokenHash();
        if($hash){
            self::lock('privacy-choice-'.substr($hash,0,40));
            $db=Database::getInstance()->getConnection();
            Transaction::run($db,static function() use($db,$hash): void {
                UxStatistics::forgetBrowser($hash);
                $stmt=$db->prepare('DELETE FROM privacy_choices WHERE token_hash=?');$stmt->bind_param('s',$hash);$stmt->execute();
                $stmt=$db->prepare('DELETE FROM privacy_browser_owners WHERE token_hash=?');$stmt->bind_param('s',$hash);$stmt->execute();
            });
        }
        (new OnlineManager())->removeSession(session_id());
        $params=session_get_cookie_params();$sessionName=session_name();
        Auth::logout();
        $secure=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https';
        setcookie(self::COOKIE,'',['expires'=>time()-3600,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
        setcookie($sessionName,'',['expires'=>time()-3600,'path'=>$params['path'],'domain'=>$params['domain'],'secure'=>$params['secure'],'httponly'=>$params['httponly'],'samesite'=>$params['samesite']??'Lax']);
        unset($_COOKIE[self::COOKIE]);
    }
    public static function pendingMail(): array {
        return Database::getInstance()->getConnection()->query('SELECT id,email FROM privacy_mail_cleanup ORDER BY id LIMIT 20')->fetch_all(MYSQLI_ASSOC);
    }
    public static function finishMail(int $id): void {
        $db=Database::getInstance()->getConnection();$stmt=$db->prepare('DELETE FROM privacy_mail_cleanup WHERE id=?');$stmt->bind_param('i',$id);$stmt->execute();
    }
}
