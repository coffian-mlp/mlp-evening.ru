<?php
$apiKey = 'dummy';

// Test with Bearer instead of Api-Key
$ch = curl_init('https://llm.api.cloud.yandex.net/v1/chat/completions');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'model' => 'gpt://b1gu0jm1h4vr4ggcq5la/deepseek-v32/latest',
    'messages' => [['role' => 'user', 'content' => 'hello']]
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $apiKey,
    'Content-Type: application/json'
]);
echo "Test Bearer:\n" . curl_exec($ch) . "\n\n";
curl_close($ch);
