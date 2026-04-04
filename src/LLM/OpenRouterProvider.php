<?php

require_once __DIR__ . '/LLMProviderInterface.php';

class OpenRouterProvider implements LLMProviderInterface {
    private $apiKey;
    private $model;
    private $proxyUrl;

    public function __construct($apiKey, $model = 'qwen/qwen-2.5-coder-32b-instruct:free', $proxyUrl = null) {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->proxyUrl = $proxyUrl;
    }

    public function askChat(array $messagesContext, string $systemPrompt): ?string {
        if (empty($this->apiKey)) {
            throw new Exception("OpenRouter API key is missing");
        }

        $url = 'https://openrouter.ai/api/v1/chat/completions';

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt]
        ];
        
        $messages = array_merge($messages, $messagesContext);

        $data = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => 0.7,
            'max_tokens' => 5000 // Увеличили лимит токенов
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->apiKey,
            'HTTP-Referer: https://mlp-evening.local', // Optional, for OpenRouter rankings
            'X-Title: MLP Evening Chat', // Optional, for OpenRouter rankings
            'Content-Type: application/json'
        ]);
        
        // If proxy is needed, it can be set here
        if ($this->proxyUrl) {
            curl_setopt($ch, CURLOPT_PROXY, $this->proxyUrl);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("OpenRouter cURL Error: " . $error);
        }

        if ($httpCode >= 400) {
            throw new Exception("OpenRouter HTTP Error $httpCode: " . $response);
        }

        $decoded = json_decode($response, true);
        if (isset($decoded['choices'][0]['message']['content'])) {
            return trim($decoded['choices'][0]['message']['content']);
        }

        throw new Exception("OpenRouter Invalid Response: " . $response);
    }
}
