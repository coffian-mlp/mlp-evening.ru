<?php

// Этот скрипт предназначен для запуска через cron (например, раз в 5-10 минут)
// * /5 * * * * php /path/to/mlp-evening/cron_llm.php

require_once __DIR__ . '/src/LLM/LLMManager.php';

// Логирование запусков
$logFile = __DIR__ . '/cron_llm.log';
$timestamp = date('Y-m-d H:i:s');

function logMessage($file, $time, $msg) {
    file_put_contents($file, "[$time] $msg\n", FILE_APPEND);
    echo $msg . "\n";
}

$llm = new LLMManager();

// Пытаемся сгенерировать спонтанное сообщение
$result = $llm->processTrigger('cron_spontaneous');

if ($result) {
    logMessage($logFile, $timestamp, "Спонтанное сообщение отправлено.");
} else {
    logMessage($logFile, $timestamp, "Пони решила промолчать (или бот отключен/чат пуст).");
}
