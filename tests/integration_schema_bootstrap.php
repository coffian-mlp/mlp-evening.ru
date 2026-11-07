<?php
require_once __DIR__ . '/integration_helpers.php';
it_require_db()->close();
require_once dirname(__DIR__) . '/bootstrap.php';

$admin = new mysqli('db', 'root', Infra\Env::get('DB_ROOT_PASS'));
$schema = 'it_bootstrap_' . bin2hex(random_bytes(6));
$admin->query("CREATE DATABASE `$schema` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
$db = new mysqli('db', 'root', Infra\Env::get('DB_ROOT_PASS'), $schema);
$db->set_charset('utf8mb4');
try {
    foreach (['STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION', ''] as $mode) {
        $db->query("SET SESSION sql_mode='$mode'");
        check(bootstrap_install($db, false) === 'installed', 'empty database bootstrap succeeds');
        check(bootstrap_schema_hash($db) === bootstrap_manifest()['schema_sha256'], 'full schema contract matches');
        check(count(migrate_applied($db)) === 43, 'only verified snapshot migrations recorded');
        check(migration_pending(migrate_all_files(dirname(__DIR__) . '/migrations'), migrate_applied($db)) === array_values(array_diff(migrate_all_files(dirname(__DIR__) . '/migrations'), bootstrap_manifest()['migrations'])), 'future migrations remain pending after frozen bootstrap');
        $db->query('ALTER TABLE users MODIFY reset_token_hash VARCHAR(255) NULL');
        try {
            bootstrap_verify_install($db, bootstrap_manifest());
            check(false, 'wrong column contract rejected');
        } catch (RuntimeException $error) {
            check(str_contains($error->getMessage(), 'schema contract'), 'wrong column contract rejected');
        }
        $db->query('ALTER TABLE users MODIFY reset_token_hash VARCHAR(64) NULL');
        $commands = $db->query('SELECT * FROM bot_commands')->fetch_all(MYSQLI_ASSOC);
        check(LLM\PlaylistCommand::matchActive($commands, '!хочу серию') !== null, 'UTF-8 playlist command matches');
        check(Domain\BotCommandManager::matchCommand($commands, '/перерыв')['handler_type'] === 'attendance', 'attendance command seeded');
        check((int)$db->query('SELECT COUNT(*) FROM episode_list')->fetch_row()[0] === 0, 'base install excludes demo catalogue');
        check((int)$db->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetch_row()[0] === 0, 'base install excludes demo admin');
        $before = $db->query('SELECT * FROM schema_migrations ORDER BY id')->fetch_all(MYSQLI_ASSOC);
        $db->query("UPDATE site_options SET value='custom sentinel' WHERE key_name='ai_chat_rules'");
        try {
            bootstrap_verify_install($db, bootstrap_manifest());
            check(false, 'seed drift rejected by completion validator');
        } catch (RuntimeException $error) {
            check(str_contains($error->getMessage(), 'seed contract'), 'seed drift rejected by completion validator');
        }
        check(bootstrap_install($db, false) === 'existing', 'repeat is a no-op');
        check($before === $db->query('SELECT * FROM schema_migrations ORDER BY id')->fetch_all(MYSQLI_ASSOC), 'repeat preserves migration ids and timestamps');
        check($db->query("SELECT value FROM site_options WHERE key_name='ai_chat_rules'")->fetch_row()[0] === 'custom sentinel', 'repeat preserves edited settings');
        $db->query('DELETE FROM schema_migrations ORDER BY id LIMIT 1');
        try {
            bootstrap_install($db);
            check(false, 'incomplete journal is not silently repaired');
        } catch (RuntimeException $error) {
            check(str_contains($error->getMessage(), 'journal is incomplete'), 'incomplete journal diagnosed');
        }
        check(count(migrate_applied($db)) === 42, 'incomplete journal remains unchanged');
        $db->close();
        $admin->query("DROP DATABASE `$schema`");
        $admin->query("CREATE DATABASE `$schema` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
        $db = new mysqli('db', 'root', Infra\Env::get('DB_ROOT_PASS'), $schema);
        $db->set_charset('utf8mb4');
    }
    $db->query('CREATE TABLE sentinel (id INT PRIMARY KEY, value VARCHAR(20))');
    $db->query("INSERT INTO sentinel VALUES (1,'keep')");
    try {
        bootstrap_install($db, false);
        check(false, 'existing untracked schema refused');
    } catch (RuntimeException $error) {
        check(str_contains($error->getMessage(), 'existing database'), 'existing untracked schema refused');
    }
    check(bootstrap_install($db, true) === 'existing', 'Docker if-empty leaves an existing database alone');
    check($db->query('SELECT value FROM sentinel')->fetch_row()[0] === 'keep', 'sentinel unchanged');
    check(!$db->query("SHOW TABLES LIKE 'schema_migrations'")->num_rows, 'refusal never invents a journal');
    migrate_ensure_table($db);
    migrate_record($db, bootstrap_manifest()['migrations'][0]);
    try {
        bootstrap_install($db);
        check(false, 'untracked partial baseline refused');
    } catch (RuntimeException $error) {
        check(str_contains($error->getMessage(), 'existing database'), 'untracked partial baseline refused');
    }
    check(count(migrate_applied($db)) === 1, 'partial baseline not expanded');
    $db->query('DROP TABLE schema_migrations');
    $db->query('DROP TABLE sentinel');
    bootstrap_claim($db, bootstrap_manifest(), false);
    $db->query('CREATE TABLE sentinel (id INT PRIMARY KEY)');
    foreach ([false, true] as $ifEmpty) {
        try {
            bootstrap_install($db, $ifEmpty);
            check(false, 'partial bootstrap refused before registration');
        } catch (RuntimeException $error) {
            check(str_contains($error->getMessage(), 'incomplete bootstrap'), 'partial bootstrap diagnosed');
        }
    }
    foreach (['apply', '--baseline'] as $mode) {
        try {
            migrate_database($db, dirname(__DIR__) . '/migrations', $mode);
            check(false, 'migration runner rejects partial fresh install');
        } catch (RuntimeException $error) {
            check(str_contains($error->getMessage(), 'incomplete bootstrap'), 'migration runner rejects partial fresh install');
        }
    }
    check(!$db->query("SHOW TABLES LIKE 'schema_migrations'")->num_rows, 'partial bootstrap has no migration records');
} finally {
    $db->close();
    $admin->query("DROP DATABASE `$schema`");
    $admin->close();
}
it_done();
