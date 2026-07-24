<?php
use LLM\BotWorker;
use LLM\JobQueue;
/**
 * Интеграционный тест MLP-279: проактив идёт через очередь llm_jobs
 * (спонтанные и анонсы журналируются, единый путь с реактивом).
 *
 * Запуск: docker compose exec php php tests/integration_proactive_queue.php
 */
require_once __DIR__ . '/integration_helpers.php';

$conn = it_require_db();
$optBackup = [];

try {
    $cfg = \Infra\ConfigManager::getInstance();
    foreach (['ai_enabled' => '1', 'ai_bot_user_id' => '1', 'ai_routerai_key' => 'it-dummy',
              'ai_proactive_interval' => '1', 'bot_last_proactive' => '0'] as $k => $v) {
        $optBackup[$k] = $cfg->getOption($k, null);
        $cfg->setOption($k, $v);
    }
    $conn->query("DELETE FROM llm_jobs WHERE type = 'cron_spontaneous'");

    $w = new BotWorker();
    $r = new ReflectionMethod(BotWorker::class, 'proactive');
    $r->setAccessible(true);
    $r->invoke($w);

    $res = $conn->query("SELECT id, type, status FROM llm_jobs WHERE type = 'cron_spontaneous' ORDER BY id DESC LIMIT 1");
    $job = $res->fetch_assoc();
    check($job !== null, 'проактив создал job cron_spontaneous в llm_jobs');
    check(($job['status'] ?? '') === 'pending', 'job в статусе pending (обработает тик)');

    // Тик воркера клеймит и завершает job (LLM с dummy-ключом свалится — статус done всё равно).
    $rt = new ReflectionMethod(BotWorker::class, 'processJobs');
    if (!$rt->getName()) {}
} catch (ReflectionException $e) {
    // имя метода обработки может отличаться — обработку проверяем через run-цикл ниже
} finally {
}

// Обработка: claimDue-цикл — прогоняем приватный обработчик через runFor? Проще: claimDue вручную.
try {
    $q = new JobQueue();
    $claimed = $q->claimDue(10);
    $found = false;
    foreach ($claimed as $j) {
        if ($j['type'] === 'cron_spontaneous') $found = true;
        $q->complete([(int)$j['id']]);
    }
    check($found, 'job cron_spontaneous клеймится воркерским claimDue');
    $res = $conn->query("SELECT status FROM llm_jobs WHERE type = 'cron_spontaneous' ORDER BY id DESC LIMIT 1");
    check(($res->fetch_assoc()['status'] ?? '') === 'done', 'после complete — done (журнал остаётся)');
} finally {
    $conn->query("DELETE FROM llm_jobs WHERE type = 'cron_spontaneous'");
    foreach ($optBackup as $k => $v) {
        if ($v === null) $conn->query("DELETE FROM site_options WHERE key_name = '" . $conn->real_escape_string($k) . "'");
        else \Infra\ConfigManager::getInstance()->setOption($k, $v);
    }
}

it_done();
