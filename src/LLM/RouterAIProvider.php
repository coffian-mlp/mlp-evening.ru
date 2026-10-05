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
    /** Таймаут HTTP-запроса, с; для отдельного вызова — копия через withTimeout() (MLP-358). */
    private int $timeoutSec = 60;
    private ?array $webSearch = null;
    private array $searchEvidence = [];
    private ?string $reasoningEffort = null;

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
        if ($this->webSearch !== null) {
            $data['plugins'] = [$this->webSearch];
        }
        if ($this->reasoningEffort !== null) {
            $data['reasoning'] = ['effort' => $this->reasoningEffort];
        }

        $ch = curl_init($url);
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
        $this->searchEvidence = [];
        if ($this->webSearch !== null) {
            foreach (($decoded['choices'][0]['message']['annotations'] ?? []) as $annotation) {
                $citation = $annotation['url_citation'] ?? null;
                if (is_array($citation) && filter_var($citation['url'] ?? '', FILTER_VALIDATE_URL)
                    && preg_match('~^https?://~i', $citation['url'])) {
                    $this->searchEvidence[] = ['url' => $citation['url'], 'title' => (string)($citation['title'] ?? '')];
                }
            }
        }
        // MLP-348: finish_reason = length → TruncatedResponseException (обрубок не выдаётся за ответ).
        $content = TokenBudget::contentOf(is_array($decoded) ? $decoded : [], 'RouterAI');
        if ($content !== null) {
            return $content;
        }

        throw new Exception("RouterAI Invalid Response: " . $response);
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

    /** Search is opt-in on an isolated utility-provider clone. */
    public function withWebSearch(int $maxResults = 3): static {
        $copy = clone $this;
        $copy->webSearch = ['id' => 'web', 'engine' => 'exa', 'max_results' => max(1, min(3, $maxResults))];
        $copy->searchEvidence = [];
        return $copy;
    }

    public function getSearchEvidence(): array {
        return $this->searchEvidence;
    }

    /** RouterAI unified reasoning control; ordinary provider keeps its model default. */
    public function withReasoningEffort(string $effort): static {
        if (!in_array($effort, ['low', 'high', 'max'], true)) {
            throw new \InvalidArgumentException('Unsupported reasoning effort');
        }
        $copy = clone $this;
        $copy->reasoningEffort = $effort;
        return $copy;
    }
}
