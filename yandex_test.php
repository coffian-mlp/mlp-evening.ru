<?php
require_once __DIR__ . '/src/LLM/YandexGPTProvider.php';

// Just dummy values
$provider = new YandexGPTProvider('dummy_key', 'gpt://b1gu0jm1h4vr4ggcq5la/deepseek-v32/latest');
try {
    $provider->askChat([['role' => 'user', 'content' => 'hello']], 'system prompt');
} catch (Exception $e) {
    echo $e->getMessage() . "\n";
}
