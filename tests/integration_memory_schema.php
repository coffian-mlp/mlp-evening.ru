<?php
require_once __DIR__.'/integration_helpers.php';
it_require_db()->close();
require_once dirname(__DIR__).'/bootstrap.php';
$admin=new mysqli('db','root',Infra\Env::get('DB_ROOT_PASS'));
$schema='it_memory_schema_'.bin2hex(random_bytes(6));
$manifest=bootstrap_manifest();
try{
 foreach(['STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION','']as$mode){
  $expected=null;
  foreach(['fresh','upgrade']as$scenario){
   $admin->query("CREATE DATABASE `$schema` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
   $db=new mysqli('db','root',Infra\Env::get('DB_ROOT_PASS'),$schema);$db->set_charset('utf8mb4');$db->query("SET SESSION sql_mode='$mode'");
   if($scenario==='fresh'){
    bootstrap_install($db);
    check(bootstrap_schema_hash($db)===$manifest['schema_sha256'],'frozen baseline remains verifiable');
   }else{
    migrate_apply_file($db,dirname(__DIR__).'/database.sample.sql');migrate_apply_file($db,dirname(__DIR__).'/database.seed.sql');migrate_ensure_table($db);
    foreach($manifest['migrations']as$file)migrate_record($db,$file);
   }
   $db->query("INSERT INTO chat_messages(user_id,username,message,created_at) VALUES(2,'Sentinel','keep source history',UTC_TIMESTAMP())");
   $before=$db->query('SELECT * FROM chat_messages')->fetch_all(MYSQLI_ASSOC);
   $journal=$db->query('SELECT * FROM schema_migrations ORDER BY id')->fetch_all(MYSQLI_ASSOC);
   migrate_database($db,dirname(__DIR__).'/migrations');
   check(migration_pending(migrate_all_files(dirname(__DIR__).'/migrations'),migrate_applied($db))===[],'all future migrations applied through normal upgrade');
   check(count(migrate_applied($db))===count($manifest['migrations'])+2,'semantic index and archive incremental migrations recorded');
   $hash=bootstrap_schema_hash($db);
   if($expected===null)$expected=$hash;
   check($expected===$hash,'fresh plus upgrade and existing upgrade have identical structure');
   check($db->query('SELECT * FROM chat_messages')->fetch_all(MYSQLI_ASSOC)===$before,'upgrade preserves source history');
   check(array_slice($db->query('SELECT * FROM schema_migrations ORDER BY id')->fetch_all(MYSQLI_ASSOC),0,count($journal))===$journal,'historical journal ids and timestamps preserved');
   $beforeRepeat=$db->query('SELECT * FROM schema_migrations ORDER BY id')->fetch_all(MYSQLI_ASSOC);
   migrate_database($db,dirname(__DIR__).'/migrations');
   check($db->query('SELECT * FROM schema_migrations ORDER BY id')->fetch_all(MYSQLI_ASSOC)===$beforeRepeat,'repeated upgrade preserves exact journal');
   check($db->query("SHOW TABLES LIKE 'memory_episode_sources'")->num_rows===1,'exact source membership schema installed');
   $db->close();$admin->query("DROP DATABASE `$schema`");
  }
 }
}finally{$admin->query("DROP DATABASE IF EXISTS `$schema`");$admin->close();}
it_done();
