<?php

// Этот скрипт предназначен для запуска через cron (например, раз в 5-10 минут)
// * /5 * * * * php /path/to/mlp-evening/cron_llm.php

require_once __DIR__ . '/src/LLM/LLMManager.php';

$llm = new LLMManager();

// Пытаемся сгенерировать спонтанное сообщение
$result = $llm->processTrigger('cron_spontaneous');

if ($result) {
    echo "Спонтанное сообщение отправлено.\n";
} else {
    echo "Пони решила промолчать (или бот отключен).\n";
}
