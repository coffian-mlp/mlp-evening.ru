<?php
$url = 'https://message.api.cloud.yandex.net/v1/chat/completions';
$data = ['model' => 'gpt://b1gu0jm1h4vr4ggcq5la/deepseek-v32/latest', 'messages' => [['role' => 'user', 'content' => 'hello']]];
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
echo "1. $httpCode $response\n";
curl_close($ch);

$url2 = 'https://llm.api.cloud.yandex.net/v1/chat/completions';
$ch = curl_init($url2);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
echo "2. $httpCode $response\n";
curl_close($ch);
