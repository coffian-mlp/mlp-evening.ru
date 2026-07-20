<?php
use Infra\Database;
/**
 * Общие помощники интеграционных тестов (MLP-247).
 *
 * Интеграционный тест работает с реальной БД из config.php (Docker-контур:
 * `docker compose up -d db php`, запуск внутри контейнера). Если БД недоступна
 * (например, запуск на хосте, где host 'db' не резолвится) — тест обязан
 * напечатать "SKIP: ..." и выйти с кодом 0; раннер классифицирует это как SKIP.
 *
 * Протокол вердикта (как в tests/test_*.php): [OK]/[FAIL] по шагам,
 * итог — "ALL PASS" (exit 0) либо "FAIL: N" (exit 1).
 */

require_once __DIR__ . '/../autoload.php'; // MLP-248: классы — автозагрузкой

function it_config(): ?array {
    $path = __DIR__ . '/../config.php';
    if (!file_exists($path)) {
        return null;
    }
    return require $path;
}

/**
 * Соединение с БД из config.php или null.
 * $waitSec > 0 — ждать готовности (MySQL в контейнере может ещё подниматься).
 * По умолчанию ожидания нет: на хосте, где 'db' не резолвится, SKIP должен
 * быть мгновенным. Для первого старта контейнера: IT_DB_WAIT=60.
 */
function it_db(int $waitSec = 0): ?mysqli {
    $cfg = it_config();
    if (!$cfg || empty($cfg['db'])) {
        return null;
    }
    $d = $cfg['db'];
    $deadline = time() + $waitSec;
    // На время probe глушим strict-режим (иначе неудачный connect бросает
    // исключение), после — возвращаем дефолт PHP 8.1+, как на бою.
    mysqli_report(MYSQLI_REPORT_OFF);
    try {
        do {
            $conn = @mysqli_connect($d['host'], $d['user'], $d['pass'], $d['name']);
            if ($conn) {
                $conn->set_charset($d['charset'] ?? 'utf8mb4');
                return $conn;
            }
            if (time() >= $deadline) {
                return null;
            }
            sleep(2);
        } while (true);
    } finally {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    }
}

function it_skip(string $reason): void {
    echo "SKIP: $reason\n";
    exit(0);
}

/**
 * Guard от запуска на боевой БД (BLOCKING из ревью MLP-247).
 * Тесты пишут в БД и триггерят broadcast в чат — на проде это недопустимо
 * (PRD: «боевая БД не участвует никогда»). Интеграционный контур опознаётся
 * по host 'db' (docker-compose); всё остальное требует явного IT_ALLOW_DB=1.
 * Возвращает причину отказа или null, если контур тестовый.
 */
function it_env_guard(): ?string {
    if (getenv('IT_ALLOW_DB') === '1') {
        return null;
    }
    $cfg = it_config();
    $host = $cfg['db']['host'] ?? '';
    if ($host !== 'db') {
        return "config.php указывает на host '{$host}' — не Docker-контур; интеграционные тесты пишут в БД"
            . ' и запрещены вне тестовой среды (обход: IT_ALLOW_DB=1, только если уверен)';
    }
    return null;
}

/** Доступная БД или SKIP (проверять ДО Database::getInstance() — тот делает die()). */
function it_require_db(): mysqli {
    if (($reason = it_env_guard()) !== null) {
        it_skip($reason);
    }
    $conn = it_db((int)getenv('IT_DB_WAIT'));
    if (!$conn) {
        it_skip('БД из config.php недоступна — нужен Docker-контур (docker compose up -d db php)');
    }
    return $conn;
}

$GLOBALS['it_fail'] = 0;

function check($cond, string $label): void {
    if ($cond) {
        echo "  [OK] $label\n";
    } else {
        echo "  [FAIL] $label\n";
        $GLOBALS['it_fail']++;
    }
}

function it_done(): void {
    if ($GLOBALS['it_fail'] > 0) {
        echo "FAIL: {$GLOBALS['it_fail']}\n";
        exit(1);
    }
    echo "ALL PASS\n";
    exit(0);
}
