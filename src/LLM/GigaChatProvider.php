<?php

require_once __DIR__ . '/LLMProviderInterface.php';

class GigaChatProvider implements LLMProviderInterface {
    private $authKey;
    private $accessToken = null;
    private $tokenExpiresAt = 0;

    public function __construct($authKey) {
        $this->authKey = $authKey;
    }

    private function getAccessToken() {
        if ($this->accessToken && time() < $this->tokenExpiresAt) {
            return $this->accessToken;
        }

        $ch = curl_init('https://ngw.devices.sberbank.ru:9443/api/v2/oauth');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'scope=GIGACHAT_API_PERS');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
            'RqUID: ' . uniqid(),
            'Authorization: Basic ' . $this->authKey
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // GigaChat often requires ignoring SSL or adding Russian certs

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            throw new Exception("GigaChat Auth Error $httpCode: " . $response);
        }

        $decoded = json_decode($response, true);
        if (isset($decoded['access_token'])) {
            $this->accessToken = $decoded['access_token'];
            $this->tokenExpiresAt = ($decoded['expires_at'] / 1000) - 60; // Convert to seconds, minus 1 min buffer
            return $this->accessToken;
        }

        throw new Exception("GigaChat Auth Invalid Response: " . $response);
    }

    public function askChat(array $messagesContext, string $systemPrompt): ?string {
        if (empty($this->authKey)) {
            throw new Exception("GigaChat Auth Key is missing");
        }

        $token = $this->getAccessToken();
        $url = 'https://gigachat.devices.sberbank.ru/api/v1/chat/completions';

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt]
        ];
        
        $messages = array_merge($messages, $messagesContext);

        $data = [
            'model' => 'GigaChat',
            'messages' => $messages,
            'temperature' => 0.7,
            'max_tokens' => 2000
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $token
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("GigaChat cURL Error: " . $error);
        }

        if ($httpCode >= 400) {
            throw new Exception("GigaChat HTTP Error $httpCode: " . $response);
        }

        $decoded = json_decode($response, true);
        if (isset($decoded['choices'][0]['message']['content'])) {
            return trim($decoded['choices'][0]['message']['content']);
        }

        throw new Exception("GigaChat Invalid Response: " . $response);
    }
}
