<?php

namespace Api;

use Domain\FeedbackManager;
use Infra\ConfigManager;
use LLM\ImageGenerator;
use LLM\JobQueue;

/**
 * Метрики Лиры для дашборда (MLP-280, MVP без новых таблиц):
 * журнал llm_jobs (неделя), генерации картинок, беклог, здоровье воркера.
 * Роль admin проверяет роутер ДО вызова.
 */
class BotMetricsController {

    public static function get(): void {
        $q = new JobQueue();
        $fm = new FeedbackManager();
        $c = ConfigManager::getInstance();

        $lyraDir = dirname(__DIR__, 2) . '/upload/lyra';
        $hb = (int)$c->getOption('bot_worker_heartbeat', 0);

        Response::json(true, "Метрики получены", 'success', [
            'jobs' => $q->stats(168),
            'images' => [
                'today' => ImageGenerator::todayCount(),
                'daily_limit' => (int)$c->getOption('ai_image_daily_limit', 20),
                'total_files' => is_dir($lyraDir) ? count(glob($lyraDir . '/*.jpg') ?: []) : 0,
            ],
            'feedback' => [
                'new' => $fm->countNew(),
                'done' => $fm->getPage(1, 0, 'done')['total'],
                'dismissed' => $fm->getPage(1, 0, 'dismissed')['total'],
            ],
            'worker' => [
                'heartbeat_age' => $hb > 0 ? time() - $hb : null,
                'mode' => (string)$c->getOption('ai_worker_mode', 'auto'),
            ],
        ]);
    }
}
