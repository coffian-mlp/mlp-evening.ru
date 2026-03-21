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

        $modelUri = trim($this->folderId);
        
        // Автодополнение URI, если ввели просто ID каталога (без слэшей и gpt://)
        if (strpos($modelUri, 'gpt://') !== 0 && strpos($modelUri, '/') === false) {
             // Просто ID каталога - значит это стандартная яндексовская модель
             $modelUri = "gpt://$modelUri/yandexgpt-lite/latest";
        } elseif (strpos($modelUri, 'gpt://') !== 0) {
             // Ввели что-то со слэшами, например b1gu0jm1h4vr4ggcq5la/deepseek-v32/latest
             $modelUri = "gpt://" . $modelUri;
        }

        // Если это сторонняя модель (DeepSeek, Llama и т.д.), Яндексу нужен OpenAI-совместимый API
        $isOpenAiCompatible = strpos($modelUri, 'yandexgpt') === false;

        // Явный фикс для Яндекса. У них OpenAI-совместимый API на самом деле ожидает путь БЕЗ gpt://
        // Судя по всему, они транслируют OpenAI запросы к своим Foundation Models, 
        // и если мы передаем URI в формате gpt://..., транслятор не может его распарсить.
        if ($isOpenAiCompatible) {
            $modelForApi = str_replace('gpt://', '', $modelUri); // Убираем gpt://
            
            $url = 'https://llm.api.cloud.yandex.net/v1/chat/completions';
            
            $messages = [
                ['role' => 'system', 'content' => $systemPrompt]
            ];
            foreach ($messagesContext as $msg) {
                $messages[] = [
                    'role' => $msg['role'],
                    'content' => $msg['content']
                ];
            }
            
            $data = [
                'model' => $modelForApi, // передаем без gpt://
                'messages' => $messages,
                'temperature' => 0.6,
                'max_tokens' => 500
            ];
            $authHeader = 'Authorization: Api-Key ' . $this->apiKey; // И для OpenAI API Яндекс понимает Api-Key
        } else {
            $url = 'https://llm.api.cloud.yandex.net/foundationModels/v1/completion';
            
            $messages = [
                ['role' => 'system', 'text' => $systemPrompt]
            ];
            foreach ($messagesContext as $msg) {
                $messages[] = [
                    'role' => $msg['role'],
                    'text' => $msg['content']
                ];
            }
            
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

        // DEBUG: Логируем запрос, чтобы понять куда и что мы отправляем
        error_log("YANDEX DEBUG: URL=" . $url);
        error_log("YANDEX DEBUG: DATA=" . json_encode($data));

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
