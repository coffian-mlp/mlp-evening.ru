<?php
namespace Domain;

use Infra\ConfigManager;
use Infra\Database;

/** Calendar coordinator; EpisodeManager owns all persistent playlist transitions. */
final class PlaylistLifecycle
{
    public function tick(?int $now = null): array
    {
        $now ??= time();
        $db = Database::getInstance()->getConnection();
        if (!(int)$db->query("SELECT GET_LOCK('playlist_lifecycle',0) AS n")->fetch_assoc()['n']) return [];
        try {
            $owner = new EpisodeManager();
            $events = (new EventManager())->getAllRaw();
            $eligible = array_values(array_filter($events, fn($event) => !empty($event['use_playlist']) || !empty($event['generate_new_playlist'])));
            $expanded = EventManager::expandOccurrences($eligible, 7, $now);
            $currentRuns = array_column($expanded, 'run_id');
            $known = [];
            $timeline = [];
            foreach ($expanded as $occurrence) {
                if ($occurrence['real_start_time'] >= $owner->getLifecycleWatermark()) $timeline[$occurrence['run_id']] = $occurrence;
            }
            foreach ($owner->getOccurrences() as $record) {
                $known[$record['run_id']] = $record;
                $metadata = json_decode($record['metadata_json'], true);
                $metadata['real_start_time'] = strtotime($record['start_at'].' UTC');
                $metadata['run_id'] = $record['run_id'];
                if (!isset($timeline[$record['run_id']]) || $metadata['real_start_time'] <= $now) $timeline[$record['run_id']] = $metadata;
            }
            uasort($timeline, fn($a,$b) => $a['real_start_time'] <=> $b['real_start_time']);
            $chainSnapshot = null;
            $chainEnd = null;
            $futureBound = false;
            $results = [];
            foreach ($timeline as $runId => $occurrence) {
                $record = $known[$runId] ?? null;
                $start = (int)$occurrence['real_start_time'];
                $end = $record ? strtotime($record['end_at'].' UTC') : $start + 60*(int)$occurrence['duration_minutes'];
                if ($record && $record['state'] === 'cancelled') continue;
                if ($start > $now) {
                    if ($record && !in_array($runId,$currentRuns,true)) {
                        $owner->setOccurrenceState($runId,'cancelled');
                        continue;
                    }
                    if ($record) $owner->reviseUpcomingOccurrence($occurrence,$now);
                    if (!$record && !$futureBound) {
                        if (empty($occurrence['use_playlist'])) {
                            $owner->bindOccurrence($occurrence,0);
                        } else {
                            $snapshot = $owner->getCurrentSnapshot() ?? $owner->importLegacySnapshot();
                            if (!$snapshot) { $owner->regeneratePlaylist(); $snapshot = $owner->getCurrentSnapshot(); }
                            $occurrence['bind_current'] = true;
                            $owner->bindOccurrence($occurrence,$snapshot['id']);
                        }
                    }
                    $futureBound = true;
                    continue;
                }
                if (!$record || $record['state'] === 'needs_attention') {
                    if (empty($occurrence['use_playlist'])) {
                        $occurrence['recover_chain'] = true;
                        $record = $owner->bindOccurrence($occurrence,0);
                    } elseif ($chainSnapshot !== null && $chainEnd <= $start) {
                        $occurrence['recover_chain'] = true;
                        $record = $owner->bindOccurrence($occurrence,$chainSnapshot);
                    } else {
                        $owner->bindOccurrence($occurrence,0);
                        $owner->setOccurrenceState($runId,'needs_attention');
                        $results[] = ['run_id'=>$runId,'status'=>'needs_attention'];
                        $chainSnapshot = null;
                        $chainEnd = null;
                        continue;
                    }
                }
                if ($now >= $end) {
                    $outcome = $owner->finishOccurrence($runId,$now);
                    $results[] = $outcome;
                    if ($outcome['generated_snapshot_id']) {
                        $chainSnapshot = $outcome['generated_snapshot_id'];
                        $chainEnd = $end;
                    } else {
                        $chainSnapshot = null;
                        $chainEnd = null;
                    }
                } else {
                    $owner->setOccurrenceState($runId,'started');
                }
            }
            return $results;
        } finally {
            ConfigManager::getInstance()->flushCache();
            $db->query("SELECT RELEASE_LOCK('playlist_lifecycle')");
        }
    }
}
