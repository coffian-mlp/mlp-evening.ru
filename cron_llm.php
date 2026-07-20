<?php
use LLM\BotWorker;
/**
 * Cron-вход бота Лиры.
 *
 * Раньше здесь жила логика проактива (спонтанные сообщения + анонсы расписания).
 * Теперь она — в BotWorker (единый голос: реактив из очереди + проактив по таймеру),
 * чтобы не было гонок cron ↔ веб-запросы.
 *
 * Скрипт крутит воркер ~55с за запуск. Рекомендуемое расписание: раз в минуту.
 * Может работать и на прежнем «раз в 4 минуты» — тогда реактив и проактив просто реже.
 */

// CLI-only: из веба этот вход недоступен (MLP-220, finding L3).
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/autoload.php'; // MLP-248

// Демон живёт дольше деплоя (git pull): прогреваем весь classmap эагерно,
// чтобы лениво догруженный ПОСЛЕ pull класс не смешал старую и новую версии кода.
foreach (array_keys(require __DIR__ . '/src/classmap.php') as $preloadClass) {
    class_exists($preloadClass) || interface_exists($preloadClass);
}
unset($preloadClass);


$config = ConfigManager::getInstance();
$poll = (int)$config->getOption('ai_worker_poll', 3);

(new BotWorker())->runFor(55, $poll);
