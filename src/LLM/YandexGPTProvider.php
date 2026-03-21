<?php

require_once __DIR__ . '/LLMProviderInterface.php';

class YandexGPTProvider implements LLMProviderInterface {
    private $apiKey;
    private $folderId;

    public function __construct($apiKey, $folderId) {
        $this->apiKey = $apiKey;
        $this->folderId = $folderId;
    }

    public function askChat(array $messagesContext, string $systemPrompt): ?string {
        if (empty($this->apiKey) || empty($this->folderId)) {
            throw new Exception("YandexGPT API key or Folder ID is missing");
        }

        $url = 'https://llm.api.cloud.yandex.net/foundationModels/v1/completion';

        // YandexGPT uses slightly different role names sometimes, but system/user/assistant are standard.
        $messages = [
            ['role' => 'system', 'text' => $systemPrompt]
        ];
        
        foreach ($messagesContext as $msg) {
            $messages[] = [
                'role' => $msg['role'],
                'text' => $msg['content']
            ];
        }

        $modelUri = strpos($this->folderId, 'gpt://') === 0 
            ? $this->folderId 
            : "gpt://$this->folderId/yandexgpt-lite/latest";

        // Если это сторонняя модель (DeepSeek, Llama и т.д.), Яндексу нужен OpenAI-совместимый API
        $isOpenAiCompatible = strpos($modelUri, 'yandexgpt') === false;

        if ($isOpenAiCompatible) {
            $url = 'https://llm.api.cloud.yandex.net/v1/chat/completions';
            $data = [
                'model' => $modelUri,
                'messages' => $messages,
                'temperature' => 0.6,
                'max_tokens' => 500
            ];
            $authHeader = 'Authorization: Api-Key ' . $this->apiKey; // И для OpenAI API Яндекс понимает Api-Key
        } else {
            $url = 'https://llm.api.cloud.yandex.net/foundationModels/v1/completion';
            $data = [
                'modelUri' => $modelUri,
                'completionOptions' => [
                    'stream' => false,
                    'temperature' => 0.6,
                    'maxTokens' => '500'
                ],
                'messages' => $messages
            ];
            $authHeader = 'Authorization: Api-Key ' . $this->apiKey;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            $authHeader,
            'Content-Type: application/json'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("YandexGPT cURL Error: " . $error);
        }

        if ($httpCode >= 400) {
            throw new Exception("YandexGPT HTTP Error $httpCode: " . $response);
        }

        $decoded = json_decode($response, true);
        
        if ($isOpenAiCompatible) {
            // OpenAI API format
            if (isset($decoded['choices'][0]['message']['content'])) {
                return trim($decoded['choices'][0]['message']['content']);
            }
        } else {
            // Yandex Foundation Models format
            if (isset($decoded['result']['alternatives'][0]['message']['text'])) {
                return trim($decoded['result']['alternatives'][0]['message']['text']);
            }
        }

        throw new Exception("YandexGPT Invalid Response: " . $response);
    }
}
