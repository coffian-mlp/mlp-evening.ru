<?php
$apiKey = 'dummy'; // Doesn't matter, we want to see the error type

// Test 1: OpenAI endpoint with gpt://...
$ch = curl_init('https://llm.api.cloud.yandex.net/v1/chat/completions');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'model' => 'gpt://b1gu0jm1h4vr4ggcq5la/deepseek-v32/latest',
    'messages' => [['role' => 'user', 'content' => 'hello']]
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Api-Key ' . $apiKey,
    'Content-Type: application/json'
]);
echo "Test 1 (OpenAI, model=gpt://...):\n" . curl_exec($ch) . "\n\n";
curl_close($ch);

// Test 2: OpenAI endpoint with just the path
$ch = curl_init('https://llm.api.cloud.yandex.net/v1/chat/completions');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'model' => 'b1gu0jm1h4vr4ggcq5la/deepseek-v32/latest',
    'messages' => [['role' => 'user', 'content' => 'hello']]
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Api-Key ' . $apiKey,
    'Content-Type: application/json'
]);
echo "Test 2 (OpenAI, model=path):\n" . curl_exec($ch) . "\n\n";
curl_close($ch);
