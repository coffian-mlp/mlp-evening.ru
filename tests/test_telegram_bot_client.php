<?php
require_once __DIR__ . '/../autoload.php';

use Social\TelegramBotClient;
use Social\TelegramBotException;

$failed = 0;
function verifyTelegram(bool $condition, string $label): void {
    global $failed;
    echo ($condition ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$condition) $failed++;
}
$token = '123456789:' . str_repeat('a', 35);
$calls = [];
$reply = ['status' => 200, 'body' => '{"ok":true,"result":{"message_id":42}}'];
$client = new TelegramBotClient($token, static function ($method, $params) use (&$calls, &$reply) {
    $calls[] = [$method, $params];
    return $reply;
});
verifyTelegram($client->request('sendMessage', ['chat_id' => 1, 'text' => 'Hello'])['message_id'] === 42, 'result returned without API envelope');
$reply['body'] = '{"ok":true,"result":[]}';
$client->request('getUpdates', ['offset' => 12, 'timeout' => 50, 'limit' => 100, 'allowed_updates' => ['all']]);
verifyTelegram($calls[1][1] === ['offset' => 12, 'timeout' => 0, 'limit' => 20, 'allowed_updates' => ['message', 'callback_query']], 'polling bounded and restricted');
$expectError = static function (callable $action, bool $uncertain, string $label) use ($token): void {
    try {
        $action();
        verifyTelegram(false, $label);
    } catch (TelegramBotException $error) {
        verifyTelegram($error->uncertain === $uncertain && !str_contains((string)$error, $token) && $error->getPrevious() === null, $label);
    }
};
foreach ([
    [['status' => 200, 'body' => '', 'errno' => 28], true, 'timeout'],
    [['status' => 503, 'body' => 'unavailable'], true, 'server error'],
    [['status' => 200, 'body' => 'broken'], true, 'invalid JSON'],
    [['status' => 200, 'body' => '{"ok":true}'], true, 'missing result'],
    [['status' => 200, 'body' => '{"ok":true,"result":{}}'], true, 'missing message identifier'],
    [['status' => 400, 'body' => 'broken'], false, 'definitive HTTP rejection'],
    [['status' => 200, 'body' => '{"ok":false,"error_code":403}'], false, 'definitive API rejection'],
    [['status' => 429, 'body' => '{"ok":false,"error_code":429}'], false, 'rate limit'],
] as [$response, $uncertain, $label]) {
    $reply = $response;
    $before = count($calls);
    $expectError(fn() => $client->request('sendMessage', ['text' => 'x']), $uncertain, $label . ' classified for mutation');
    verifyTelegram(count($calls) === $before + 1, $label . ' never automatically retried');
    if ($label !== 'missing message identifier') {
        $expectError(fn() => $client->request('getMe'), false, $label . ' safe for read retry');
    }
}
$expectError(fn() => new TelegramBotClient('secret'), false, 'invalid credential rejected without exposure');
$expectError(fn() => $client->request('deleteMessage'), false, 'unsupported method rejected');
$throwing = new TelegramBotClient($token, static function () use ($token) { throw new RuntimeException('https://api.telegram.org/bot' . $token); });
$expectError(fn() => $throwing->request('sendMessage'), true, 'transport exception credentials and chain removed');
$reply = ['status' => 200, 'body' => '{"ok":true,"result":true}'];
verifyTelegram($client->request('answerCallbackQuery', ['callback_query_id' => 'x']) === ['ok' => true], 'boolean callback result normalized');

$directory = dirname(__DIR__) . '/upload/lyra';
$madeDirectory = !is_dir($directory);
if ($madeDirectory) mkdir($directory, 0755, true);
$basename = 'telegram_unit_' . bin2hex(random_bytes(8)) . '.jpg';
$path = $directory . '/' . $basename;
$webpath = '/upload/lyra/' . $basename;
file_put_contents($path, 'local fixture');
try {
    $reply = ['status' => 200, 'body' => '{"ok":true,"result":{"message_id":43}}'];
    $keyboard = ['inline_keyboard' => [[['text' => 'Approve', 'callback_data' => 'draft:1']]]];
    verifyTelegram($client->sendPhoto(1, str_repeat('🦄', 512), $webpath, $keyboard)['message_id'] === 43, '1024 UTF16-unit photo caption accepted');
    $last = end($calls);
    verifyTelegram($last[1]['photo'] === $webpath && $last[1]['reply_markup'] === $keyboard && !str_contains(json_encode($last), $token), 'fake receives ordinary params without credential or CURLFile');
    $expectError(fn() => $client->sendPhoto(1, str_repeat('🦄', 513), $webpath), false, 'caption counts surrogate pairs');
    $expectError(fn() => $client->request('sendPhoto', ['caption' => "\xff", 'photo' => $webpath]), false, 'invalid UTF8 caption rejected');
    foreach (['/etc/passwd', '/upload/lyra/../x.jpg', 'https://example.org/x.jpg', '/upload/lyra/x.png', '/upload/lyra/not_existing.jpg'] as $bad) {
        $expectError(fn() => $client->sendPhoto(1, 'caption', $bad), false, 'photo path cannot escape permitted local directory');
    }
    $link = $directory . '/telegram_link_' . bin2hex(random_bytes(8)) . '.jpg';
    symlink($path, $link);
    try {
        $expectError(fn() => $client->sendPhoto(1, 'caption', '/upload/lyra/' . basename($link)), false, 'symbolic link rejected');
    } finally { unlink($link); }
} finally {
    unlink($path);
    if ($madeDirectory) rmdir($directory);
}
echo $failed ? "FAILURES: $failed\n" : "ALL PASS\n";
exit($failed ? 1 : 0);
