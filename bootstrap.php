<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** Fresh-install entry point. Existing installations use migrate.php. */
require_once __DIR__ . '/migrate.php';

function bootstrap_manifest(): array {
    return require __DIR__ . '/database/bootstrap-manifest.php';
}

function bootstrap_verify_files(array $manifest): void {
    foreach ($manifest['files'] as $file => $expected) {
        if (!hash_equals($expected, hash_file('sha256', __DIR__ . '/' . $file))) {
            throw new RuntimeException('Bootstrap file differs from manifest: ' . $file);
        }
    }
}

/** Structural contract excludes bootstrap metadata and physical column order. */
function bootstrap_schema_hash(mysqli $db): string {
    $schema = $db->real_escape_string($db->query('SELECT DATABASE()')->fetch_row()[0]);
    $exclude = "TABLE_NAME NOT IN ('schema_migrations','schema_bootstrap')";
    $queries = [
        "SELECT TABLE_NAME,ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA='$schema' AND $exclude ORDER BY TABLE_NAME",
        "SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$schema' AND $exclude ORDER BY TABLE_NAME,COLUMN_NAME",
        "SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE,EXPRESSION FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='$schema' AND $exclude ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX",
        "SELECT t.TABLE_NAME,t.CONSTRAINT_NAME,c.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS t JOIN information_schema.CHECK_CONSTRAINTS c ON c.CONSTRAINT_SCHEMA=t.CONSTRAINT_SCHEMA AND c.CONSTRAINT_NAME=t.CONSTRAINT_NAME WHERE t.TABLE_SCHEMA='$schema' ORDER BY t.TABLE_NAME,t.CONSTRAINT_NAME",
        "SELECT TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA='$schema' AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION",
        "SELECT TABLE_NAME,CONSTRAINT_NAME,UPDATE_RULE,DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='$schema' ORDER BY TABLE_NAME,CONSTRAINT_NAME",
    ];
    $structure = [];
    foreach ($queries as $sql) {
        $structure[] = $db->query($sql)->fetch_all(MYSQLI_ASSOC);
    }
    return hash('sha256', json_encode($structure, JSON_THROW_ON_ERROR));
}

/** Pin required defaults independently of optional demo rows and runtime timestamps. */
function bootstrap_seed_hash(mysqli $db): string {
    $seed = [];
    $queries = [
        "SELECT key_name,IF(key_name='playlist_rollout_watermark','<timestamp>',value) AS value FROM site_options ORDER BY key_name",
        'SELECT command_prefix,description,handler_type,system_prompt,is_active FROM bot_commands ORDER BY command_prefix',
        'SELECT parent_id,title,url,sort_order,is_active,visibility,is_external,show_in_header,show_in_burger FROM menu_items ORDER BY sort_order,url',
        'SELECT id,login,nickname,password_hash,role,roles_json FROM users WHERE id=2',
    ];
    foreach ($queries as $sql) {
        $seed[] = $db->query($sql)->fetch_all(MYSQLI_ASSOC);
    }
    return hash('sha256', json_encode($seed, JSON_THROW_ON_ERROR));
}

function bootstrap_verify_install(mysqli $db, array $manifest): void {
    if (!hash_equals($manifest['schema_sha256'], bootstrap_schema_hash($db))) {
        throw new RuntimeException('Bootstrap schema contract mismatch; migration journal was not registered');
    }
    if (!hash_equals($manifest['seed_sha256'], bootstrap_seed_hash($db))) {
        throw new RuntimeException('Bootstrap seed contract mismatch; migration journal was not registered');
    }
}

/** A durable ownership marker precedes DDL, which MySQL cannot roll back. */
function bootstrap_claim(mysqli $db, array $manifest, bool $demo): void {
    $db->query("CREATE TABLE schema_bootstrap (
        id TINYINT NOT NULL PRIMARY KEY,
        snapshot VARCHAR(64) NOT NULL,
        state VARCHAR(16) NOT NULL,
        demo TINYINT NOT NULL,
        completed_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
    $stmt = $db->prepare("INSERT INTO schema_bootstrap(id,snapshot,state,demo) VALUES(1,?,'building',?)");
    $stmt->bind_param('si', $manifest['id'], $demo);
    $stmt->execute();
}

function bootstrap_install(mysqli $db, bool $ifEmpty = false, bool $demo = false): string {
    $manifest = bootstrap_manifest();
    bootstrap_verify_files($manifest);
    $database = $db->query('SELECT DATABASE()')->fetch_row()[0];
    $lockName = 'mlp-bootstrap:' . substr(hash('sha256', $database), 0, 48);
    $lock = $db->prepare('SELECT GET_LOCK(?,30)');
    $lock->bind_param('s', $lockName);
    $lock->execute();
    if ((int)$lock->get_result()->fetch_row()[0] !== 1) {
        throw new RuntimeException('Another bootstrap is running');
    }
    try {
        $tables = array_column($db->query('SHOW FULL TABLES')->fetch_all(MYSQLI_NUM), 0);
        if (in_array('schema_bootstrap', $tables, true)) {
            $state = $db->query('SELECT snapshot,state FROM schema_bootstrap WHERE id=1')->fetch_assoc();
            if (!$state || $state['state'] !== 'complete') {
                throw new RuntimeException('Detected incomplete bootstrap. Inspect the isolated installation before retrying; no migrations were baselined.');
            }
            if (migration_pending($manifest['migrations'], migrate_applied($db))) {
                throw new RuntimeException('Bootstrap migration journal is incomplete');
            }
            return 'existing';
        }
        if ($tables) {
            if ($ifEmpty) return 'existing';
            throw new RuntimeException('Refusing bootstrap over an existing database; use migrate.php for upgrades');
        }
        bootstrap_claim($db, $manifest, $demo);
        $db->set_charset('utf8mb4');
        migrate_apply_file($db, __DIR__ . '/database.sample.sql');
        migrate_ensure_table($db);
        $db->begin_transaction();
        try {
            migrate_apply_file($db, __DIR__ . '/database.seed.sql');
            if ($demo) migrate_apply_file($db, __DIR__ . '/database.demo.sql');
            bootstrap_verify_install($db, $manifest);
            foreach ($manifest['migrations'] as $filename) {
                migrate_record($db, $filename);
            }
            $db->query("UPDATE schema_bootstrap SET state='complete',completed_at=UTC_TIMESTAMP() WHERE id=1");
            $db->commit();
        } catch (Throwable $error) {
            $db->rollback();
            throw $error;
        }
        return 'installed';
    } finally {
        $release = $db->prepare('SELECT RELEASE_LOCK(?)');
        $release->bind_param('s', $lockName);
        $release->execute();
    }
}

function bootstrap_main(array $arguments): void {
    $waitSeconds = 0;
    foreach ($arguments as $argument) {
        if (preg_match('/^--wait=(\d{1,3})$/D', $argument, $match)) {
            $waitSeconds = min(300, (int)$match[1]);
        } elseif (!in_array($argument, ['--demo', '--if-empty'], true)) {
            throw new InvalidArgumentException('Unknown bootstrap option: ' . $argument);
        }
    }
    $deadline = time() + $waitSeconds;
    do {
        try {
            $db = migrate_connect();
            break;
        } catch (mysqli_sql_exception $error) {
            if (time() >= $deadline) throw new RuntimeException('Database unavailable for bootstrap');
            sleep(1);
        }
    } while (true);
    try {
        $result = bootstrap_install($db, in_array('--if-empty', $arguments, true), in_array('--demo', $arguments, true));
        echo "Bootstrap: $result\n";
    } finally {
        $db->close();
    }
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    try {
        bootstrap_main(array_slice($argv, 1));
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . "\n");
        exit(1);
    }
}
