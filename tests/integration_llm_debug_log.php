<?php
use LLM\ImageGenerator;
use LLM\LlmDebugLog;
/**
 * Интеграционный тест MLP-297/298: debug-журнал нейронок llm_debug_log
 * (вставка, TTL-чистка при вставке, выключатель ai_debug_log) и
 * ImageGenerator::lastError без сети (пустой ключ провайдера).
 *
 * Запуск: docker compose exec php php tests/integration_llm_debug_log.php
 */
require_once __DIR__ . '/integration_helpers.php';

$conn = it_require_db();
$optBackup = [];

try {
    $cfg = \Infra\ConfigManager::getInstance();
    foreach (['ai_debug_log' => '1', 'ai_image_provider' => 'routerai', 'ai_routerai_key' => ''] as $k => $v) {
        $optBackup[$k] = $cfg->getOption($k, null);
        $cfg->setOption($k, $v);
    }

    echo "== таблица на месте (миграция 2026_07_24_llm_debug_log) ==\n";
    $res = $conn->query("SHOW TABLES LIKE 'llm_debug_log'");
    check($res && $res->num_rows === 1, 'таблица llm_debug_log существует');

    echo "== вставка обмена ==\n";
    $marker = 'it-dbg-' . getmypid() . '-' . time();
    LlmDebugLog::log('utility', 'ItTest', 'it/model', ['system' => $marker, 'messages' => []], 'ответ модели', 'ok', 123);
    $row = $conn->query("SELECT * FROM llm_debug_log WHERE request LIKE '%$marker%' ORDER BY id DESC LIMIT 1")->fetch_assoc();
    check((bool)$row, 'обмен записан');
    check($row && $row['kind'] === 'utility' && $row['provider'] === 'ItTest' && $row['model'] === 'it/model', 'kind/provider/model на месте');
    check($row && $row['status'] === 'ok' && (int)$row['duration_ms'] === 123, 'status и duration');
    check($row && str_contains((string)$row['response'], 'ответ модели'), 'ответ сохранён');

    echo "== TTL: старые записи выметаются при вставке ==\n";
    $conn->query("INSERT INTO llm_debug_log (created_at, kind, provider, model, status, duration_ms, request)
                  VALUES (NOW() - INTERVAL 8 DAY, 'chat', 'ItOld', '', 'ok', 1, '$marker-old')");
    $oldId = (int)$conn->insert_id;
    LlmDebugLog::log('chat', 'ItTest', '', 'триггер чистки ' . $marker, null, 'ok', 1);
    $gone = $conn->query("SELECT id FROM llm_debug_log WHERE id = $oldId")->num_rows === 0;
    check($gone, 'запись старше 7 дней удалена');

    echo "== выключатель ai_debug_log=0 ==\n";
    $cfg->setOption('ai_debug_log', '0');
    LlmDebugLog::log('chat', 'ItTest', '', $marker . '-off', null, 'ok', 1);
    $none = $conn->query("SELECT id FROM llm_debug_log WHERE request LIKE '%$marker-off%'")->num_rows === 0;
    check($none, 'при выключенном журнале записи нет');
    $cfg->setOption('ai_debug_log', '1');

    echo "== ImageGenerator::lastError без сети (MLP-297) ==\n";
    $url = ImageGenerator::generate('it-prompt: pony');
    check($url === null, 'без ключа генерация не идёт');
    check(ImageGenerator::lastError() !== null && str_contains((string)ImageGenerator::lastError(), 'не настроен'), 'причина сбоя сохранена для извинения');
} finally {
    foreach ($optBackup as $k => $v) {
        if ($v === null) $conn->query("DELETE FROM site_options WHERE key_name = '" . $conn->real_escape_string($k) . "'");
        else \Infra\ConfigManager::getInstance()->setOption($k, $v);
    }
    $conn->query("DELETE FROM llm_debug_log WHERE provider IN ('ItTest', 'ItOld')");
}

it_done();
