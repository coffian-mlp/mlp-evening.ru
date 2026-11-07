<?php
require_once __DIR__ . '/integration_helpers.php';
it_require_db()->close();
require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/fixtures/historical-seed.php';

$manifest = bootstrap_manifest();
$dml = historicalSeedDml($manifest['migrations']);
$tables = array_values(array_unique(array_column($dml, 'table')));
sort($tables);
check(count($manifest['migrations']) === 43 && count($dml) === 39, 'all 43 manifest files inspected, all 39 historical DML statements extracted');
check(historicalSeedStatements("-- ignore ;\nINSERT INTO t VALUES ('a;b', 'it''s'); /* skip ; */ UPDATE t SET x=1;") === ["INSERT INTO t VALUES ('a;b', 'it''s')", 'UPDATE t SET x=1'], 'SQL extractor preserves quoted semicolons and doubled quotes');
echo 'DML statements=' . count($dml) . ' tables=' . implode(',', $tables) . "\n";
// Defaults introduced by the original sample rather than by any historical migration.
$sampleOptions = ['stream_url','ai_bot_user_id','ai_enabled','ai_system_prompt','ai_aliases',
    'ai_main_is_vision','ai_vision_provider','ai_vision_model','ai_image_provider','ai_image_model',
    'ai_image_daily_limit','ai_image_chat_context','ai_image_llm_caption'];
$admin = new mysqli('db', 'root', Infra\Env::get('DB_ROOT_PASS'));
$schemas = [];
$connections = [];
try {
    foreach ([false, true] as $demo) {
        $pair = [];
        for ($index = 0; $index < 2; $index++) {
            $schema = 'it_seed_' . bin2hex(random_bytes(6));
            $admin->query("CREATE DATABASE `$schema` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
            $schemas[] = $schema;
            $db = new mysqli('db', 'root', Infra\Env::get('DB_ROOT_PASS'), $schema);
            $db->set_charset('utf8mb4');
            $db->query('SET timestamp=1700000000');
            $connections[] = $db;
            $pair[] = $db;
        }
        [$fresh, $historical] = $pair;
        bootstrap_install($fresh, false, $demo);
        migrate_apply_file($historical, dirname(__DIR__) . '/database.sample.sql');
        // Copy only pre-migration base data, leaving all historical seed targets empty.
        foreach (['users', 'episode_list'] as $table) {
            $historical->query("INSERT INTO `$table` SELECT * FROM `{$schemas[count($schemas)-2]}`.`$table`");
        }
        $historical->query('UPDATE users SET roles_json=NULL');
        $optionList = "'" . implode("','", $sampleOptions) . "'";
        $historical->query("INSERT INTO site_options SELECT * FROM `{$schemas[count($schemas)-2]}`.site_options WHERE key_name IN ($optionList)");
        foreach ($dml as $item) $historical->query($item['sql']);
        foreach ($tables as $table) {
            $actual = historicalSeedRows($fresh, $table);
            $expected = historicalSeedRows($historical, $table);
            check($actual === $expected, 'fresh matches independent historical DML: ' . $table . ' demo=' . (int)$demo);
            if ($actual !== $expected) {
                echo '  missing=' . json_encode(array_values(array_diff($expected, $actual)), JSON_UNESCAPED_UNICODE) . "\n";
                echo '  extra=' . json_encode(array_values(array_diff($actual, $expected)), JSON_UNESCAPED_UNICODE) . "\n";
            }
        }
        check((int)$historical->query('SELECT COUNT(*) FROM users WHERE roles_json IS NULL')->fetch_row()[0] === 0, 'historical role backfill exercised');
        check((int)$historical->query('SELECT COUNT(*) FROM users WHERE last_seen=UTC_TIMESTAMP()')->fetch_row()[0] === (int)$historical->query('SELECT COUNT(*) FROM users')->fetch_row()[0], 'one-time presence stamp exercised separately from fresh defaults');
        $commands = $historical->query('SELECT * FROM bot_commands WHERE is_active=1')->fetch_all(MYSQLI_ASSOC);
        foreach (['fresh', 'upgrade no-op'] as $phase) {
            $active = $fresh->query('SELECT * FROM bot_commands WHERE is_active=1')->fetch_all(MYSQLI_ASSOC);
            foreach ($commands as $command) {
                $matched = Domain\BotCommandManager::matchCommand($active, $command['command_prefix']);
                check($matched !== null && $matched['handler_type'] === $command['handler_type'], $phase . ' matches ' . $command['command_prefix'] . ' demo=' . (int)$demo);
            }
            migrate_database($fresh, dirname(__DIR__) . '/migrations');
        }
        // Applying historical DML to completed fresh seed must not introduce missing rows.
        $before = [];
        foreach ($tables as $table) $before[$table] = historicalSeedRows($fresh, $table);
        foreach ($dml as $item) $fresh->query($item['sql']);
        foreach ($tables as $table) check($before[$table] === historicalSeedRows($fresh, $table), 'historical replay preserves complete fresh seed: ' . $table);
        // Conditional upgrade DML has no input on a fresh installation. Exercise it with synthetic legacy inputs.
        foreach ($pair as $db) {
            $db->query("INSERT INTO site_options(key_name,value) VALUES
                ('machine_spirit_enabled','1'),('machine_spirit_owner_id','12'),('machine_spirit_cooldown','20'),
                ('machine_spirit_prompt','legacy'),('machine_spirit_user_login','legacy'),
                ('machine_spirit_commands','legacy'),('machine_spirit_last_refusal','legacy'),
                ('announcements_token','synthetic-token-for-seed')");
            $rules = $db->query("SELECT value FROM site_options WHERE key_name='ai_chat_rules'")->fetch_row()[0];
            $insert = $db->prepare("INSERT INTO bot_commands(command_prefix,description,handler_type,system_prompt,is_active) VALUES('/правила','legacy fixture','text',?,1)");
            $insert->bind_param('s', $rules);
            $insert->execute();
            $db->query("DELETE FROM site_options WHERE key_name='playlist_legacy_imported'");
            $db->query("INSERT INTO episode_list(ID,TITLE,WANNA_WATCH,LENGTH) VALUES(9000,'Legacy fixture',3,1)");
            foreach ($dml as $item) $db->query($item['sql']);
            $options = array_column($db->query('SELECT key_name,value FROM site_options')->fetch_all(MYSQLI_ASSOC), 'value', 'key_name');
            check(($options['stream_command_enabled'] ?? '') === '1' && ($options['stream_command_owner_id'] ?? '') === '12'
                && ($options['stream_command_cooldown'] ?? '') === '20' && ($options['ai_owner_user_id'] ?? '') === '12', 'conditional legacy option transfers exercised');
            check((int)$db->query("SELECT COUNT(*) FROM site_options WHERE key_name LIKE 'machine_spirit_%'")->fetch_row()[0] === 0, 'obsolete legacy options removed');
            check(($options['announcements_legacy_token_hash'] ?? '') === hash('sha256', 'synthetic-token-for-seed'), 'conditional announcement provenance exercised');
            check(str_contains($db->query("SELECT system_prompt FROM bot_commands WHERE command_prefix='/правила'")->fetch_row()[0], '{rules}'), 'guarded rules prompt update exercised');
            check($db->query("SELECT outcome_json FROM episode_wish_events WHERE operation_key='legacy:9000'")->fetch_row()[0] === '{"count": 3}', 'conditional wish backfill exercised');
        }
        foreach ($tables as $table) check(historicalSeedRows($fresh, $table) === historicalSeedRows($historical, $table), 'conditional historical DML matches: ' . $table);
    }
} finally {
    foreach ($connections as $db) $db->close();
    foreach ($schemas as $schema) $admin->query("DROP DATABASE `$schema`");
    $admin->close();
}
it_done();
