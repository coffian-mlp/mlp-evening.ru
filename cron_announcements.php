<?php
/** Dedicated minute worker for Telegram announcement editing and publication. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/autoload.php';

use Domain\AnnouncementStore;
use Infra\Env;
use LLM\AnnouncementContent;
use LLM\AnnouncementWorker;
use Social\TelegramBotClient;

$store = new AnnouncementStore();
if ($store->option('announcements_enabled','0') !== '1') exit;
$settings = \Domain\AnnouncementSettings::values();
$token = $settings['announcements_token'];
$owner = (int)$settings['announcements_owner_id'];
$channel = $settings['announcements_channel'];
$event = (int)$store->option('announcements_event_id','0');
$number = (int)$store->option('announcements_first_number','0');
if (!$token || $owner<=0 || $event<=0 || $number<1 || $number>3999 || !preg_match('/^(?:@[A-Za-z0-9_]{5,32}|-100\d+)$/D',$channel)) {
    error_log('Telegram announcements configuration incomplete');
    exit(1);
}
try {
    (new AnnouncementWorker($store,\Domain\AnnouncementSettings::client($settings),new AnnouncementContent(),$owner,$channel,$event,$number,\Domain\AnnouncementSettings::fingerprint($settings)))->tick();
} catch (\Throwable $e) {
    error_log('Telegram announcements worker failed: '.get_class($e));
    exit(1);
}
