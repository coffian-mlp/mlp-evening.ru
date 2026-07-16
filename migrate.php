<?php
/**
 * Простой раннер миграций БД (R7, MLP-233).
 *
 * Применяет непринятые *.sql из migrations/ по имени (лексикографический порядок)
 * и записывает применённое в таблицу schema_migrations. Idempotent: уже
 * применённые файлы пропускаются.
 *
 * Живёт в корне рядом с bot_worker.php/cron_llm.php (папка scripts/ в .gitignore
 * и на прод не попадает).
 *
 * Использование:
 *   php migrate.php            — применить все непринятые миграции
 *   php migrate.php --status   — показать применённые / ожидающие
 *   php migrate.php --baseline — пометить ВСЕ текущие файлы применёнными
 *                                (без выполнения) — для первого запуска на
 *                                проде, где схема уже накатана вручную.
 *
 * Порядок на проде (первый раз):
 *   git pull && php migrate.php --baseline
 * Далее при каждом деплое:
 *   git pull && php migrate.php
 */

/** Pure: какие базовые имена ещё не применены (отсортированы по имени). */
function migration_pending(array $allBasenames, array $appliedBasenames): array {
    $applied = array_flip($appliedBasenames);
    $pending = [];
    foreach ($allBasenames as $name) {
        if (!isset($applied[$name])) {
            $pending[] = $name;
        }
    }
    sort($pending);
    return $pending;
}

function migrate_connect() {
    $config = require __DIR__ . '/config.php';
    $c = $config['db'];
    $db = new mysqli($c['host'], $c['user'], $c['pass'], $c['name']);
    if ($db->connect_error) {
        fwrite(STDERR, "DB connect error: {$db->connect_error}\n");
        exit(1);
    }
    $db->set_charset($c['charset'] ?? 'utf8mb4');
    return $db;
}

function migrate_ensure_table($db): void {
    $db->query("CREATE TABLE IF NOT EXISTS `schema_migrations` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `filename` VARCHAR(255) NOT NULL,
        `applied_at` DATETIME NOT NULL,
        UNIQUE KEY `uniq_filename` (`filename`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function migrate_applied($db): array {
    $names = [];
    $res = $db->query("SELECT filename FROM schema_migrations");
    while ($res && $row = $res->fetch_assoc()) {
        $names[] = $row['filename'];
    }
    return $names;
}

function migrate_all_files(string $dir): array {
    $names = [];
    foreach (glob($dir . '/*.sql') as $path) {
        $names[] = basename($path);
    }
    sort($names);
    return $names;
}

function migrate_record($db, string $filename): void {
    $stmt = $db->prepare("INSERT INTO schema_migrations (filename, applied_at) VALUES (?, NOW())");
    $stmt->bind_param('s', $filename);
    $stmt->execute();
    $stmt->close();
}

/** Применить один SQL-файл (поддержка нескольких стейтментов). */
function migrate_apply_file($db, string $path): void {
    $sql = file_get_contents($path);
    if ($db->multi_query($sql)) {
        do {
            if ($res = $db->store_result()) {
                $res->free();
            }
        } while ($db->more_results() && $db->next_result());
    }
    if ($db->errno) {
        throw new \RuntimeException("SQL error in " . basename($path) . ": " . $db->error);
    }
}

function migrate_main(array $argv): void {
    $mode = $argv[1] ?? 'apply';
    $dir  = __DIR__ . '/migrations';

    $db = migrate_connect();
    migrate_ensure_table($db);

    $all     = migrate_all_files($dir);
    $applied = migrate_applied($db);
    $pending = migration_pending($all, $applied);

    if ($mode === '--status') {
        echo "Применено (" . count($applied) . "):\n";
        foreach ($applied as $f) echo "  ✓ $f\n";
        echo "Ожидают (" . count($pending) . "):\n";
        foreach ($pending as $f) echo "  … $f\n";
        return;
    }

    if ($mode === '--baseline') {
        foreach ($pending as $f) {
            migrate_record($db, $f);
            echo "  baseline: $f\n";
        }
        echo "Готово: " . count($pending) . " файл(ов) помечено применёнными (без выполнения).\n";
        return;
    }

    // apply
    if (!$pending) {
        echo "Нечего применять — всё актуально.\n";
        return;
    }
    foreach ($pending as $f) {
        echo "Применяю $f … ";
        try {
            migrate_apply_file($db, $dir . '/' . $f);
            migrate_record($db, $f);
            echo "ok\n";
        } catch (\Throwable $e) {
            echo "ОШИБКА\n";
            fwrite(STDERR, $e->getMessage() . "\n");
            exit(1);
        }
    }
    echo "Готово: " . count($pending) . " миграций применено.\n";
}

// Запускаем main только при прямом вызове из CLI (не при require из теста).
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    migrate_main($argv);
}
