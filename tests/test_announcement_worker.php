<?php
require_once __DIR__ . '/../autoload.php';
use LLM\AnnouncementWorker;
$failed = 0;
function verifyAnnouncementOwner(bool $condition, string $label): void {
    global $failed;
    echo ($condition ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$condition) $failed++;
}
$owner = 123456789;
$message = ['message' => ['message_id' => 1, 'from' => ['id' => $owner], 'chat' => ['id' => $owner, 'type' => 'private'], 'text' => '/status']];
verifyAnnouncementOwner(AnnouncementWorker::ownerUpdate($message, $owner), 'owner private message authorized');
$callback = ['callback_query' => ['id' => 'callback', 'from' => ['id' => $owner], 'message' => $message['message'], 'data' => 'ann:1:0123456789abcdef:approve']];
verifyAnnouncementOwner(AnnouncementWorker::ownerUpdate($callback, $owner), 'owner private callback authorized');
foreach (['stranger','wrongchat','group','channel','missingfrom','ownerzero'] as $kind) {
    $bad = $message; $expectedOwner = $owner;
    if ($kind === 'stranger') $bad['message']['from']['id']++;
    if ($kind === 'wrongchat') $bad['message']['chat']['id']++;
    if ($kind === 'group') $bad['message']['chat']['type'] = 'group';
    if ($kind === 'channel') $bad['message']['chat']['type'] = 'channel';
    if ($kind === 'missingfrom') unset($bad['message']['from']);
    if ($kind === 'ownerzero') $expectedOwner = 0;
    verifyAnnouncementOwner(!AnnouncementWorker::ownerUpdate($bad, $expectedOwner), $kind . ' cannot access editor');
}
$bad = $callback; $bad['callback_query']['from']['id']++;
verifyAnnouncementOwner(!AnnouncementWorker::ownerUpdate($bad, $owner), 'callback sender checked separately from bot message author');
$callback['callback_query']['message']['from']['id'] = 777;
verifyAnnouncementOwner(AnnouncementWorker::ownerUpdate($callback, $owner), 'bot-authored preview can be used by authenticated owner');
verifyAnnouncementOwner(!AnnouncementWorker::ownerUpdate(['channel_post' => ['chat' => ['id' => $owner]]], $owner), 'channel post cannot substitute private owner update');
$keyboard = AnnouncementWorker::keyboard(['id' => 1844674407, 'nonce' => '0123456789abcdef']);
$actions = [];
foreach ($keyboard['inline_keyboard'] as $row) foreach ($row as $button) {
    $data = $button['callback_data'];
    verifyAnnouncementOwner(strlen($data) <= 64 && str_starts_with($data, 'ann:1844674407:0123456789abcdef:') && !isset($button['url']), 'button binds revision nonce within Telegram limit');
    $actions[] = substr($data, strrpos($data, ':') + 1);
}
verifyAnnouncementOwner($actions === ['approve','ask_text','ask_image','ask_both','cancel'], 'independent approval, text/image feedback and cancellation available');
echo $failed ? "FAILURES: $failed\n" : "ALL PASS\n";
exit($failed ? 1 : 0);
