<?php

require_once __DIR__ . '/ConfigManager.php';

class CentrifugoService {
    private $apiUrl;
    private $apiKey;
    private $secret;

    public function __construct() {
        // Мы берем конфиг напрямую, т.к. ConfigManager работает с БД (site_options), 
        // а это системные настройки.
        // Но подожди, ConfigManager у нас синглтон для site_options.
        // А файловый конфиг мы читаем где? Обычно require config.php.
        // Давайте сделаем метод загрузки файлового конфига или просто прочитаем его здесь.
        
        $config = require __DIR__ . '/../config.php';
        
        // Fallback to defaults if missing
        $chatConfig = $config['chat'] ?? [];
        $this->apiUrl = $chatConfig['centrifugo_api_url'] ?? 'http://centrifugo:8000/api';
        $this->apiKey = $chatConfig['centrifugo_api_key'] ?? '';
        $this->secret = $chatConfig['centrifugo_secret'] ?? '';
    }

    public function publish($channel, $data) {
        if (empty($this->apiKey)) return false; // Not configured

        $commands = [
            [
                'method' => 'publish',
                'params' => [
                    'channel' => $channel,
                    'data' => $data
                ]
            ]
        ];

        return $this->send($commands);
    }

    public function broadcast($channels, $data) {
        if (empty($this->apiKey)) return false;

        $commands = [
            [
                'method' => 'broadcast',
                'params' => [
                    'channels' => $channels,
                    'data' => $data
                ]
            ]
        ];

        return $this->send($commands);
    }

    public function presence($channel) {
        if (empty($this->apiKey)) return null;

        $commands = [
            [
                'method' => 'presence',
                'params' => [
                    'channel' => $channel
                ]
            ]
        ];

        // Send expects array of commands
        // Response will be object or array of responses?
        // My send method returns boolean usually, but I need data now.
        // I need to refactor send() or create a new internal method that returns data.
        
        return $this->sendAndGetData($commands);
    }

    public function generateToken($userId, $exp = 0) {
        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $payload = ['sub' => (string)$userId];
        if ($exp) {
            $payload['exp'] = $exp;
        }
        
        $segments = [];
        $segments[] = $this->urlSafeB64Encode(json_encode($header));
        $segments[] = $this->urlSafeB64Encode(json_encode($payload));
        
        $signing_input = implode('.', $segments);
        $signature = $this->sign($signing_input, $this->secret);
        $segments[] = $this->urlSafeB64Encode($signature);
        
        return implode('.', $segments);
    }

    private function sign($msg, $key) {
        return hash_hmac('sha256', $msg, $key, true);
    }

    private function urlSafeB64Encode($data) {
        $b64 = base64_encode($data);
        $b64 = str_replace(['+', '/', '='], ['-', '_', ''], $b64);
        return $b64;
    }

    private function send($commands) {
        $result = $this->sendAndGetData($commands);
        return $result !== null;
    }

    private function sendAndGetData($commands) {
        $cmd = $commands[0];
        $method = $cmd['method'];
        $params = $cmd['params'];
        
        $url = rtrim($this->apiUrl, '/') . '/' . $method;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-API-Key: ' . $this->apiKey
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($response === false) {
            error_log("Centrifugo cURL Error: " . curl_error($ch));
            curl_close($ch);
            return null;
        } 
        
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("Centrifugo API [$url] ($httpCode): " . $response);
            return null;
        }

        return json_decode($response, true);
    }
}
