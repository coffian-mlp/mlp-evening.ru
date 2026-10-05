<?php
namespace Domain;
use Infra\Database;
use Infra\ConfigManager;
use Infra\Transaction;
class EpisodeManager {
    private const DAILY_LIMIT  =  3;
    private const COOLDOWN_SECONDS  =  604800;
    private \mysqli $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    private function sql(string $sql, array $args = []): \mysqli_stmt {
        $s = $this->db->prepare($sql);
        if ($args) $s->bind_param(str_repeat('s', count($args)), ...$args);
        $s->execute();
        return $s;
    }

    private function rows(string $sql, array $args = []): array {
        return $this->sql($sql, $args)->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function option(string $key): ?string {
        $r = $this->rows('SELECT value FROM site_options WHERE key_name=?', [$key]);
        return $r[0]['value'] ?? null;
    }

    private function setOption(string $key, string $value): void {
        if (!ConfigManager::getInstance()->setOption($key, $value)) throw new \RuntimeException('Option save failed');
    }

    private function tx(callable $fn): mixed {
        try {
            return Transaction::run($this->db, $fn);
        }
        finally {
            ConfigManager::getInstance()->flushCache();
        }
    }

    public function getAllEpisodes() {
        return $this->rows('SELECT e.*, e.legacy_wanna_watch+(SELECT COUNT(*) FROM episode_wishes w WHERE w.episode_id=e.ID AND w.status="active") AS WANNA_WATCH FROM episode_list e ORDER BY e.ID');
    }

    private function generateWeightedPlaylist($limit) {
        return PlaylistSelector::select(EpisodeCatalog::normalize($this->getAllEpisodes()), (int)$limit);
    }

    public function getSnapshot(int $snapshotId): ?array {
        $r = $this->rows('SELECT * FROM playlist_snapshots WHERE id=?', [$snapshotId])[0] ?? null;
        if (!$r)return null;
        $r['stories'] = json_decode($r['payload_json'], true);
        $r['id'] = (int)$r['id'];
        return $r;
    }

    public function getOccurrenceSnapshot(string $runId): ?array {
        $r = $this->rows('SELECT snapshot_id FROM playlist_occurrences WHERE run_id=?', [$runId]);
        return isset($r[0])?$this->getSnapshot((int)$r[0]['snapshot_id']):null;
    }

    public function getCurrentSnapshot(): ?array {
        return $this->getSnapshot((int)$this->option('current_playlist_snapshot_id'));
    }

    /** Serialize initial import, publication and occurrence binding on one persistent row. */
    private function publicationLock(): int {
        $this->sql('INSERT INTO site_options(key_name,value) VALUES("current_playlist_snapshot_id","0") ON DUPLICATE KEY UPDATE key_name=VALUES(key_name)');
        return (int)$this->rows('SELECT value FROM site_options WHERE key_name="current_playlist_snapshot_id" FOR UPDATE')[0]['value'];
    }

    public function importLegacySnapshot(): ?array {
        return $this->tx(function () {
            $currentId = $this->publicationLock();
            if ($currentId > 0) return $this->getSnapshot($currentId);
            $legacy = $this->rows('SELECT value,updated_at FROM site_options WHERE key_name="current_playlist" FOR UPDATE')[0] ?? null;
            if (!$legacy) return null;
            $payload = json_decode($legacy['value'], true);
            if (!is_array($payload)) throw new \RuntimeException('Legacy snapshot needs attention');
            unset($payload['_meta']);
            $catalog = EpisodeCatalog::normalize($this->getAllEpisodes());
            $stories = [];
            $usedIds = [];
            foreach ($payload as $oldStory) {
                if (!is_array($oldStory) || !isset($oldStory['ids'],$oldStory['titles']) || !is_array($oldStory['ids']) || !is_array($oldStory['titles'])) throw new \RuntimeException('Legacy snapshot needs attention');
                $ids = array_map('intval', $oldStory['ids']);
                if (count(array_unique($ids)) !== count($ids) || array_intersect($ids, array_keys($usedIds))) throw new \RuntimeException('Duplicate legacy story');
                foreach ($ids as $id) $usedIds[$id] = true;
                sort($ids);
                foreach ($catalog as $story) {
                    $canonical = $story['ids'];
                    sort($canonical);
                    if ($canonical === $ids) {
                        $story['ids'] = array_map('intval', $oldStory['ids']);
                        $story['titles'] = $oldStory['titles'];
                        $stories[] = $story;
                        break;
                    }
                }
            }
            if (count($stories) !== count($payload)) throw new \RuntimeException('Legacy snapshot needs attention');
            $this->sql('INSERT INTO playlist_snapshots(created_at,payload_json,origin) VALUES(?,?,"legacy")', [$legacy['updated_at'], json_encode($stories, JSON_THROW_ON_ERROR)]);
            $id = (int)$this->db->insert_id;
            $this->setOption('current_playlist_snapshot_id', (string)$id);
            return $this->getSnapshot($id);
        });
    }

    public function getSavedPlaylist() {
        $s = $this->getCurrentSnapshot();
        if ($s) {
            $p = $s['stories'];
            $p['_meta'] = ['snapshot_id' => $s['id'], 'updated_at' => $s['created_at'], 'is_old' => strtotime($s['created_at'].' UTC') < time()-604800, 'incomplete' => array_sum(array_column($p, 'length')) < 8];
            return $p;
        }
        $p = json_decode($this->option('current_playlist') ?? 'null', true);
        if (!is_array($p))return null;
        foreach ($p as $k => $v)if ($k !== '_meta'  &&  (!is_array($v) || !isset($v['ids'], $v['titles'], $v['length']) || !is_array($v['ids']) || !is_array($v['titles'])))return null;
        $p['_meta'] = ['is_old' => true, 'updated_at' => '', 'snapshot_id' => 0];
        return $p;
    }

    public function getEveningPlaylist($limit = 8) {
        return $this->getSavedPlaylist() ?? [];
    }

    public function regeneratePlaylist($limit = 8) {
        $this->tx(function () use ($limit) {
            $currentId = $this->publicationLock();
            if ($currentId === 0) $this->importLegacySnapshot();
            $stories = $this->generateWeightedPlaylist($limit);
            $this->sql('INSERT INTO playlist_snapshots(created_at,payload_json,origin) VALUES(UTC_TIMESTAMP(),?,"admin")', [json_encode($stories, JSON_THROW_ON_ERROR)]);
            $id = $this->db->insert_id;
            $this->setOption('current_playlist_snapshot_id', (string)$id);
            $this->setOption('current_playlist', json_encode($stories, JSON_THROW_ON_ERROR));
            $this->sql('UPDATE playlist_occurrences SET metadata_json=JSON_ARRAY_APPEND(JSON_SET(metadata_json,"$.snapshot_revisions",COALESCE(JSON_EXTRACT(metadata_json,"$.snapshot_revisions"),JSON_ARRAY())),"$.snapshot_revisions",JSON_OBJECT("previous_snapshot_id",snapshot_id,"new_snapshot_id",?,"revised_at",UTC_TIMESTAMP())),snapshot_id=? WHERE state="pending" AND start_at>UTC_TIMESTAMP()', [$id,$id]);
        });
        return $this->getSavedPlaylist();
    }

    private function replay(string $key): ?array {
        $r = $this->rows('SELECT outcome_json FROM episode_wish_events WHERE operation_key=?', [$key]);
        return isset($r[0])?json_decode($r[0]['outcome_json'], true):null;
    }

    private function event(int $userId, int $episodeId, string $key, string $kind, array $out, int $now): array {
        $this->sql('INSERT INTO episode_wish_events(operation_key,user_id,episode_id,kind,status,created_at,outcome_json) VALUES(?,?,?,?,?,?,?)', [$key, $userId, $episodeId, $kind, $out['status'], gmdate('Y-m-d H:i:s', $now), json_encode($out, JSON_THROW_ON_ERROR)]);
        return $out;
    }

    private function quotaLock(int $userId): void {
        $this->sql('INSERT INTO episode_wish_locks(user_id) VALUES(?) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id)', [$userId]);
        $this->rows('SELECT user_id FROM episode_wish_locks WHERE user_id=? FOR UPDATE', [$userId]);
    }

    public function wish(int $userId, int $episodeId, string $operationKey, ?int $now = null): array {
        $now??=time();
        return $this->tx(function () use ($userId, $episodeId, $operationKey, $now) {
            $this->quotaLock($userId);
            if ($r = $this->replay($operationKey))return $r;
            if ($userId <= 0  ||  !(new UserManager())->getUserById($userId)) throw new \InvalidArgumentException('Missing user');
            (new ChatManager())->assertCanSend($userId);
            $ep = $this->rows('SELECT ID,TITLE FROM episode_list WHERE ID=?', [$episodeId])[0] ?? null;
            $facts = ['episode_id' => $episodeId, 'title' => $ep['TITLE'] ?? ''];
            $out = ['status' => 'rejected', 'code' => 'missing', 'facts' => $facts];
            if (!$ep)return $this->event($userId, $episodeId, $operationKey, 'wish', $out, $now);
            $last = $this->rows('SELECT created_at FROM episode_wish_events WHERE user_id=? AND episode_id=? AND kind="wish" AND status="accepted" ORDER BY id DESC LIMIT 1', [$userId, $episodeId]);
            if ($last  &&  $now < strtotime($last[0]['created_at'].' UTC') + self::COOLDOWN_SECONDS) {
                $out['code'] = 'cooldown';
                $out['facts']['next_allowed_at'] = gmdate('Y-m-d H:i:s', strtotime($last[0]['created_at'].' UTC') + self::COOLDOWN_SECONDS);
                return $this->event($userId, $episodeId, $operationKey, 'wish', $out, $now);
            }
            $local = (new \DateTimeImmutable('@'.$now))->setTimezone(new \DateTimeZone('Europe/Kaliningrad'));
            $start = $local->setTime(0, 0);
            $end = $start->modify('+1 day');
            $n = (int)$this->rows('SELECT COUNT(DISTINCT episode_id) AS n FROM episode_wish_events WHERE user_id=? AND kind="wish" AND status="accepted" AND created_at>=? AND created_at<?', [$userId, $start->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $end->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')])[0]['n'];
            if ($n >= self::DAILY_LIMIT) {
                $out['code'] = 'daily_limit';
                $out['facts']['quota_remaining'] = 0;
                return $this->event($userId, $episodeId, $operationKey, 'wish', $out, $now);
            }
            $stamp = gmdate('Y-m-d H:i:s', $now);
            $this->sql('INSERT INTO episode_wishes(user_id,episode_id,status,accepted_at,updated_at) VALUES(?,?,"active",?,?) ON DUPLICATE KEY UPDATE status="active",accepted_at=VALUES(accepted_at),updated_at=VALUES(updated_at),fulfilled_snapshot_id=NULL,fulfilled_completion_key=NULL', [$userId, $episodeId, $stamp, $stamp]);
            return $this->event($userId, $episodeId, $operationKey, 'wish', ['status' => 'accepted', 'code' => 'accepted', 'facts' => $facts + ['quota_remaining' => self::DAILY_LIMIT-1-$n]], $now);
        });
    }

    public function cancelWish(int $userId, int $episodeId, string $operationKey, ?int $now = null): array {
        $now??=time();
        return $this->tx(function () use ($userId, $episodeId, $operationKey, $now) {
            $this->quotaLock($userId);
            if ($r = $this->replay($operationKey))return $r;
            if ($userId <= 0  ||  !(new UserManager())->getUserById($userId)) throw new \InvalidArgumentException('Missing user');
            (new ChatManager())->assertCanSend($userId);
            $updated = $this->sql('UPDATE episode_wishes SET status="cancelled",updated_at=? WHERE user_id=? AND episode_id=? AND status="active"', [gmdate('Y-m-d H:i:s', $now), $userId, $episodeId]);
            $ok = $updated->affected_rows > 0;
            return $this->event($userId, $episodeId, $operationKey, 'cancel', ['status' => $ok?'cancelled':'rejected', 'code' => $ok?'cancelled':'not_active', 'facts' => ['episode_id' => $episodeId]], $now);
        });
    }

    public function getUserWishes(int $userId): array {
        return $this->rows('SELECT w.episode_id,e.TITLE AS title,w.accepted_at,w.status FROM episode_wishes w JOIN episode_list e ON e.ID=w.episode_id WHERE w.user_id=? AND w.status="active" ORDER BY w.accepted_at DESC', [$userId]);
    }

    public function getWishTop(int $limit = 5): array {
        return $this->rows('SELECT e.ID AS episode_id,e.TITLE AS title,COUNT(*) AS votes FROM episode_wishes w JOIN episode_list e ON e.ID=w.episode_id WHERE w.status="active" GROUP BY e.ID,e.TITLE ORDER BY votes DESC,e.ID LIMIT '.max(1, min(5, $limit)));
    }

    public function bindOccurrence(array $occurrence, int $snapshotId): array {
        return $this->tx(function () use ($occurrence, $snapshotId) {
            $publishedId = $this->publicationLock();
            if (!empty($occurrence['bind_current'])) $snapshotId = $publishedId;
            $start = (int)$occurrence['real_start_time'];
            $end = $start + 60 * (int)$occurrence['duration_minutes'];
            $this->sql('INSERT IGNORE INTO playlist_occurrences(run_id,event_id,start_at,end_at,snapshot_id,state,metadata_json) VALUES(?,?,?,?,?,"pending",?)', [$occurrence['run_id'], $occurrence['id'], gmdate('Y-m-d H:i:s', $start), gmdate('Y-m-d H:i:s', $end), $snapshotId, json_encode($occurrence, JSON_THROW_ON_ERROR)]);
            if (!empty($occurrence['recover_chain']) && ($snapshotId > 0 || empty($occurrence['use_playlist']))) $this->sql('UPDATE playlist_occurrences SET snapshot_id=?,state="pending" WHERE run_id=? AND state="needs_attention" AND snapshot_id=0', [$snapshotId,$occurrence['run_id']]);
            return $this->rows('SELECT * FROM playlist_occurrences WHERE run_id=?', [$occurrence['run_id']])[0];
        });
    }

    public function reviseUpcomingOccurrence(array $occurrence, int $now): ?array {
        return $this->tx(function () use ($occurrence, $now) {
            $this->publicationLock();
            $row = $this->rows('SELECT * FROM playlist_occurrences WHERE run_id=? FOR UPDATE', [$occurrence['run_id']])[0] ?? null;
            if (!$row || $row['state'] !== 'pending' || strtotime($row['start_at'].' UTC') <= $now) return $row;
            $metadata = json_decode($row['metadata_json'], true);
            if (!empty($occurrence['use_playlist']) && (int)$row['snapshot_id'] === 0) {
                $snapshot = $this->getCurrentSnapshot() ?? $this->importLegacySnapshot();
                if (!$snapshot) {
                    $this->regeneratePlaylist();
                    $snapshot = $this->getCurrentSnapshot();
                }
                $metadata['snapshot_revisions'][] = [
                    'previous_snapshot_id' => 0,
                    'new_snapshot_id' => $snapshot['id'],
                    'revised_at' => gmdate('Y-m-d H:i:s', $now),
                ];
                $this->sql('UPDATE playlist_occurrences SET snapshot_id=?,metadata_json=? WHERE run_id=?', [
                    $snapshot['id'], json_encode($metadata, JSON_THROW_ON_ERROR), $occurrence['run_id'],
                ]);
            }
            $newEnd = gmdate('Y-m-d H:i:s', (int)$occurrence['real_start_time'] + 60*(int)$occurrence['duration_minutes']);
            if ($newEnd !== $row['end_at'] || ($metadata['use_playlist'] ?? 0) != ($occurrence['use_playlist'] ?? 0) || ($metadata['generate_new_playlist'] ?? 0) != ($occurrence['generate_new_playlist'] ?? 0)) {
                $revisions = $metadata['schedule_revisions'] ?? [];
                $revisions[] = ['previous_end_at'=>$row['end_at'],'revised_at'=>gmdate('Y-m-d H:i:s',$now)];
                $metadata = array_replace($metadata,$occurrence);
                $metadata['schedule_revisions'] = $revisions;
                $this->sql('UPDATE playlist_occurrences SET end_at=?,metadata_json=? WHERE run_id=?', [$newEnd,json_encode($metadata,JSON_THROW_ON_ERROR),$occurrence['run_id']]);
            }
            return $this->rows('SELECT * FROM playlist_occurrences WHERE run_id=?', [$occurrence['run_id']])[0];
        });
    }

    public function getLifecycleWatermark(): int {
        return strtotime(($this->option('playlist_rollout_watermark') ?? gmdate('Y-m-d H:i:s')) . ' UTC');
    }

    public function getOccurrences(): array {
        return $this->rows('SELECT * FROM playlist_occurrences ORDER BY start_at,run_id');
    }

    public function setOccurrenceState(string $runId, string $state): void {
        if (!in_array($state, ['started','cancelled','needs_attention'], true)) throw new \InvalidArgumentException('Invalid occurrence state');
        $this->sql('UPDATE playlist_occurrences SET state=? WHERE run_id=? AND state<>"completed"', [$state,$runId]);
    }

    public function getOccurrenceOutcome(string $runId): ?array {
        $row = $this->rows('SELECT state,outcome_json FROM playlist_occurrences WHERE run_id=?', [$runId])[0] ?? null;
        if (!$row || $row['state'] !== 'completed' || !$row['outcome_json']) return null;
        return json_decode($row['outcome_json'], true);
    }

    public function findSnapshotOccurrence(int $snapshotId): ?array {
        return $this->rows('SELECT * FROM playlist_occurrences WHERE snapshot_id=? AND (state IN ("pending","started") OR (state="completed" AND outcome_json IS NULL)) ORDER BY start_at LIMIT 1', [$snapshotId])[0] ?? null;
    }

    /** The run outcome is committed with accounting and its generated successor. */
    public function finishOccurrence(string $runId, ?int $now = null): array {
        $now ??= time();
        return $this->tx(function () use ($runId, $now) {
            $this->publicationLock();
            $occ = $this->rows('SELECT * FROM playlist_occurrences WHERE run_id=? FOR UPDATE', [$runId])[0] ?? null;
            if (!$occ || in_array($occ['state'], ['cancelled','needs_attention'], true)) throw new \InvalidArgumentException('Occurrence unavailable');
            if ($occ['outcome_json']) return json_decode($occ['outcome_json'], true);
            $metadata = json_decode($occ['metadata_json'], true);
            $viewAccounted = !empty($metadata['use_playlist']);
            if ($viewAccounted) $this->completeSnapshot((int)$occ['snapshot_id'], $runId, null, $now);
            $generatedId = null;
            if (!empty($metadata['generate_new_playlist'])) {
                $this->regeneratePlaylist();
                $generatedId = $this->getCurrentSnapshot()['id'];
            }
            $outcome = ['run_id'=>$runId,'state'=>'completed','snapshot_id'=>(int)$occ['snapshot_id'],'view_accounted'=>$viewAccounted,'generated_snapshot_id'=>$generatedId,'completed_at'=>gmdate('Y-m-d H:i:s',$now)];
            $this->sql('UPDATE playlist_occurrences SET state="completed",generated_snapshot_id=?,outcome_json=? WHERE run_id=?', [$generatedId,json_encode($outcome,JSON_THROW_ON_ERROR),$runId]);
            return $outcome;
        });
    }

    public function completeSnapshot(int $snapshotId, ?string $runId = null, ?array $storyIds = null, ?int $now = null): array {
        $now??=time();
        return $this->tx(function () use ($snapshotId, $runId, $storyIds, $now) {
            $this->publicationLock();
            $s = $this->rows('SELECT * FROM playlist_snapshots WHERE id=? FOR UPDATE', [$snapshotId])[0] ?? null;
            if (!$s)throw new \InvalidArgumentException('Missing snapshot');
            if (!$runId) {
                $bound = $this->rows('SELECT run_id FROM playlist_occurrences WHERE snapshot_id=? AND state IN ("started","pending") ORDER BY start_at LIMIT 1', [$snapshotId]);
                $runId = $bound[0]['run_id'] ?? null;
            }
            $completionKey = $runId ?? ('manual:'.$snapshotId);
            if ($this->rows('SELECT completion_key FROM playlist_completions WHERE completion_key=?', [$completionKey]))return ['status' => 'accepted', 'code' => 'already_completed', 'facts' => ['snapshot_id' => $snapshotId]];
            if (!$runId  &&  $s['completed_at'])return ['status' => 'accepted', 'code' => 'already_completed', 'facts' => ['snapshot_id' => $snapshotId]];
            $occ = $runId?$this->rows('SELECT * FROM playlist_occurrences WHERE run_id=? AND snapshot_id=? FOR UPDATE', [$runId, $snapshotId]):[];
            if ($runId && !$occ)throw new \InvalidArgumentException('Occurrence mismatch');
            $cutoff = $occ?$occ[0]['start_at']:gmdate('Y-m-d H:i:s', $now);
            $stories = json_decode($s['payload_json'], true);
            $seenIds = [];
            $seenStories = [];
            foreach ($stories as $story) {
                if (isset($seenStories[$story['story_id']]) || count(array_unique($story['ids'])) !== count($story['ids']) || array_intersect($story['ids'], array_keys($seenIds))) throw new \RuntimeException('Invalid snapshot uniqueness');
                $seenStories[$story['story_id']] = true;
                foreach ($story['ids'] as $id) $seenIds[$id] = true;
            }
            $selected = $storyIds === null?array_column($stories, 'story_id'):array_values(array_unique($storyIds));
            if (array_diff($selected, array_column($stories, 'story_id')))throw new \InvalidArgumentException('Invalid story');
            foreach ($stories as $story) {
                if (!in_array($story['story_id'], $selected, true))continue;
                $ids = $story['ids'];
                sort($ids);
                $parts = $this->rows('SELECT ID,TIMES_WATCHED FROM episode_list WHERE ID IN ('.implode(',', array_map('intval', $ids)).') ORDER BY ID FOR UPDATE');
                $views = max(array_column($parts, 'TIMES_WATCHED')) + 1;
                foreach ($ids as $id) {
                    $this->sql('UPDATE episode_list SET TIMES_WATCHED=? WHERE ID=?', [$views, $id]);
                    $this->sql('INSERT INTO watching_now(EPNUM,TITLE) SELECT ID,TITLE FROM episode_list WHERE ID=?', [$id]);
                    $this->sql('UPDATE episode_wishes SET status="fulfilled",fulfilled_snapshot_id=?,fulfilled_completion_key=?,updated_at=? WHERE episode_id=? AND status="active" AND accepted_at<=?', [$snapshotId, $completionKey, gmdate('Y-m-d H:i:s', $now), $id, $cutoff]);
                    $this->sql('UPDATE episode_wish_events SET status="fulfilled",snapshot_id=?,fulfilled_completion_key=? WHERE user_id IS NULL AND episode_id=? AND status="active" AND created_at<=?', [$snapshotId, $completionKey, $id, $cutoff]);
                    $active = $this->rows('SELECT COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(outcome_json,"$.count")) AS UNSIGNED)),0) AS n FROM episode_wish_events WHERE user_id IS NULL AND episode_id=? AND status="active"', [$id])[0]['n'];
                    $this->sql('UPDATE episode_list SET legacy_wanna_watch=?,WANNA_WATCH=? WHERE ID=?', [$active, $active, $id]);
                }
            }
            $this->sql('UPDATE playlist_snapshots SET completed_at=?,completion_json=? WHERE id=?', [gmdate('Y-m-d H:i:s', $now), json_encode($selected), $snapshotId]);
            $this->sql('INSERT INTO playlist_completions(completion_key,snapshot_id,run_id,start_at,completed_at,shown_json) VALUES(?,?,?,?,?,?)', [$completionKey, $snapshotId, $runId, $cutoff, gmdate('Y-m-d H:i:s', $now), json_encode($selected)]);
            if ($runId)$this->sql('UPDATE playlist_occurrences SET state="completed" WHERE run_id=?', [$runId]);
            return ['status' => 'accepted', 'code' => 'completed', 'facts' => ['snapshot_id' => $snapshotId]];
        });
    }

    public function correctCompletion(int $snapshotId, array $shownStoryIds, int $actorId, string $operationKey, ?string $completionKey = null): array {
        return $this->tx(function () use ($snapshotId, $shownStoryIds, $actorId, $operationKey, $completionKey) {
            $s = $this->rows('SELECT * FROM playlist_snapshots WHERE id=? FOR UPDATE', [$snapshotId])[0] ?? null;
            if (!$s || !$s['completed_at'])throw new \InvalidArgumentException('Not completed');
            if ($this->rows('SELECT id FROM playlist_corrections WHERE operation_key=?', [$operationKey]))return ['status' => 'accepted', 'code' => 'already_corrected', 'facts' => []];
            $stories = json_decode($s['payload_json'], true);
            $after = array_values(array_unique($shownStoryIds));
            if (array_diff($after, array_column($stories, 'story_id')))throw new \InvalidArgumentException('Invalid story');
            $completion = $completionKey?$this->rows('SELECT * FROM playlist_completions WHERE snapshot_id=? AND completion_key=?', [$snapshotId, $completionKey])[0] ?? null:$this->rows('SELECT * FROM playlist_completions WHERE snapshot_id=? ORDER BY completed_at DESC LIMIT 1', [$snapshotId])[0] ?? null;
            if (!$completion)throw new \InvalidArgumentException('Missing completion');
            $completionKey = $completion['completion_key'];
            $before = json_decode($completion['shown_json'], true);
            $cutoff = $completion['start_at'];
            foreach ($stories as $story) {
                $delta = (int)in_array($story['story_id'], $after, true)-(int)in_array($story['story_id'], $before, true);
                if (!$delta)continue;
                foreach ($story['ids'] as $id) {
                    $this->sql('UPDATE episode_list SET TIMES_WATCHED=GREATEST(0,TIMES_WATCHED+?) WHERE ID=?', [$delta, $id]);
                    if ($delta > 0) {
                        $this->sql('UPDATE episode_wishes SET status="fulfilled",fulfilled_snapshot_id=?,fulfilled_completion_key=? WHERE episode_id=? AND status="active" AND accepted_at<=?', [$snapshotId, $completionKey, $id, $cutoff]);
                        $this->sql('UPDATE episode_wish_events SET status="fulfilled",snapshot_id=?,fulfilled_completion_key=? WHERE user_id IS NULL AND episode_id=? AND status="active" AND created_at<=?', [$snapshotId, $completionKey, $id, $cutoff]);
                    }
                    else {
                        $this->sql('UPDATE episode_wish_events SET status="active",snapshot_id=NULL,fulfilled_completion_key=NULL WHERE user_id IS NULL AND episode_id=? AND status="fulfilled" AND fulfilled_completion_key=?', [$id, $completionKey]);
                    }
                    if ($delta < 0)$this->sql('UPDATE episode_wishes SET status="active",fulfilled_snapshot_id=NULL,fulfilled_completion_key=NULL WHERE episode_id=? AND status="fulfilled" AND fulfilled_completion_key=?', [$id, $completionKey]);
                    $legacy = $this->rows('SELECT COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(outcome_json,"$.count")) AS UNSIGNED)),0) n FROM episode_wish_events WHERE user_id IS NULL AND episode_id=? AND status="active"', [$id])[0]['n'];
                    $this->sql('UPDATE episode_list SET legacy_wanna_watch=?,WANNA_WATCH=? WHERE ID=?', [$legacy, $legacy, $id]);
                }
            }
            $this->sql('INSERT INTO playlist_corrections(snapshot_id,actor_id,operation_key,created_at,before_json,after_json,completion_key) VALUES(?,?,?,UTC_TIMESTAMP(),?,?,?)', [$snapshotId, $actorId, $operationKey, json_encode($before), json_encode($after), $completionKey]);
            $this->sql('UPDATE playlist_completions SET shown_json=? WHERE completion_key=?', [json_encode($after), $completionKey]);
            $this->sql('UPDATE playlist_snapshots SET completion_json=? WHERE id=?', [json_encode($after), $snapshotId]);
            return ['status' => 'accepted', 'code' => 'corrected', 'facts' => ['snapshot_id' => $snapshotId]];
        });
    }

    public function voteForEpisode($id) {
        return $this->tx(function () use ($id) {
            $updated = $this->sql('UPDATE episode_list SET legacy_wanna_watch=legacy_wanna_watch+1,WANNA_WATCH=WANNA_WATCH+1 WHERE ID=?', [(int)$id]);
            if ($updated->affected_rows !== 1)return false;
            $this->sql('INSERT INTO episode_wish_events(operation_key,user_id,episode_id,kind,status,created_at,outcome_json) VALUES(?,NULL,?,"legacy","active",UTC_TIMESTAMP(),?)', ['admin:'.bin2hex(random_bytes(16)), (int)$id, '{"count":1}']);
            return true;
        });
    }

    public function markAsWatched(array $ids) {
        $s = $this->getCurrentSnapshot();
        if (!$s)throw new \InvalidArgumentException('Missing snapshot');
        $ids = array_unique(array_map('intval', $ids));
        $selected = [];
        foreach ($s['stories'] as $st) {
            if (array_intersect($ids, $st['ids'])) {
                if (array_diff($st['ids'], $ids))throw new \InvalidArgumentException('Partial story');
                $selected[] = $st['story_id'];
            }
        }
        if (array_diff($ids, array_merge(...array_column($s['stories'], 'ids'))))throw new \InvalidArgumentException('Unknown episode');
        $this->completeSnapshot($s['id'], null, $selected);
        return true;
    }

    public function getCompletions(int $snapshotId): array {
        return $this->rows('SELECT * FROM playlist_completions WHERE snapshot_id=? ORDER BY completed_at DESC', [$snapshotId]);
    }

    public function getRecentSnapshots(int $limit = 20): array {
        return $this->rows('SELECT id,created_at,completed_at FROM playlist_snapshots ORDER BY id DESC LIMIT '.max(1, min(100, $limit)));
    }

    public function getWatchHistory($limit = 100) {
        return $this->rows('SELECT ID,EPNUM,TITLE FROM watching_now ORDER BY ID DESC LIMIT '.max(1, (int)$limit));
    }

    public function clearWannaWatch() {
        return $this->tx(function() {
            $this->sql('UPDATE episode_wishes SET status="cancelled" WHERE status="active"');
            $this->sql('UPDATE episode_wish_events SET status="cancelled" WHERE user_id IS NULL AND status="active"');
            return $this->db->query('UPDATE episode_list SET WANNA_WATCH=0,legacy_wanna_watch=0');
        });
    }

    public function resetTimesWatched() {
        return $this->db->query('UPDATE episode_list SET TIMES_WATCHED=0');
    }

    public function clearWatchingNowLog() {
        return $this->db->query('TRUNCATE TABLE watching_now');
    }
}
