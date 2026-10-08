<?php
namespace Domain;

use Infra\Database;
use Infra\Transaction;

/** Durable revisions and delivery checkpoints for the Telegram editor. */
final class AnnouncementStore
{
    private \mysqli $db;

    public function __construct(?\mysqli $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    private function sql(string $sql, array $args = []): \mysqli_stmt
    {
        $stmt = $this->db->prepare($sql);
        if ($args) $stmt->bind_param(str_repeat('s', count($args)), ...$args);
        $stmt->execute();
        return $stmt;
    }

    private function rows(string $sql, array $args = []): array
    {
        return $this->sql($sql, $args)->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function option(string $name, string $default = ''): string
    {
        return $this->rows('SELECT value FROM site_options WHERE key_name=?', [$name])[0]['value'] ?? $default;
    }

    public function setOption(string $name, string $value): void
    {
        $this->sql('INSERT INTO site_options(key_name,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)', [$name,$value]);
    }

    /** Exactly one durable action per Telegram update, including its offset. */
    public function consume(int $id, callable $action): bool
    {
        return Transaction::run($this->db, function () use ($id,$action) {
            if (!$this->sql('INSERT IGNORE INTO telegram_announcement_updates VALUES(?,UTC_TIMESTAMP())', [$id])->affected_rows) return false;
            $action();
            $this->setOption('announcements_update_offset', (string)($id+1));
            return true;
        });
    }

    public function ensure(array $facts, string $fingerprint, array $schedule, int $initialNumber): int
    {
        return Transaction::run($this->db, function () use ($facts,$fingerprint,$schedule,$initialNumber) {
            $this->sql('INSERT IGNORE INTO site_options(key_name,value) VALUES("announcements_counter","0")');
            $counter = (int)$this->rows('SELECT value FROM site_options WHERE key_name="announcements_counter" FOR UPDATE')[0]['value'];
            $run = (string)$facts['occurrence']['run_id'];
            $old = $this->rows('SELECT * FROM telegram_announcement_campaigns WHERE run_id=? FOR UPDATE', [$run])[0] ?? null;
            if ($old) {
                if (!hash_equals($old['fingerprint'],$fingerprint)) {
                    $this->sql('UPDATE telegram_announcement_campaigns SET fingerprint=?,facts_json=?,start_at=? WHERE id=?', [$fingerprint,json_encode($facts,JSON_THROW_ON_ERROR),gmdate('Y-m-d H:i:s',(int)$facts['occurrence']['real_start_time']),$old['id']]);
                    foreach ($this->rows('SELECT p.*,r.* FROM telegram_announcement_posts p JOIN telegram_announcement_revisions r ON r.id=p.current_revision WHERE p.campaign_id=?', [$old['id']]) as $row) {
                        $due = $schedule[$row['kind'].'_at'];
                        $expires = $row['kind']==='main' ? $schedule['reminder_at'] : (int)$facts['occurrence']['real_start_time'];
                        $this->sql('UPDATE telegram_announcement_posts SET due_at=?,expires_at=? WHERE id=?', [gmdate('Y-m-d H:i:s',$due),gmdate('Y-m-d H:i:s',$expires),$row['post_id']]);
                        if (in_array($row['state'],['published','uncertain','sending'],true)) continue;
                        $this->newRevision((int)$row['post_id'],'Расписание или плейлист изменились. Требуется новый черновик и повторное согласование.',null,null);
                    }
                }
                return (int)$old['id'];
            }
            $last = (int)($this->rows('SELECT MAX(stream_number) AS n FROM telegram_announcement_campaigns')[0]['n'] ?? 0);
            $number = max($initialNumber,$last+1,$counter+1);
            if ($number < 1 || $number > 3999) throw new \RuntimeException('Stream number outside supported range');
            $this->sql('INSERT INTO telegram_announcement_campaigns(run_id,stream_number,start_at,fingerprint,facts_json) VALUES(?,?,?,?,?)', [$run,$number,gmdate('Y-m-d H:i:s',(int)$facts['occurrence']['real_start_time']),$fingerprint,json_encode($facts,JSON_THROW_ON_ERROR)]);
            $id = (int)$this->db->insert_id;
            $this->setOption('announcements_counter',(string)$number);
            foreach (['main','reminder'] as $kind) {
                $expires = $kind==='main' ? $schedule['reminder_at'] : (int)$facts['occurrence']['real_start_time'];
                $this->sql('INSERT INTO telegram_announcement_posts(campaign_id,kind,due_at,expires_at) VALUES(?,?,?,?)', [$id,$kind,gmdate('Y-m-d H:i:s',$schedule[$kind.'_at']),gmdate('Y-m-d H:i:s',$expires)]);
                $this->newRevision((int)$this->db->insert_id,'',null,null);
            }
            return $id;
        });
    }

    private function newRevision(int $postId, string $feedback, ?string $caption, ?string $image): int
    {
        $post = $this->rows('SELECT * FROM telegram_announcement_posts WHERE id=? FOR UPDATE',[$postId])[0];
        $old = $post['current_revision'] ? $this->revision((int)$post['current_revision']) : null;
        if ($old) $this->sql('UPDATE telegram_announcement_revisions SET state="superseded" WHERE id=?',[$old['id']]);
        $version = $old ? (int)$old['version']+1 : 1;
        $state = $caption === null ? 'text_pending' : ($image === null ? 'image_pending' : 'preview_pending');
        $this->sql('INSERT INTO telegram_announcement_revisions(post_id,version,nonce,state,caption,previous_caption,image_path,feedback,created_at) VALUES(?,?,?,?,?,?,?,?,UTC_TIMESTAMP())',[$postId,$version,bin2hex(random_bytes(8)),$state,$caption,$old['caption'] ?? $old['previous_caption'] ?? null,$image,$feedback]);
        $id = (int)$this->db->insert_id;
        $this->sql('UPDATE telegram_announcement_posts SET current_revision=? WHERE id=?',[$id,$postId]);
        return $id;
    }

    public function revision(int $id): ?array
    {
        return $this->rows('SELECT r.*,p.kind,p.current_revision,p.due_at,p.expires_at,c.run_id,c.stream_number,c.fingerprint,c.facts_json FROM telegram_announcement_revisions r JOIN telegram_announcement_posts p ON p.id=r.post_id JOIN telegram_announcement_campaigns c ON c.id=p.campaign_id WHERE r.id=?',[$id])[0] ?? null;
    }

    public function active(): array
    {
        return $this->rows('SELECT r.id FROM telegram_announcement_revisions r JOIN telegram_announcement_posts p ON p.current_revision=r.id WHERE r.state NOT IN ("superseded","published","expired","cancelled") ORDER BY p.due_at,r.id');
    }

    /** Approval and regeneration reject old, forged and already delivered revisions. */
    public function action(int $id, string $nonce, string $action, string $feedback = '', ?int $now = null): string
    {
        $now ??= time();
        return Transaction::run($this->db, function () use ($id,$nonce,$action,$feedback,$now) {
            $this->sql('SELECT id FROM telegram_announcement_revisions WHERE id=? FOR UPDATE',[$id]);
            $r = $this->revision($id);
            if (!$r || (int)$r['current_revision']!==$id || !hash_equals($r['nonce'],$nonce)) return 'stale';
            if ($now >= strtotime($r['expires_at'].' UTC')) return 'expired';
            if (!in_array($r['state'],['pending','approved','failed','feedback_text','feedback_image','feedback_both'],true)) return 'unavailable';
            if ($action==='approve') {
                if ($r['state']==='approved') return 'approved';
                if ($r['state']!=='pending' || !$r['caption'] || !$r['image_path'] || !$r['preview_photo_id']) return 'unavailable';
                $this->sql('UPDATE telegram_announcement_revisions SET state="approved",approved_at=? WHERE id=?',[gmdate('Y-m-d H:i:s',$now),$id]);
                return 'approved';
            }
            if (in_array($action,['ask_text','ask_image','ask_both'],true)) {
                $this->sql('UPDATE telegram_announcement_revisions SET state=?,approved_at=NULL WHERE id=?',['feedback_'.substr($action,4),$id]);
                return 'awaiting_feedback';
            }
            if ($action==='cancel') {
                $this->sql('UPDATE telegram_announcement_revisions SET state="cancelled",approved_at=NULL WHERE id=?',[$id]);
                return 'cancelled';
            }
            if (in_array($action,['text','image','both'],true)) {
                $this->newRevision((int)$r['post_id'],mb_substr($feedback,0,2000),$action==='image'?$r['caption']:null,$action==='text'?$r['image_path']:null);
                return 'regenerating';
            }
            return 'unavailable';
        });
    }

    public function findPreview(int $messageId): ?array
    {
        return $this->rows('SELECT id,nonce FROM telegram_announcement_revisions WHERE preview_photo_id=? OR preview_controls_id=? ORDER BY id DESC LIMIT 1',[$messageId,$messageId])[0] ?? null;
    }

    public function change(int $id, string $expected, string $state, array $values = []): bool
    {
        if (array_key_exists('caption',$values) && $expected !== 'text_generating') throw new \LogicException('Caption is immutable outside generation');
        if (array_key_exists('image_path',$values) && $expected !== 'image_generating') throw new \LogicException('Image is immutable outside generation');
        $allowed = ['caption','image_path','preview_photo_id','preview_controls_id','channel_message_id','last_error','retry_at','attempts'];
        $set = ['state=?']; $args = [$state];
        foreach ($values as $key=>$value) {
            if (!in_array($key,$allowed,true)) throw new \InvalidArgumentException('Unknown revision field');
            $set[] = "$key=?"; $args[]=$value;
        }
        $args[]=$id; $args[]=$expected;
        return $this->sql('UPDATE telegram_announcement_revisions SET '.implode(',',$set).' WHERE id=? AND state=?',$args)->affected_rows===1;
    }

    /** Crash after an HTTP send started must never trigger a blind resend. */
    public function recover(): void
    {
        $this->sql('UPDATE telegram_announcement_revisions SET state="uncertain",last_error="delivery_interrupted" WHERE state IN ("sending","preview_sending","controls_sending")');
        $this->sql('UPDATE telegram_announcement_revisions SET state="text_pending" WHERE state="text_generating"');
        $this->sql('UPDATE telegram_announcement_revisions SET state="image_pending" WHERE state="image_generating"');
    }
}
