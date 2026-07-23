<?php
use Infra\ConfigManager;
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

// MLP-274: крон глушит stderr — направляем error_log воркера в файл,
// иначе сбои генерации/LLM теряются бесследно.
ini_set('error_log', __DIR__ . '/logs/worker.log');

// Демон живёт дольше деплоя (git pull): прогреваем классы эагерно,
// чтобы лениво догруженный ПОСЛЕ pull класс не смешал старую и новую версии кода.
foreach (['Core', 'Infra', 'Domain', 'LLM', 'Social', 'Api'] as $preloadDir) {
    foreach (glob(__DIR__ . "/src/{$preloadDir}/*.php") as $preloadFile) {
        $preloadClass = $preloadDir . '\\' . basename($preloadFile, '.php');
        class_exists($preloadClass) || interface_exists($preloadClass);
    }
}
unset($preloadDir, $preloadFile, $preloadClass);


$config = ConfigManager::getInstance();
$poll = (int)$config->getOption('ai_worker_poll', 3);

(new BotWorker())->runFor(55, $poll);
