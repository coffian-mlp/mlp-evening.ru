<?php
/**
 * Точка входа воркера бота Лиры.
 *
 * Режим берётся из site_options.ai_worker_mode (или аргумента):
 *   daemon — постоянный процесс (systemd), опрос каждые ai_worker_poll сек;
 *   cron   — крутится ~55с и выходит (запускается cron раз в минуту);
 *   inline — один проход (обычно воркер так не запускают, обработка идёт в веб-запросе);
 *   auto   — как cron (очередь primary; inline-фоллбек живёт на стороне продюсера в api.php).
 *
 * Примеры:
 *   php bot_worker.php            # режим из настроек
 *   php bot_worker.php daemon     # принудительно демон (для systemd unit)
 */

require_once __DIR__ . '/src/LLM/BotWorker.php';

$config = ConfigManager::getInstance();
$mode = $argv[1] ?? $config->getOption('ai_worker_mode', 'auto');
$poll = (int)$config->getOption('ai_worker_poll', 3);

$worker = new BotWorker();

switch ($mode) {
    case 'daemon':
        while (true) {
            $worker->tick();
            sleep(max(1, $poll));
        }
        break;

    case 'inline':
        $worker->tick();
        break;

    case 'cron':
    case 'auto':
    default:
        // cron раз в минуту запускает воркер, который крутится ~55с и завершается.
        $worker->runFor(55, $poll);
        break;
}
