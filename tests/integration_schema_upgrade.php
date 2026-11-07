<?php
require_once __DIR__ . '/integration_helpers.php';
it_require_db()->close();
require_once dirname(__DIR__) . '/bootstrap.php';

// An optional schema-only production export exercises this same test without copying data.
$structureFile = $argv[1] ?? dirname(__DIR__) . '/database.sample.sql';
$admin = new mysqli('db', 'root', Infra\Env::get('DB_ROOT_PASS'));
$schema = 'it_upgrade_' . bin2hex(random_bytes(6));
$admin->query("CREATE DATABASE `$schema` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
$db = new mysqli('db', 'root', Infra\Env::get('DB_ROOT_PASS'), $schema);
$db->set_charset('utf8mb4');
$manifest = bootstrap_manifest();
$productionJournal = isset($argv[2])
    ? array_column(json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR)['migration_journal'], 'filename')
    : $manifest['migrations'];
check(count($productionJournal) === 43 && array_diff($manifest['migrations'], $productionJournal) === [], 'production journal matches 43 historical migrations');
try {
    foreach (['STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION', ''] as $mode) {
        foreach ([false, true] as $previousVersion) {
            $db->query("SET SESSION sql_mode='$mode'");
            $db->query('SET FOREIGN_KEY_CHECKS=0');
            try {
                migrate_apply_file($db, $structureFile);
            } finally {
                $db->query('SET FOREIGN_KEY_CHECKS=1');
            }
            migrate_ensure_table($db);
            migrate_apply_file($db, dirname(__DIR__) . '/database.seed.sql');
            $db->query("INSERT INTO users(id,login,nickname,password_hash,role,roles_json) VALUES
                (100,'sentinel_admin','Admin','!','admin',NULL),
                (101,'sentinel_mod','Moderator','!','moderator',NULL),
                (102,'sentinel_unknown','Legacy','!','unknown',NULL),
                (103,'sentinel_combined','Combined','!','user',JSON_ARRAY('user','moderator','streamer'))");
            $db->query("INSERT INTO chat_messages(id,user_id,username,message) VALUES(100,100,'Sentinel','История сохраняется')");
            $db->query("INSERT INTO episode_list(ID,TITLE,LENGTH) VALUES(100,'Sentinel episode',4)");
            $db->query("UPDATE site_options SET value='CUSTOM_RULES' WHERE key_name='ai_chat_rules'");
            $db->query("UPDATE bot_commands SET system_prompt='CUSTOM_PROMPT' WHERE command_prefix='/хочу'");
            $db->query("UPDATE menu_items SET title='Custom archive',sort_order=999 WHERE url='/archive.php'");
            $enum = $db->query("SHOW COLUMNS FROM bot_commands LIKE 'handler_type'")->fetch_assoc()['Type'];
            preg_match_all("/'([^']+)'/", $enum, $matches);
            foreach ($matches[1] as $index => $handler) {
                $stmt = $db->prepare("INSERT INTO bot_commands(command_prefix,description,handler_type,system_prompt,is_active) VALUES(?,'sentinel',?,'',0)");
                $prefix = '/sentinel_' . $index;
                $stmt->bind_param('ss', $prefix, $handler);
                $stmt->execute();
            }
            $types = $db->query("SHOW COLUMNS FROM llm_jobs LIKE 'type'")->fetch_assoc()['Type'];
            preg_match_all("/'([^']+)'/", $types, $matches);
            foreach ($matches[1] as $type) {
                $stmt = $db->prepare("INSERT INTO llm_jobs(type,payload,run_after,created_at) VALUES(?,JSON_OBJECT('sentinel',1),UTC_TIMESTAMP(),UTC_TIMESTAMP())");
                $stmt->bind_param('s', $type);
                $stmt->execute();
            }
            $applied = $productionJournal;
            if ($previousVersion) {
                // Previous supported fixture is the schema immediately before the two 27 October migrations.
                $db->query('ALTER TABLE users DROP CHECK user_roles_baseline, DROP COLUMN roles_json');
                $db->query('ALTER TABLE events DROP COLUMN monitor_stream, DROP COLUMN streamer_user_id');
                $db->query('ALTER TABLE command_interactions DROP INDEX interaction_system, DROP COLUMN origin, DROP COLUMN system_key, MODIFY source_message_id INT NOT NULL');
                foreach (['lyra_notification_links','stream_monitor_runs','stream_incidents','stream_monitor_deliveries'] as $table) {
                    $db->query("DROP TABLE `$table`");
                }
                $applied = array_values(array_diff($applied, ['2026_10_27_multiple_user_roles.sql', '2026_10_27_stream_monitor.sql']));
            }
            foreach ($applied as $filename) migrate_record($db, $filename);
            $journalBefore = $db->query('SELECT * FROM schema_migrations ORDER BY id')->fetch_all(MYSQLI_ASSOC);
            $sentinelQueries = [
                'SELECT id,login,nickname,password_hash,role FROM users ORDER BY id',
                'SELECT * FROM chat_messages ORDER BY id',
                'SELECT * FROM episode_list ORDER BY ID',
                'SELECT * FROM site_options ORDER BY key_name',
                'SELECT * FROM bot_commands ORDER BY id',
                'SELECT * FROM menu_items ORDER BY id',
                'SELECT * FROM llm_jobs ORDER BY id',
            ];
            $before = [];
            foreach ($sentinelQueries as $sql) $before[] = $db->query($sql)->fetch_all(MYSQLI_ASSOC);
            for ($attempt = 0; $attempt < 2; $attempt++) {
                migrate_database($db, dirname(__DIR__) . '/migrations');
                check(migration_pending($manifest['migrations'], migrate_applied($db)) === [], 'upgrade has no pending migrations');
                check(bootstrap_schema_hash($db) === $manifest['schema_sha256'], 'upgrade reaches the full fresh schema contract');
                $after = [];
                foreach ($sentinelQueries as $sql) $after[] = $db->query($sql)->fetch_all(MYSQLI_ASSOC);
                check($before === $after, 'sentinel history, accounts, catalogue, options, prompts, navigation and ENUM rows preserved');
                if (!$previousVersion) {
                    check($journalBefore === $db->query('SELECT * FROM schema_migrations ORDER BY id')->fetch_all(MYSQLI_ASSOC), '43-applied production fixture keeps journal unchanged');
                    check(json_decode($db->query('SELECT roles_json FROM users WHERE id=103')->fetch_row()[0], true) === ['user','moderator','streamer'], 'combined roles preserved');
                    check($db->query('SELECT roles_json FROM users WHERE id=100')->fetch_row()[0] === null, 'nullable legacy roles preserved on no-op');
                } else {
                    $roles = $db->query('SELECT id,roles_json FROM users WHERE id BETWEEN 100 AND 102 ORDER BY id')->fetch_all(MYSQLI_ASSOC);
                    check(array_map(static fn($row) => json_decode($row['roles_json'], true), $roles) === [['user','admin'],['user','moderator'],['user']], 'published role migration retains known legacy roles and unknown compatibility field');
                }
            }
            $db->close();
            $admin->query("DROP DATABASE `$schema`");
            $admin->query("CREATE DATABASE `$schema` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
            $db = new mysqli('db', 'root', Infra\Env::get('DB_ROOT_PASS'), $schema);
            $db->set_charset('utf8mb4');
        }
    }
} finally {
    $db->close();
    $admin->query("DROP DATABASE `$schema`");
    $admin->close();
}
it_done();
