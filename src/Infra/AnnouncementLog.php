<?php
namespace Infra;

use Social\TelegramBotException;

/** Bounded diagnostic history; no captions, destinations, tokens or exception messages. */
final class AnnouncementLog
{
    public function __construct(private ?string $path = null) {}

    /** Newest entries first, including the retained rotation. Reads share the writer's lock. */
    public function recent(int $limit = 100, bool $errorsOnly = true): array
    {
        $limit=max(1,min(100,$limit));
        $path=$this->path??dirname(__DIR__,2).'/logs/announcements.log';
        if (!file_exists($path) && !file_exists($path.'.1')) return [];
        $lock=null;
        try {
            if (is_link($path.'.lock')) throw new \RuntimeException('Unsafe log lock');
            $lock=@fopen($path.'.lock','r');
            if (!$lock || !flock($lock,LOCK_SH)) throw new \RuntimeException('Log lock unavailable');
            $entries=[];
            foreach ([$path,$path.'.1'] as $file) {
                if (!file_exists($file)) continue;
                if (is_link($file) || !is_file($file)) throw new \RuntimeException('Unsafe log file');
                $handle=@fopen($file,'rb');
                if (!$handle) throw new \RuntimeException('Log unavailable');
                try {
                    $size=fstat($handle)['size'];
                    $offset=max(0,$size-1048576);
                    if ($offset && fseek($handle,$offset)!==0) throw new \RuntimeException('Log seek failed');
                    $tail=stream_get_contents($handle,1048576);
                    if ($tail===false) throw new \RuntimeException('Log read failed');
                } finally { fclose($handle); }
                $lines=explode("\n",$tail);
                if ($offset) array_shift($lines);
                // Ignore an incomplete final record left by an interrupted write.
                array_pop($lines);
                foreach (array_reverse($lines) as $line) {
                    $row=json_decode($line,true);
                    if (!is_array($row) || !is_string($row['event']??null)) continue;
                    if ($errorsOnly && !isset($row['error_type']) && !str_ends_with($row['event'],'_failed')) continue;
                    $entries[]=$row;
                    if (count($entries)>=$limit) return $entries;
                }
            }
            return $entries;
        } finally {
            if (is_resource($lock)) { flock($lock,LOCK_UN); fclose($lock); }
        }
    }

    public function record(string $event, array $revision = [], ?\Throwable $error = null, array $outcome = []): bool
    {
        $entry = ['at'=>gmdate('Y-m-d\TH:i:s\Z'),'event'=>preg_match('/\A[a-z_]{1,40}\z/D',$event)?$event:'unknown'];
        foreach (['id'=>'revision_id','post_id'=>'post_id','stream_number'=>'stream_number','version'=>'version'] as $key=>$name) {
            if (isset($revision[$key])) $entry[$name]=(int)$revision[$key];
        }
        if (in_array($revision['kind']??null,['main','reminder'],true)) $entry['kind']=$revision['kind'];
        foreach (['stage','next_state'] as $key) {
            if (in_array($outcome[$key]??null,['sending','preview_sending','controls_sending','approved','preview_pending','controls_pending','failed','uncertain'],true)) $entry[$key]=$outcome[$key];
        }
        foreach (['attempts','message_id'] as $key) if (isset($outcome[$key])) $entry[$key]=(int)$outcome[$key];
        if (isset($outcome['retry_at']) && preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/D',$outcome['retry_at'])) $entry['retry_at']=$outcome['retry_at'];
        if ($error !== null) $entry['error_type']=$error instanceof TelegramBotException?'telegram':'worker';
        if ($error instanceof TelegramBotException) {
            $entry['uncertain']=$error->uncertain;
            $details=$error->diagnostics;
            $entry['reason']=in_array($details['reason']??null,['network','transport_exception','rate_limit','http_rejected','service_response','invalid_response','api_rejected','invalid_result','missing_message_id','invalid_caption','invalid_photo'],true)?$details['reason']:'unspecified';
            foreach (['http_status','curl_errno','api_code'] as $key) if (isset($details[$key])) $entry[$key]=max(0,min(9999,(int)$details[$key]));
        }
        $line=json_encode($entry,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
        $path=$this->path??dirname(__DIR__,2).'/logs/announcements.log';
        $lock=null;
        $mask=umask(0027);
        try {
            if (!is_dir(dirname($path)) && !@mkdir(dirname($path),0750,true) && !is_dir(dirname($path))) throw new \RuntimeException('Log directory unavailable');
            $lock=@fopen($path.'.lock','c');
            if (!$lock || !flock($lock,LOCK_EX)) throw new \RuntimeException('Log lock unavailable');
            clearstatcache(true,$path);
            if (is_file($path) && filesize($path)+strlen($line)>1048576 && !@rename($path,$path.'.1')) throw new \RuntimeException('Log rotation unavailable');
            if (@file_put_contents($path,$line,FILE_APPEND)!==strlen($line)) throw new \RuntimeException('Log write failed');
            return true;
        } catch (\Throwable $ignored) {
            // syslog remains available when cron discards stderr or the log directory is unwritable.
            syslog(LOG_ERR,'mlp-announcements journal fallback '.$line);
            return false;
        } finally {
            if (is_resource($lock)) { flock($lock,LOCK_UN); fclose($lock); }
            umask($mask);
        }
    }
}
