<?php
/**
 * Интеграционный тест ConfigManager с реальной БД (MLP-247, T-04, AC-2).
 *
 * Сценарий: setOption → getOption → flushCache → перечитывание из БД → очистка.
 * Контракт: dev_knowledge/contracts/core.contract.md (get/setOption).
 *
 * Запуск: docker compose exec php php tests/integration_config_manager.php
 */

require_once __DIR__ . '/integration_helpers.php';

$conn = it_require_db();

require_once __DIR__ . '/../src/ConfigManager.php';

$key = 'it_opt_' . getmypid();
$cm  = ConfigManager::getInstance();

try {
    // Значения не существует — дефолт.
    check($cm->getOption($key, 'default') === 'default', 'getOption несуществующего ключа возвращает дефолт');

    // Запись и чтение.
    check($cm->setOption($key, 'магия дружбы') !== false, 'setOption записывает новую опцию');
    check($cm->getOption($key) === 'магия дружбы', 'getOption возвращает записанное (utf8mb4)');

    // Обновление существующей.
    $cm->setOption($key, 'v2');
    check($cm->getOption($key) === 'v2', 'setOption обновляет существующую опцию');

    // flushCache: меняем значение мимо request-кеша (напрямую в БД — имитация
    // другого процесса), без flush видим старое, после flush — свежее.
    $stmt = $conn->prepare('UPDATE site_options SET value = ? WHERE key_name = ?');
    $v3 = 'v3-из-другого-процесса';
    $stmt->bind_param('ss', $v3, $key);
    $stmt->execute();
    $stmt->close();

    check($cm->getOption($key) === 'v2', 'до flushCache виден request-кеш');
    $cm->flushCache();
    check($cm->getOption($key) === 'v3-из-другого-процесса', 'после flushCache значение перечитано из БД');
} finally {
    // Уборка даже при исключении посреди теста. У ConfigManager нет
    // deleteOption (контракт узкий) — фикстурная уборка своей строки напрямую.
    $stmt = $conn->prepare('DELETE FROM site_options WHERE key_name = ?');
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $stmt->close();
    $cm->flushCache();
}

check($cm->getOption($key, null) === null, 'после очистки опции нет');

// Чистота — только своя строка (по pid): чужие остатки — не вина этого теста.
$stmt = $conn->prepare('SELECT COUNT(*) AS n FROM site_options WHERE key_name = ?');
$stmt->bind_param('s', $key);
$stmt->execute();
check((int)$stmt->get_result()->fetch_assoc()['n'] === 0, 'своих тестовых строк в site_options не осталось');
$stmt->close();

$conn->close();
it_done();
