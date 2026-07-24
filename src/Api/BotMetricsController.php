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

        $hb = (int)$c->getOption('bot_worker_heartbeat', 0);

        Response::json(true, "Метрики получены", 'success', [
            'jobs' => $q->stats(168),
            'images' => [
                'today' => ImageGenerator::todayCount(),
                'daily_limit' => (int)$c->getOption('ai_image_daily_limit', 20),
                'total_files' => self::totalDrawings(),
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

    /**
     * Всего рисунков в /upload/lyra (MLP-289, AR7-L7): glob по каталогу дорог
     * при разрастании — кешируем на 5 минут через Core\FileCache('imagegen'),
     * тот же подкаталог, что у дневного счётчика художницы.
     */
    private static function totalDrawings(): int {
        $cache = new \Core\FileCache('imagegen');
        $cached = $cache->get('total_files', 300);
        if ($cached !== null && isset($cached['n'])) {
            return (int)$cached['n'];
        }
        $lyraDir = dirname(__DIR__, 2) . '/upload/lyra';
        $n = is_dir($lyraDir) ? count(glob($lyraDir . '/*.jpg') ?: []) : 0;
        $cache->set('total_files', ['n' => $n]);
        return $n;
    }
}
