<?php

namespace LLM;

/**
 * Ответ оборван потолком max_tokens (finish_reason = "length", MLP-348).
 * Обрубок хранится в $partial для debug-журнала; вызывающему его не отдают:
 * LLMManager::askWithFallback считает такую попытку неудачной.
 */
class TruncatedResponseException extends \Exception {
    public string $partial;

    public function __construct(string $provider, string $partial) {
        $this->partial = $partial;
        parent::__construct("{$provider}: ответ оборван потолком токенов (finish_reason=length), получено "
            . mb_strlen($partial) . ' симв.');
    }
}
