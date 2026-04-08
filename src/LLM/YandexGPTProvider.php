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
        // Модели YandexGPT и AliceAI работают через основной Foundation Models API
        $isOpenAiCompatible = strpos($modelUri, 'yandexgpt') === false && strpos($modelUri, 'alice') === false;

        if ($isOpenAiCompatible) {
            // ВАЖНО: OpenAI-совместимый эндпоинт Яндекса находится не на llm.api.cloud.yandex.net!
            // В официальной документации для OpenAI-совместимого API указано использовать другой URL.
            // Но ошибка "grpcCode:3" говорит о том, что роутер Яндекса на llm.api.cloud.yandex.net/v1/chat/completions
            // всё ещё пытается проксировать запрос через старый gRPC сервис Foundation Models, 
            // который вообще не знает про модель deepseek!
            // Чтобы Яндекс понял, что это DeepSeek и отправил его в OpenAI-шлюз, 
            // URI должен быть ТОЧНО таким, какой выдает Яндекс в консоли. 
            // А именно: 'gpt://[каталог]/deepseek-v32/latest'
            
            // Если он всё равно выдает 'invalid model_uri', возможно нам нужно использовать 'Authorization: Bearer <API-КЛЮЧ>'
            // Давайте попробуем заголовок 'Bearer ' вместо 'Api-Key '
            
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
                'model' => $modelUri, // Оставляем gpt://... , так как без него Яндекс выдает {"error":{"message":"Failed to parse model URI","type":"invalid_request_error"}}
                'messages' => $messages,
                'temperature' => 0.6,
                'max_tokens' => 5000
            ];
            
            // И вот еще нюанс: для API v1/chat/completions Яндекса URI модели должен быть в формате просто `deepseek-v32` или `deepseek-v32/latest`
            // или может быть `gpt://[id]/[модель]` всё же работает?
            // Ошибка "invalid model_uri" с grpcCode:3 приходит от старого Foundation Models! 
            // Это значит, что наш запрос каким-то образом попадает туда, а не в новый роутер OpenAI.
            // Причина: мы шлём 'Authorization: Api-Key', а для OpenAI-совместимого API у Яндекса требуется Bearer или Api-Key. 
            // Давай попробуем сформировать modelUri именно так, как они просят в доке: 'deepseek-v32' или 'gpt://...'.
            
            // Заменим 'Api-Key ' на 'Bearer '
            $authHeader = 'Authorization: Bearer ' . $this->apiKey; 
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
                    'maxTokens' => '5000'
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
