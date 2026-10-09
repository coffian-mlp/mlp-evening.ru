<?php

namespace Social;

/** Dedicated announcement transport. Credentials are supplied by the worker. */
class TelegramBotClient
{
    private const METHODS = ['getMe', 'getChat', 'getChatMember', 'getWebhookInfo', 'getUpdates', 'sendPhoto', 'sendMessage', 'answerCallbackQuery'];
    private const MUTATING = ['sendPhoto', 'sendMessage', 'answerCallbackQuery'];
    private $fakeTransport;
    private AnnouncementProxy $proxy;

    public function __construct(private string $token, ?callable $fakeTransport = null, string $proxyUrl = '')
    {
        if (!preg_match('/\A[0-9]{5,20}:[A-Za-z0-9_-]{20,100}\z/D', $token)) {
            throw new TelegramBotException('Telegram bot credential is invalid.');
        }
        $this->fakeTransport = $fakeTransport;
        $this->proxy = new AnnouncementProxy($proxyUrl);
    }

    /** Fake transport receives (method, params) and returns status/body/errno. */
    public function request(string $method, array $params = []): array
    {
        if (!in_array($method, self::METHODS, true)) {
            throw new TelegramBotException('Telegram method is not supported.');
        }
        $mutating = in_array($method, self::MUTATING, true);
        $photoPath = null;
        if ($method === 'getUpdates') {
            $params['offset'] = max(0, (int)($params['offset'] ?? 0));
            $params['timeout'] = 0;
            $params['limit'] = 20;
            $params['allowed_updates'] = ['message', 'callback_query'];
        }
        if ($method === 'sendPhoto') {
            $photoPath = $this->resolvePhoto($params['photo'] ?? null);
            $caption = $params['caption'] ?? '';
            if (!is_string($caption) || !mb_check_encoding($caption, 'UTF-8') || strlen(mb_convert_encoding($caption, 'UTF-16LE', 'UTF-8')) / 2 > 1024) {
                throw new TelegramBotException('Telegram photo caption is invalid or too long.');
            }
        }
        try {
            $response = $this->fakeTransport !== null
                ? ($this->fakeTransport)($method, $params)
                : $this->transport($method, $params, $photoPath);
        } catch (\Throwable $error) {
            // Transport messages and previous exceptions may contain credentials.
            throw new TelegramBotException('Telegram transport failed.', $mutating);
        }
        if (!is_array($response) || (int)($response['errno'] ?? 0) !== 0) {
            throw new TelegramBotException('Telegram network request failed.', $mutating);
        }
        $status = (int)($response['status'] ?? 0);
        $body = json_decode(is_string($response['body'] ?? null) ? $response['body'] : '', true);
        if ($status === 429 || (is_array($body) && ($body['error_code'] ?? null) === 429)) {
            throw new TelegramBotException('Telegram rate limit reached.', false);
        }
        if ($status >= 400 && $status < 500) {
            throw new TelegramBotException('Telegram request rejected (HTTP ' . $status . ').', false);
        }
        if ($status < 200 || $status >= 300) {
            throw new TelegramBotException('Telegram service response failed.', $mutating);
        }
        if (!is_array($body) || !array_key_exists('ok', $body) || !is_bool($body['ok'])) {
            throw new TelegramBotException('Telegram response is invalid.', $mutating);
        }
        if ($body['ok'] !== true) {
            $code = (int)($body['error_code'] ?? 0);
            throw new TelegramBotException('Telegram API request rejected.', $mutating && ($code < 400 || $code >= 500));
        }
        if (!array_key_exists('result', $body) || (!is_array($body['result']) && !($method === 'answerCallbackQuery' && $body['result'] === true))) {
            throw new TelegramBotException('Telegram result is invalid.', $mutating);
        }
        if (in_array($method, ['sendPhoto', 'sendMessage'], true)
            && (!is_int($body['result']['message_id'] ?? null) || $body['result']['message_id'] <= 0)) {
            throw new TelegramBotException('Telegram sent-message identifier is missing.', true);
        }
        return is_array($body['result']) ? $body['result'] : ['ok' => true];
    }

    public function sendPhoto(int|string $chatId, string $caption, string $webpath, ?array $keyboard = null): array
    {
        $params = ['chat_id' => $chatId, 'caption' => $caption, 'photo' => $webpath];
        if ($keyboard !== null) {
            $params['reply_markup'] = $keyboard;
        }
        return $this->request('sendPhoto', $params);
    }

    private function resolvePhoto(mixed $webpath): string
    {
        if (!is_string($webpath) || !preg_match('~\A/upload/lyra/[A-Za-z0-9_-]+\.jpg\z~D', $webpath)) {
            throw new TelegramBotException('Telegram photo path is invalid.');
        }
        $root = dirname(__DIR__, 2);
        $directory = realpath($root . '/upload/lyra');
        $path = $root . $webpath;
        $resolved = realpath($path);
        if ($directory === false || $resolved === false || !is_file($resolved) || !is_readable($resolved)
            || is_link($path) || is_link($root . '/upload/lyra') || dirname($resolved) !== $directory
            || $directory !== $root . '/upload/lyra') {
            throw new TelegramBotException('Telegram photo is not an allowed local file.');
        }
        return $resolved;
    }

    private function transport(string $method, array $params, ?string $photoPath): array
    {
        $proxy = $this->proxy->resolve();
        $curl = curl_init('https://api.telegram.org/bot' . $this->token . '/' . $method);
        $options = [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS];
        // Empty string disables inherited process proxies; this transport has its own configuration.
        $options[CURLOPT_PROXY] = $proxy;
        $options[CURLOPT_NOPROXY] = '';
        if ($photoPath !== null) {
            $params['photo'] = new \CURLFile($photoPath, 'image/jpeg', basename($photoPath));
            foreach ($params as $key => $value) {
                if (is_array($value)) {
                    $params[$key] = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                }
            }
            $options[CURLOPT_POSTFIELDS] = $params;
        } else {
            $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
            $options[CURLOPT_POSTFIELDS] = json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        try {
            curl_setopt_array($curl, $options);
            $body = curl_exec($curl);
            return ['status' => curl_getinfo($curl, CURLINFO_HTTP_CODE), 'body' => $body, 'errno' => curl_errno($curl)];
        } finally {
            curl_close($curl);
        }
    }
}
