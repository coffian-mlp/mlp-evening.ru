<?php

return [
    'db' => [
        'host' => 'db', // Обычно 'db' для Docker или 'localhost'
        'name' => 'coffian_eplist',
        'user' => 'root',
        'pass' => 'YOUR_PASSWORD_HERE',
        'charset' => 'utf8mb4'
    ],
    'chat' => [
        'driver' => 'sse', // 'sse' or 'centrifugo'
        'centrifugo_api_url' => 'http://127.0.0.1:8000/api',
        'centrifugo_api_key' => 'YOUR_API_KEY',
        'centrifugo_secret'  => 'YOUR_TOKEN_SECRET',
    ]
];
