<?php
require_once __DIR__.'/integration_helpers.php';it_require_db()->close();require_once dirname(__DIR__).'/bootstrap.php';
$admin=new mysqli('db','root',Infra\Env::get('DB_ROOT_PASS'));$name='it_archive_migration_'.bin2hex(random_bytes(5));$expected=null;
$archive='2026_11_08_memory_archive.sql';$root=dirname(__DIR__);
preg_match('/CREATE TABLE `bot_memory` \(.*?\) ENGINE=[^;]+;/s',file_get_contents($root.'/database.sample.sql'),$match);
$oldSchema=$argv[1]??null;
$legacy=$oldSchema?file_get_contents($oldSchema):preg_replace('/^\s*`is_archived`[^\r\n]*\R|^\s*KEY `idx_memory_active`[^\r\n]*\R/m','',$match[0]);
$legacy=preg_replace('/,\s*\) ENGINE/',') ENGINE',$legacy);
try{
 foreach(['STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION','']as$mode)foreach([false,true]as$populated){
  $admin->query('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');$db=new mysqli('db','root',Infra\Env::get('DB_ROOT_PASS'),$name);$db->set_charset('utf8mb4');$db->query("SET SESSION sql_mode='$mode'");
  check(bootstrap_install($db,false)==='installed','updated B00 installs cleanly');
  if($populated){
   migrate_apply_file($db,$root.'/migrations/2026_11_07_semantic_memory.sql');migrate_record($db,'2026_11_07_semantic_memory.sql');
   $db->query('DROP TABLE bot_memory');migrate_apply_file($db,$oldSchema?:'data://text/plain,'.rawurlencode($legacy));
   check($db->query("SHOW COLUMNS FROM bot_memory LIKE 'is_archived'")->num_rows===0,'production-shaped fixture starts without archive column');
   foreach(['dossier','meme']as$kind)foreach(['manual','auto','oc']as$source){$s=$db->prepare("INSERT INTO bot_memory(kind,user_id,text,source,created_by,created_at,updated_at) VALUES(?,100,?,?,101,'2026-01-02 03:04:05','2026-02-03 04:05:06')");$text='sentinel_'.$kind.'_'.$source;$s->bind_param('sss',$kind,$text,$source);$s->execute();}
   $db->query("INSERT INTO chat_messages(user_id,username,message) VALUES(100,'Sentinel','History preserved')");
  }
  $columns='id,kind,user_id,text,source,created_by,created_at,updated_at';$before=$db->query('SELECT '.$columns.' FROM bot_memory ORDER BY id')->fetch_all(MYSQLI_ASSOC);$chat=$db->query('SELECT * FROM chat_messages ORDER BY id')->fetch_all(MYSQLI_ASSOC);$journal=$db->query('SELECT * FROM schema_migrations ORDER BY id')->fetch_all(MYSQLI_ASSOC);
  migrate_database($db,$root.'/migrations');migrate_database($db,$root.'/migrations');
  check($before===$db->query('SELECT '.$columns.' FROM bot_memory ORDER BY id')->fetch_all(MYSQLI_ASSOC),'additive migration preserves every old value and timestamp');
  check($chat===$db->query('SELECT * FROM chat_messages ORDER BY id')->fetch_all(MYSQLI_ASSOC),'migration preserves chat rows');
  check((int)$db->query('SELECT COUNT(*) FROM bot_memory WHERE is_archived<>0')->fetch_row()[0]===0,'existing rows default to active');
  check($db->query("SHOW INDEX FROM bot_memory WHERE Key_name='idx_memory_active'")->num_rows===3,'active-reader index exists');
  check(count(migrate_applied($db))===45,'fresh and populated migrate.php reach 45 applied files');
  check($journal===array_slice($db->query('SELECT * FROM schema_migrations ORDER BY id')->fetch_all(MYSQLI_ASSOC),0,count($journal)),'existing journal IDs and timestamps are preserved');
  $hash=bootstrap_schema_hash($db);if($expected===null)$expected=$hash;check($expected===$hash,'fresh and production-shaped populated schemas converge in both modes');
  migrate_apply_file($db,$root.'/migrations/'.$archive);check($hash===bootstrap_schema_hash($db),'archive SQL itself is idempotent');
  $db->close();$admin->query('DROP DATABASE `'.$name.'`');
 }
}finally{$admin->query('DROP DATABASE IF EXISTS `'.$name.'`');$admin->close();}
it_done();
