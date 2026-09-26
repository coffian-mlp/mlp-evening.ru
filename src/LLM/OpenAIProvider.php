<?php

namespace LLM;

use Exception;


class OpenAIProvider implements LLMProviderInterface {
    private $apiKey;
    private $model;
    private $baseUrl;
    private $proxyUrl;
    /** Потолок токенов ответа; null — из дашборда (TokenBudget::fromConfig, MLP-348). */
    private ?int $maxTokens;
    /** Таймаут HTTP-запроса, с; для отдельного вызова — копия через withTimeout() (MLP-358). */
    private int $timeoutSec = 60;

    public function __construct($apiKey, $model = 'gpt-4o-mini', $baseUrl = 'https://api.openai.com/v1/chat/completions', $proxyUrl = null, ?int $maxTokens = null) {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->baseUrl = $baseUrl; // Позволяет переопределить URL для GitHub Models, Groq и т.д.
        $this->proxyUrl = $proxyUrl;
        $this->maxTokens = $maxTokens; // MLP-348: null — потолок из настройки ai_max_tokens
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
            'max_tokens' => $this->maxTokens ?? TokenBudget::fromConfig() // MLP-347/348
        ];

        $ch = curl_init($this->baseUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // MLP-314: таймауты внешних вызовов — зависший провайдер не держит воркер/веб-запрос.
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeoutSec); // MLP-358: по умолчанию 60 с
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
        // MLP-348: finish_reason = length → TruncatedResponseException (обрубок не выдаётся за ответ).
        $content = TokenBudget::contentOf(is_array($decoded) ? $decoded : [], 'OpenAI');
        if ($content !== null) {
            return $content;
        }

        throw new Exception("OpenAI Invalid Response: " . $response);
    }

    /**
     * Копия провайдера с другим таймаутом запроса (MLP-358): долгим задачам вроде режиссёра сцены —
     * больше времени, не трогая общий экземпляр и остальные вызовы. Минимум 5 с.
     */
    public function withTimeout(int $sec): static {
        $copy = clone $this;
        $copy->timeoutSec = max(5, $sec);
        return $copy;
    }
}
