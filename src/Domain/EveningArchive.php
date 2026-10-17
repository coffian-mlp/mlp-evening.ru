<?php
namespace Domain;

/** Public read projection: no calendar expansion or lifecycle writes. */
final class EveningArchive
{
    public static function present(array $row): array
    {
        $metadata = json_decode($row['metadata_json'] ?? '', true);
        $stories = json_decode($row['payload_json'] ?? '', true);
        $shown = $row['shown_json'] === null ? null : json_decode($row['shown_json'], true);
        $known = is_array($shown) && array_is_list($shown);
        $episodes = [];
        foreach (is_array($stories) ? $stories : [] as $story) {
            if (!is_array($story) || !is_array($story['titles'] ?? null)) continue;
            $watched = $known ? in_array($story['story_id'] ?? null, $shown, true) : null;
            foreach ($story['titles'] as $title) {
                if (is_string($title)) $episodes[] = ['title'=>$title, 'watched'=>$watched];
            }
        }
        $start = new \DateTimeImmutable($row['start_at'], new \DateTimeZone('UTC'));
        return [
            'title'=>is_string($metadata['title'] ?? null) ? $metadata['title'] : 'Вечерок',
            'date'=>$start->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('d.m.Y · H:i'),
            'datetime'=>$start->format('Y-m-d\TH:i:s\Z'),
            'episodes'=>$episodes, 'known'=>$known, 'corrected'=>(bool)$row['corrected'],
            'watched_count'=>$known ? count(array_filter($episodes, fn($e) => $e['watched'])) : null,
        ];
    }
}
