<?php
namespace LLM;

use Domain\EpisodeManager;

/** Durable schedule delivery; playlist facts are read from the completed occurrence. */
final class ScheduleAnnouncement {
    public static function retryDelay(int $attempts): int {
        return min(900, 60 * (2 ** min(4, max(0, $attempts))));
    }

    public static function processJob(LLMManager $llm, JobQueue $queue, array $job, bool $enabled): void {
        $id = (int)$job['id'];
        if (self::cancelIfExcluded($queue, $job)) return;
        try {
            $sent = $enabled && (isset($job['data']['prepared_reply'])
                ? CommandReplyDelivery::publish($llm, $job)
                : $llm->withReplyJob($job, fn() => $llm->processTrigger('dynamic_command', $job['data'])));
        } catch (\Throwable $e) {
            $sent = false;
            error_log('Schedule announcement deferred: ' . get_class($e));
        }
        if (self::cancelIfExcluded($queue, $job)) return;
        if ($sent) $queue->complete([$id]);
        else $queue->release([$id], self::retryDelay((int)($job['attempts'] ?? 0)));
    }

    public static function deliver(LLMManager $llm, array $data): bool {
        $episodes = new EpisodeManager();
        $outcome = $episodes->getOccurrenceOutcome((string)($data['event_run_id'] ?? ''));
        if (!$outcome || ($outcome['state'] ?? '') !== 'completed' || empty($outcome['view_accounted'])) return false;
        $snapshot = $episodes->getSnapshot((int)($outcome['generated_snapshot_id'] ?? 0));
        if (!$snapshot) return false;
        $expanded = \Domain\EventManager::expandWithNextOccurrences((new \Domain\EventManager())->getAllRaw(), 7);
        $context = ScheduleData::dataBlock(ScheduleData::snapshot($expanded, time(), $data['event_run_id'] ?? null));
        return self::publish($llm, $snapshot['stories'], $outcome['completed_at'], $context);
    }

    public static function publish(LLMManager $llm, array $stories, string $completedAt, string $scheduleContext): bool {
        $titles = [];
        foreach ($stories as $story) {
            if (!is_array($story) || empty($story['titles'])) return false;
            foreach ($story['titles'] as $title) {
                if (!is_string($title) || trim($title) === '') return false;
                $metadata = \Domain\EpisodeCatalog::metadata($title);
                $titles[] = $metadata ? sprintf('S%02dE%02d — %s', $metadata['season'], $metadata['episode'], $metadata['name']) : trim($title);
            }
        }
        if (!$titles) return false;
        $instruction = 'Сообщи участникам, что просмотр завершённого поняшного вечерка засчитан, а новая подборка готова. '
            . 'Обязательно включи точные слова «просмотр засчитан». Поблагодари участников в своём характере. '
            . 'Напиши только вступление в 1–3 предложениях; полный список новых серий добавит код, не перечисляй серии сама. '
            . 'Если доставка задержалась, не выдавай завершение за только что произошедшее и не зови на уже закончившееся событие. '
            . 'Момент учёта просмотра (UTC): ' . $completedAt . ".\n" . $scheduleContext;
        $intro = $llm->withAddressedMessages([], fn() => $llm->liveText($instruction, 'просмотр засчитан'));
        if (!$intro || mb_stripos($intro, 'просмотр засчитан') === false || preg_match('/^[\s\W]*SILENCE[\s\W]*$/iu', $intro)) return false;
        $list = [];
        foreach ($titles as $index => $title) $list[] = ($index + 1) . '. ' . $title;
        return (bool)$llm->botSay($intro . "\n\n" . implode("\n", $list));
    }
    /** Recheck queued announcements against live restrictions before generation and delivery. */
    public static function allowed(array $data, bool $lock = false): bool {
        if (empty($data['schedule_announcement']) || !preg_match('/^(\d+)_(\d+)$/D', (string)($data['event_run_id'] ?? ''), $match)) return true;
        foreach ((new \Domain\EventManager())->getAllRaw($lock) as $event) {
            if ((int)$event['id'] === (int)$match[1]) return \Domain\EventManager::occurrenceAllowed($event, (int)$match[2]);
        }
        return true; // Preserve existing deletion semantics for legacy jobs.
    }

    public static function cancelIfExcluded(JobQueue $queue, array $job): bool {
        if (self::allowed($job['data'] ?? [])) return false;
        $queue->complete([(int)$job['id']]);
        return true;
    }
}
