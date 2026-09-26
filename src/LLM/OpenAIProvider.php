<?php

namespace LLM;

use Exception;


class OpenAIProvider implements LLMProviderInterface {
    private $apiKey;
    private $model;
    private $baseUrl;
    private $proxyUrl;

    public function __construct($apiKey, $model = 'gpt-4o-mini', $baseUrl = 'https://api.openai.com/v1/chat/completions', $proxyUrl = null) {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->baseUrl = $baseUrl; // Позволяет переопределить URL для GitHub Models, Groq и т.д.
        $this->proxyUrl = $proxyUrl;
    }

    public function getModel(): string {
        return (string)$this->model;
    }

    public function askChat(array $messagesContext, string $systemPrompt): ?string {
        if (empty($this->apiKey)) {
            throw new Exception("OpenAI API key is missing");
        }

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt]
        ];
        
        $messages = array_merge($messages, $messagesContext);

        $messages = VisionFormatter::maybeExpand($messages); // vision: развернуть картинки в image_url

        $data = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => 0.7,
            'max_tokens' => self::MAX_TOKENS
        ];

        $ch = curl_init($this->baseUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // MLP-314: таймауты внешних вызовов — зависший провайдер не держит воркер/веб-запрос.
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json'
        ]);

        if ($this->proxyUrl) {
            curl_setopt($ch, CURLOPT_PROXY, $this->proxyUrl);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("OpenAI cURL Error: " . $error);
        }

        if ($httpCode >= 400) {
            throw new Exception("OpenAI HTTP Error $httpCode: " . $response);
        }

        $decoded = json_decode($response, true);
        if (isset($decoded['choices'][0]['message']['content'])) {
            return trim($decoded['choices'][0]['message']['content']);
        }

        throw new Exception("OpenAI Invalid Response: " . $response);
    }

    /**
     * Потолок completion-токенов на ответ (MLP-347). У reasoning-моделей (z-ai/glm-5.3 и т.п.)
     * рассуждения входят в тот же лимит, что и текст ответа: при прежних 2000 длинное рассуждение
     * (до ~1950 токенов) оставляло на ответ несколько слов, и сцена режиссёра обрывалась на полуслове
     * (8 из 40 вызовов за неделю, 2 пустых). Это потолок, а не расход: оплачиваются фактические токены.
     */
    public const MAX_TOKENS = 5000;
}
