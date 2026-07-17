<?php
/**
 * Интеграционный смоук bootstrap'а (MLP-247, T-03, AC-3).
 *
 * Проверяет, что init.php подключается в CLI без fatal error и ядро отвечает:
 * классы загружены, ConfigManager читает site_options из реальной БД.
 * Это страховка фаз 2–3 v4.8.0: автозагрузчик и перекладка src/ обязаны
 * оставить этот тест зелёным (включая регистр имён файлов на Linux-FS).
 *
 * Запуск: docker compose exec php php tests/integration_bootstrap.php
 */

require_once __DIR__ . '/integration_helpers.php';

$probe = it_require_db();
$probe->close();

// CLI: DOCUMENT_ROOT пуст — подставляем корень проекта (как это делает веб-сервер).
$_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__ . '/..');

require __DIR__ . '/../init.php';

// init.php открывает ob_start() для инъекции ассетов — в CLI-тесте закрываем
// сразу, иначе вывод check() уйдёт в буфер и потеряется.
while (ob_get_level() > 0) {
    ob_end_clean();
}

check(class_exists('Core\\Application'), 'Core\\Application загружен');
check(class_exists('Core\\Component'), 'Core\\Component загружен');
check(class_exists('Database'), 'Database загружен');
check(class_exists('ConfigManager'), 'ConfigManager загружен');
check(class_exists('Auth'), 'Auth загружен');
check(class_exists('UserManager'), 'UserManager загружен');
check(class_exists('EpisodeManager'), 'EpisodeManager загружен');
check(class_exists('StickerManager'), 'StickerManager загружен');
check(class_exists('CentrifugoService'), 'CentrifugoService загружен');

global $app;
check($app instanceof \Core\Application, '$app — экземпляр Core\\Application');

// Auth::check() уже вызван в init.php; в CLI не должно быть авторизации.
check(Auth::check() === false, 'Auth::check() в CLI — false (без сессии пользователя)');

// ConfigManager ходит в реальную БД: stream_url засеян в database.sample.sql.
$stream = ConfigManager::getInstance()->getOption('stream_url');
check(is_string($stream) && $stream !== '', "ConfigManager::getOption('stream_url') читает БД (= " . var_export($stream, true) . ")");

it_done();
