<?php
require_once __DIR__ . '/integration_helpers.php';
if ((it_config()['db']['host'] ?? '') !== 'db') it_skip('requires isolated Docker database');
$db = it_require_db();
$missing = false;
foreach (['campaigns','posts','revisions','updates'] as $suffix) {
    if (!$db->query("SHOW TABLES LIKE 'telegram_announcement_" . $suffix . "'")->num_rows) $missing = true;
}
if ($missing) {
    $db->multi_query(file_get_contents(__DIR__ . '/../migrations/2026_10_08_telegram_announcements.sql'));
    do { if ($result = $db->store_result()) $result->free(); } while ($db->more_results() && $db->next_result());
}
if ($db->query('SELECT id FROM telegram_announcement_revisions WHERE state IN ("sending","preview_sending","controls_sending","text_generating","image_generating") LIMIT 1')->num_rows) {
    it_skip('another announcement test or worker has transitional revisions');
}
$store = new Domain\AnnouncementStore($db);
$offset = $db->query('SELECT value FROM site_options WHERE key_name="announcements_update_offset"')->fetch_assoc();
$counter = $db->query('SELECT value FROM site_options WHERE key_name="announcements_counter"')->fetch_assoc();
$campaigns = [];
$updates = [];
$now = time();
$marker = 'it375_' . bin2hex(random_bytes(10));
$facts = ['occurrence' => ['run_id' => $marker . ':1', 'real_start_time' => $now + 8 * 86400], 'episodes' => [['id' => 1, 'title' => 'Fixture']]];
$schedule = ['prepare_at' => $now, 'main_at' => $now + 86400, 'reminder_at' => $now + 7 * 86400];
$posts = static function (int $campaign) use ($db): array {
    return $db->query('SELECT * FROM telegram_announcement_posts WHERE campaign_id=' . $campaign . ' ORDER BY kind')->fetch_all(MYSQLI_ASSOC);
};
$preview = static function (int $id, string $caption = 'Fixture caption', string $image = '/upload/lyra/fixture.jpg') use ($store): array {
    $r = $store->revision($id);
    if ($r['state'] === 'text_pending') {
        $store->change($id, 'text_pending', 'text_generating');
        $store->change($id, 'text_generating', $r['image_path'] ? 'preview_pending' : 'image_pending', ['caption' => $caption]);
    }
    $r = $store->revision($id);
    if ($r['state'] === 'image_pending') {
        $store->change($id, 'image_pending', 'image_generating');
        $store->change($id, 'image_generating', 'preview_pending', ['image_path' => $image]);
    }
    $store->change($id, 'preview_pending', 'preview_sending');
    check($store->change($id, 'preview_sending', 'pending', ['preview_photo_id' => $id + 1000000]), 'fixture preview becomes pending');
    return $store->revision($id);
};
try {
    $id = $store->ensure($facts, hash('sha256', 'first'), $schedule, 100);
    $campaigns[] = $id;
    $rows = $posts($id);
    check(array_column($rows, 'kind') === ['main','reminder'], 'one campaign creates main and reminder');
    check($store->ensure($facts, hash('sha256', 'first'), $schedule, 100) === $id && count($posts($id)) === 2, 'ensure idempotent for same occurrence');
    $facts2 = $facts; $facts2['occurrence']['run_id'] = $marker . ':2';
    $id2 = $store->ensure($facts2, hash('sha256', 'second'), $schedule, 1);
    $campaigns[] = $id2;
    $number1 = (int)$store->revision((int)$rows[0]['current_revision'])['stream_number'];
    $number2 = (int)$store->revision((int)$posts($id2)[0]['current_revision'])['stream_number'];
    check($number2 === $number1 + 1, 'stream numbers monotonically advance even with lower initial setting');

    $update = random_int(1000000000, 2000000000); $updates[] = $update;
    $count = 0;
    check($store->consume($update, static function () use (&$count) { $count++; }), 'first Telegram update consumed');
    check(!$store->consume($update, static function () use (&$count) { $count++; }) && $count === 1, 'duplicate update cannot repeat action');
    check($store->option('announcements_update_offset') === (string)($update + 1), 'offset committed with action');
    $update2 = $update + 1; $updates[] = $update2;
    try { $store->consume($update2, static function () { throw new RuntimeException('fixture rollback'); }); check(false, 'failed update transaction rolls back'); }
    catch (RuntimeException $error) { check($error->getMessage() === 'fixture rollback', 'failed update transaction rolls back'); }
    check($store->consume($update2, static function () {}), 'rolled-back update can be processed again');

    $main = (int)$rows[0]['current_revision'];
    $r = $store->revision($main);
    check($store->action($main, $r['nonce'], 'approve', '', $now) === 'unavailable', 'approval before generated preview rejected');
    $r = $preview($main);
    check($store->action($main, str_repeat('f',16), 'approve', '', $now) === 'stale', 'forged nonce rejected');
    check($store->action($main, $r['nonce'], 'approve', '', $now) === 'approved', 'delivered preview can be approved');
    $approved = $store->revision($main);
    check($approved['approved_at'] !== null && $approved['caption'] === $r['caption'] && $approved['image_path'] === $r['image_path'], 'approval fixes exact preview content');
    try { $store->change($main, 'approved', 'approved', ['caption' => 'illegal mutation']); } catch (LogicException|RuntimeException $error) {}
    check($store->revision($main)['caption'] === $approved['caption'], 'approved revision content cannot be overwritten');
    foreach ([['caption'=>null],['image_path'=>null],['image_path'=>'/upload/lyra/replacement.jpg']] as $badValues) {
        try { $store->change($main, 'approved', 'approved', $badValues); } catch (LogicException|RuntimeException $error) {}
        $unchanged = $store->revision($main);
        check($unchanged['caption'] === $approved['caption'] && $unchanged['image_path'] === $approved['image_path'], 'approved content cannot be erased or image replaced');
    }
    check($store->action($main, $r['nonce'], 'ask_text', '', $now) === 'awaiting_feedback', 'ask text accepts approved preview');
    check($store->revision($main)['approved_at'] === null && $store->revision($main)['state'] === 'feedback_text', 'request for feedback invalidates approval immediately');
    check($store->action($main, $r['nonce'], 'text', 'Shorter please', $now) === 'regenerating', 'text feedback creates new revision');
    $new = $store->revision((int)$posts($id)[0]['current_revision']);
    check((int)$new['version'] === 2 && $new['state'] === 'text_pending' && $new['caption'] === null && $new['previous_caption'] === $r['caption'] && $new['image_path'] === $r['image_path'] && $new['feedback'] === 'Shorter please', 'text regeneration preserves image, previous caption and exact feedback');
    check($store->action($main, $r['nonce'], 'approve', '', $now) === 'stale' && $store->revision($main)['state'] === 'superseded', 'old revision buttons cannot approve new content');
    $new = $preview((int)$new['id'], 'New caption', $new['image_path']);
    check($store->action((int)$new['id'], $new['nonce'], 'image', 'Different colours', $now) === 'regenerating', 'image feedback creates separate revision');
    $image = $store->revision((int)$posts($id)[0]['current_revision']);
    check((int)$image['version'] === 3 && $image['state'] === 'image_pending' && $image['caption'] === 'New caption' && $image['image_path'] === null, 'image regeneration preserves caption');
    $image = $preview((int)$image['id']);
    check($store->findPreview((int)$image['preview_photo_id'])['id'] === $image['id'], 'reply locates exact preview revision');
    check($store->action((int)$image['id'], $image['nonce'], 'cancel', '', $now) === 'cancelled', 'owner cancellation accepted');
    check($store->action((int)$image['id'], $image['nonce'], 'approve', '', $now) === 'unavailable', 'cancelled post cannot be approved');

    $reminder = $preview((int)$rows[1]['current_revision']);
    check($store->action((int)$reminder['id'], $reminder['nonce'], 'approve', '', (int)$facts['occurrence']['real_start_time']) === 'expired', 'approval at expiry boundary rejected');
    check($store->action((int)$reminder['id'], $reminder['nonce'], 'approve', '', $now) === 'approved', 'reminder independently approved');
    $changed = $facts; $changed['episodes'][0]['title'] = 'Changed playlist';
    $store->ensure($changed, hash('sha256', 'changed'), $schedule, 100);
    $changedReminder = $store->revision((int)$posts($id)[1]['current_revision']);
    check($changedReminder['id'] !== $reminder['id'] && $changedReminder['state'] === 'text_pending' && $changedReminder['approved_at'] === null, 'changed programme invalidates approval and requires new preview');

    $secondRows = $posts($id2);
    $sending = $preview((int)$secondRows[0]['current_revision']);
    $store->action((int)$sending['id'], $sending['nonce'], 'approve', '', $now);
    $store->change((int)$sending['id'], 'approved', 'sending');
    $generating = (int)$secondRows[1]['current_revision'];
    $store->change($generating, 'text_pending', 'text_generating');
    $store->recover();
    check($store->revision((int)$sending['id'])['state'] === 'uncertain' && $store->revision((int)$sending['id'])['approved_at'] !== null, 'interrupted channel delivery retains approval but becomes uncertain');
    check($store->action((int)$sending['id'], $sending['nonce'], 'approve', '', $now) === 'unavailable', 'uncertain delivery cannot be blindly approved for resend');
    check($store->revision($generating)['state'] === 'text_pending', 'interrupted text generation safely resumes');
    $store->change((int)$sending['id'], 'uncertain', 'published', ['channel_message_id' => 777]);
    check((int)$store->revision((int)$sending['id'])['channel_message_id'] === 777, 'manual reconciliation records published message');
    check($store->action((int)$sending['id'], $sending['nonce'], 'both', '', $now) === 'unavailable', 'published revision cannot be regenerated');
    $freshRows = $posts($id);
    $previewInterrupted = (int)$freshRows[0]['current_revision'];
    $imageInterrupted = (int)$freshRows[1]['current_revision'];
    $store->change($previewInterrupted, 'text_pending', 'preview_sending');
    $store->change($imageInterrupted, 'text_pending', 'image_generating');
    $store->recover();
    check($store->revision($previewInterrupted)['state'] === 'uncertain' && $store->revision($previewInterrupted)['approved_at'] === null, 'interrupted preview is uncertain independently of channel delivery');
    check($store->revision($imageInterrupted)['state'] === 'image_pending', 'interrupted image generation safely resumes');
} finally {
    foreach ($campaigns as $campaign) {
        $ownPosts = array_column($posts($campaign), 'id');
        if ($ownPosts) $db->query('DELETE FROM telegram_announcement_revisions WHERE post_id IN (' . implode(',',array_map('intval',$ownPosts)) . ')');
        $db->query('DELETE FROM telegram_announcement_posts WHERE campaign_id=' . $campaign);
        $db->query('DELETE FROM telegram_announcement_campaigns WHERE id=' . $campaign);
    }
    foreach ($updates as $update) $db->query('DELETE FROM telegram_announcement_updates WHERE update_id=' . $update);
    if ($offset) $store->setOption('announcements_update_offset', $offset['value']);
    else $db->query('DELETE FROM site_options WHERE key_name="announcements_update_offset"');
    if ($counter) $store->setOption('announcements_counter', $counter['value']);
    else $db->query('DELETE FROM site_options WHERE key_name="announcements_counter"');
    $db->close();
}
it_done();
