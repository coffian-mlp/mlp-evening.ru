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
$token = (string)Env::get('TELEGRAM_ANNOUNCEMENTS_TOKEN','');
$owner = (int)Env::get('TELEGRAM_ANNOUNCEMENTS_OWNER_ID','0');
$channel = (string)Env::get('TELEGRAM_ANNOUNCEMENTS_CHANNEL','@mlp_evening');
$event = (int)$store->option('announcements_event_id','0');
$number = (int)$store->option('announcements_first_number','0');
if (!$token || $owner<=0 || $event<=0 || $number<1 || $number>3999 || !preg_match('/^(?:@[A-Za-z0-9_]{5,32}|-100\d+)$/D',$channel)) {
    error_log('Telegram announcements configuration incomplete');
    exit(1);
}
try {
    (new AnnouncementWorker($store,new TelegramBotClient($token),new AnnouncementContent(),$owner,$channel,$event,$number))->tick();
} catch (\Throwable $e) {
    error_log('Telegram announcements worker failed: '.get_class($e));
    exit(1);
}
