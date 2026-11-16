<?php
require_once __DIR__.'/integration_helpers.php';it_require_db()->close();
require_once dirname(__DIR__).'/bootstrap.php';
$admin=new mysqli('db','root',Infra\Env::get('DB_ROOT_PASS'));$name='it_recurrence_'.bin2hex(random_bytes(6));$root=dirname(__DIR__);
preg_match('/CREATE TABLE `events` \(.*?\) ENGINE=[^;]+;/s',file_get_contents($root.'/database.sample.sql'),$match);
$old=preg_replace('/^\s*`recurrence_(until|skip_dates)`[^\r\n]*\R/m','',$match[0]);
$now=strtotime('2030-01-10 UTC');
try {
    foreach(['STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION',''] as $mode) foreach([false,true] as $populated) {
        $admin->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
        $db=new mysqli('db','root',Infra\Env::get('DB_ROOT_PASS'),$name);$db->set_charset('utf8mb4');$db->query("SET SESSION sql_mode='$mode'");
        migrate_apply_file($db,'data://text/plain,'.rawurlencode($old));
        if($populated) $db->query("INSERT INTO events(id,title,description,start_time,duration_minutes,is_recurring,recurrence_rule,use_playlist,color,created_at,updated_at) VALUES(471,'Sentinel','History','2030-01-10 22:30:00',90,1,'weekly',1,'#123456','2026-01-01 01:02:03','2026-01-02 04:05:06')");
        $before=$db->query('SELECT * FROM events ORDER BY id')->fetch_all(MYSQLI_ASSOC);
        $expected=Domain\EventManager::expandOccurrences($before,30,$now);
        migrate_apply_file($db,$root.'/migrations/2026_11_16_event_recurrence_limits.sql');
        $after=$db->query('SELECT * FROM events ORDER BY id')->fetch_all(MYSQLI_ASSOC);
        $original=array_map(static fn($r)=>array_diff_key($r,array_flip(['recurrence_until','recurrence_skip_dates'])),$after);
        check($before===$original,'additive migration preserves every original value and timestamp');
        check($db->query('SELECT id FROM events WHERE recurrence_until IS NOT NULL OR recurrence_skip_dates IS NOT NULL')->num_rows===0,'all legacy restrictions default to NULL');
        check(array_column($expected,'run_id')===array_column(Domain\EventManager::expandOccurrences($after,30,$now),'run_id'),'NULL migration retains actual old recurrence expansion');
        $hash=bootstrap_schema_hash($db);migrate_apply_file($db,$root.'/migrations/2026_11_16_event_recurrence_limits.sql');
        check($hash===bootstrap_schema_hash($db) && $after===$db->query('SELECT * FROM events ORDER BY id')->fetch_all(MYSQLI_ASSOC),'repeated SQL is idempotent including existing rows');
        $db->close();$admin->query("DROP DATABASE `$name`");
    }
    $admin->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
    $db=new mysqli('db','root',Infra\Env::get('DB_ROOT_PASS'),$name);$db->set_charset('utf8mb4');
    check(bootstrap_install($db)==='installed','updated snapshot boots a completely clean database');
    check(bootstrap_schema_hash($db)===bootstrap_manifest()['schema_sha256'],'snapshot structure matches updated manifest');
    migrate_database($db,$root.'/migrations');migrate_database($db,$root.'/migrations');
    check(migration_pending(migrate_all_files($root.'/migrations'),migrate_applied($db))===[],'fresh installation plus normal migration has zero pending');
    $db->close();
} finally {$admin->query("DROP DATABASE IF EXISTS `$name`");$admin->close();}
it_done();
