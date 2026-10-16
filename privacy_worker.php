<?php
/** Scheduled privacy maintenance is independent of the LLM provider and queue. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/autoload.php';
$db = \Infra\Database::getInstance()->getConnection();
if ((int)$db->query("SELECT GET_LOCK('privacy-maintenance',0) ok")->fetch_assoc()['ok'] !== 1) exit;
try {
    \Domain\AccountErasure::maintain();
    \Domain\UxStatistics::maintain();
    \Domain\Privacy::maintain();
    \Infra\ConfigManager::getInstance()->setOption('privacy_worker_heartbeat', (string)time());
} catch (Throwable $e) {
    error_log('Privacy maintenance failed: ' . get_class($e) . ': ' . $e->getMessage());
    exit(1);
} finally {
    $db->query("SELECT RELEASE_LOCK('privacy-maintenance')");
}
