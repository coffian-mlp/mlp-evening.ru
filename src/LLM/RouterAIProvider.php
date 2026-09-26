<?php

namespace LLM;

use Exception;


/**
 * RouterAI (routerai.ru) — российский агрегатор LLM с OpenAI-совместимым API.
 * Формат запроса/ответа аналогичен OpenAI и OpenRouter.
 * В отличие от OpenRouter не блокируется Cloudflare по гео (RU), поэтому прокси обычно не требуется.
 */
class RouterAIProvider implements LLMProviderInterface {
    private $apiKey;
    private $model;
    private $proxyUrl;
    /** Потолок токенов ответа; null — из дашборда (TokenBudget::fromConfig, MLP-348). */
    private ?int $maxTokens;

    public function __construct($apiKey, $model = 'openai/gpt-4o-mini', $proxyUrl = null, ?int $maxTokens = null) {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->proxyUrl = $proxyUrl;
        $this->maxTokens = $maxTokens; // MLP-348: null — потолок из настройки ai_max_tokens
    }

    public function getModel(): string {
        return (string)$this->model;
    }

    public function askChat(array $messagesContext, string $systemPrompt): ?string {
        if (empty($this->apiKey)) {
            throw new Exception("RouterAI API key is missing");
        }

        $url = 'https://routerai.ru/api/v1/chat/completions';

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

        $ch = curl_init($url);
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

        // Прокси для РФ обычно не нужен, но оставляем для единообразия с остальными провайдерами
        if ($this->proxyUrl) {
            curl_setopt($ch, CURLOPT_PROXY, $this->proxyUrl);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("RouterAI cURL Error: " . $error);
        }

        if ($httpCode >= 400) {
            throw new Exception("RouterAI HTTP Error $httpCode: " . $response);
        }

        $decoded = json_decode($response, true);
        // MLP-348: finish_reason = length → TruncatedResponseException (обрубок не выдаётся за ответ).
        $content = TokenBudget::contentOf(is_array($decoded) ? $decoded : [], 'RouterAI');
        if ($content !== null) {
            return $content;
        }

        throw new Exception("RouterAI Invalid Response: " . $response);
    }
}
