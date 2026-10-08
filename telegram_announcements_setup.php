<?php
/** CLI readiness checks and explicit activation; never prints credentials. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/autoload.php';
use Domain\AnnouncementStore;
use Domain\EventManager;
use Infra\Env;
use Social\TelegramBotClient;

$store = new AnnouncementStore();
$mode = $argv[1] ?? '--check';
if ($mode === '--disable') { $store->setOption('announcements_enabled','0'); echo "Announcements disabled.\n"; exit; }
if (!in_array($mode,['--check','--enable'],true)) { fwrite(STDERR,"Usage: php telegram_announcements_setup.php --check|--enable EVENT_ID FIRST_NUMBER [FIRST_DATE]|--disable\n"); exit(1); }
try {
    $token = (string)Env::get('TELEGRAM_ANNOUNCEMENTS_TOKEN','');
    $owner = (int)Env::get('TELEGRAM_ANNOUNCEMENTS_OWNER_ID','0');
    $channel = (string)Env::get('TELEGRAM_ANNOUNCEMENTS_CHANNEL','@mlp_evening');
    if ($owner<=0 || !preg_match('/^(?:@[A-Za-z0-9_]{5,32}|-100\d+)$/D',$channel)) throw new RuntimeException('Owner/channel configuration incomplete');
    $client = new TelegramBotClient($token);
    $me = $client->request('getMe');
    if (empty($me['is_bot']) || empty($me['id'])) throw new RuntimeException('Expected a Telegram bot');
    $webhook = $client->request('getWebhookInfo');
    if (!empty($webhook['url'])) throw new RuntimeException('Bot has a webhook; use a dedicated polling bot');
    $member = $client->request('getChatMember',['chat_id'=>$channel,'user_id'=>$me['id']]);
    if (($member['status'] ?? '')!=='administrator' || empty($member['can_post_messages'])) throw new RuntimeException('Bot cannot publish in channel');
    $private = $client->request('getChat',['chat_id'=>$owner]);
    if (($private['type'] ?? '')!=='private' || (int)($private['id'] ?? 0)!==$owner) throw new RuntimeException('Owner must start the bot privately');
    if ($mode==='--enable') {
        $eventId = (int)($argv[2] ?? 0); $number = (int)($argv[3] ?? 0);
        $event = null;
        foreach ((new EventManager())->getAllRaw() as $candidate) if ((int)$candidate['id']===$eventId) $event=$candidate;
        if (!$event || empty($event['use_playlist']) || empty($event['is_recurring']) || $event['recurrence_rule']!=='weekly' || empty($event['start_time']) || $number<1 || $number>3999) throw new RuntimeException('Event or initial stream number invalid');
        if (isset($argv[4])) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d',$argv[4],new \DateTimeZone('Europe/Moscow'));
            if (!$date || $date->format('Y-m-d')!==$argv[4]) throw new RuntimeException('First date must be YYYY-MM-DD');
            $store->setOption('announcements_not_before',(string)$date->getTimestamp());
        }
        $store->setOption('announcements_event_id',(string)$eventId);
        $store->setOption('announcements_first_number',(string)$number);
        $store->setOption('announcements_enabled','1');
    }
    echo 'Bot @'.($me['username'] ?? '').': ready; channel publishing and private owner chat verified; enabled='.$store->option('announcements_enabled','0').".\n";
} catch (Throwable $error) {
    // Only controlled messages and exception class; provider payloads and token never printed.
    fwrite(STDERR,'Readiness check failed: '.($error instanceof \Social\TelegramBotException ? get_class($error) : $error->getMessage())."\n");
    exit(1);
}
