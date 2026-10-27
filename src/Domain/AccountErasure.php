<?php
namespace Domain;

use Infra\ConfigManager;
use Infra\Database;
use Infra\Transaction;

/** Erases one withdrawn account; other authors' posts and playlist history remain intact. */
final class AccountErasure
{
    public static function eraseDue(int $userId): bool
    {
        Privacy::lockAccount($userId);
        $db = Database::getInstance()->getConnection();
        $users=new UserManager();
        $result = Transaction::run($db, static function () use ($userId,$users): bool {
            $identity=$users->privacyIdentity($userId,true);
            if(!Privacy::dueAccount($userId))return false;
            $identity??=['login'=>'','nickname'=>'','email'=>null];
            $chat=new ChatManager();$export=$chat->privacyExport($userId);
            $options=$users->getUserOptions($userId);
            $files=$export['files'];if(!empty($options['avatar_url']))$files[]=$options['avatar_url'];
            (new \LLM\JobQueue())->eraseUserData($userId,$export['message_ids']);
            (new AnnouncementStore())->eraseChatEvidence($export['message_ids']);
            \LLM\LlmDebugLog::eraseAliases(array_merge([$identity['login'],$identity['nickname']],$export['aliases']));
            $chat->eraseUserData($userId);
            (new PollManager())->eraseUserData($userId);
            (new BotMemoryManager())->eraseUserData($userId);
            (new CommandInteractionManager())->eraseUserData($userId);
            (new FeedbackManager())->eraseUserData($userId);
            (new EpisodeManager())->eraseUserData($userId);
            (new StreamReactions())->eraseUserData($userId);
            (new LyraNotifications())->eraseUserData($userId);
            (new StreamMonitorStore())->eraseUserData($userId);
            (new ReminderManager())->eraseUserData($userId);
            (new OnlineManager())->eraseUserData($userId);
            (new \Social\SocialAuthService())->eraseUserData($userId);
            Auth::eraseUserData($userId);
            Privacy::stageErasure($userId,$files);
            $users->eraseUserData($userId);
            return true;
        });
        if($result)$users->invalidatePrivacyCache($userId);
        return $result;
    }

    public static function maintain(): void
    {
        $db = Database::getInstance()->getConnection();
        $failure=false;
        foreach (Privacy::dueAccounts() as $row) {
            try { self::eraseDue((int)$row['user_id']); }
            catch (\Throwable $error) { $failure=true;error_log('Account erasure deferred: user='.(int)$row['user_id'].' class='.get_class($error)); }
        }
        self::removeFiles();
        self::removeSessions();
        foreach(Privacy::pendingMail() as $mail){
            try { \Infra\Mailer::eraseRecipientLogs($mail['email']);Privacy::finishMail((int)$mail['id']); }
            catch(\Throwable $error){$failure=true;error_log('Mail privacy cleanup deferred: '.get_class($error));}
        }
        if ($failure) throw new \RuntimeException('Some due accounts require another attempt');
    }

    public static function removeFiles(): void
    {
        $root = dirname(__DIR__, 2); $failed=false;
        foreach (Privacy::pendingFiles() as $row) {
            $path = $row['path'];
            if (!preg_match('~^/upload/(?:avatars|chat|lyra)/[a-zA-Z0-9][a-zA-Z0-9_.-]*\.[a-zA-Z0-9]{1,10}$~D', $path)) {$failed=true;continue;}
            $full = $root . $path;
            $real = realpath($full);
            if ($real !== false && (!str_starts_with($real, $root . '/upload/') || is_link($full))) {$failed=true;continue;}
            if (is_file($full) && !unlink($full)) {$failed=true;continue;}
            Privacy::finishFile($path);
        }
        if($failed)throw new \RuntimeException('Some attributable files could not be removed');
    }

    public static function removeSessions(): void
    {
        $db=Database::getInstance()->getConnection();
        if (ini_get('session.save_handler') !== 'files') throw new \RuntimeException('Configure erasure for the selected session backend');
        $parts=explode(';',session_save_path() ?: sys_get_temp_dir()); $base=array_pop($parts); $depth=count($parts)>0?(int)$parts[0]:0;
        foreach(Privacy::pendingSessions() as $row) {
            $id=$row['session_id'];
            if(!preg_match('/^[a-zA-Z0-9,-]{1,128}$/D',$id)||!is_dir($base))throw new \RuntimeException('Invalid session erasure path');
            $directory=$base;
            for($i=0;$i<$depth;$i++)$directory.='/'.($id[$i]??'');
            $file=$directory.'/sess_'.$id;
            if(is_file($file)&&!unlink($file))throw new \RuntimeException('Cannot erase session file');
            Privacy::forgetSession($id);
        }
    }

}
