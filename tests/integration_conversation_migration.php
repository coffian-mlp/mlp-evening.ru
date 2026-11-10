<?php
require_once __DIR__.'/integration_helpers.php';it_require_db()->close();require_once dirname(__DIR__).'/bootstrap.php';
$admin=new mysqli('db','root',Infra\Env::get('DB_ROOT_PASS'));$name='it_conversation_'.bin2hex(random_bytes(5));$root=dirname(__DIR__);$expected=null;
try{
 foreach(['STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION','']as$mode)foreach([false,true]as$legacy){
  $admin->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");$db=new mysqli('db','root',Infra\Env::get('DB_ROOT_PASS'),$name);$db->set_charset('utf8mb4');$db->query("SET SESSION sql_mode='$mode'");bootstrap_install($db);
  if($legacy){$db->query('ALTER TABLE chat_messages DROP COLUMN conversation_revision');$db->query('DROP TABLE bot_conversation_summaries,bot_memory_arrivals');$db->query("ALTER TABLE llm_jobs MODIFY type ENUM('mention','greeting','dynamic_command','cron_spontaneous','machine_spirit','stream_command','memory_scribe') NOT NULL");}
  foreach(['mention','greeting','dynamic_command','cron_spontaneous','machine_spirit','stream_command','memory_scribe']as$type){$s=$db->prepare("INSERT INTO llm_jobs(type,payload,run_after,created_at) VALUES(?,JSON_OBJECT('synthetic',1),UTC_TIMESTAMP(),UTC_TIMESTAMP())");$s->bind_param('s',$type);$s->execute();}
  $before=$db->query('SELECT * FROM llm_jobs ORDER BY id')->fetch_all(MYSQLI_ASSOC);
  $db->query("INSERT INTO chat_messages(user_id,username,message,created_at,edited_at,deleted_at,is_deleted) VALUES(2,'Synthetic','Синтетический старый источник','2026-11-01 10:00:00','2026-11-01 10:01:00','2026-11-01 10:02:00',1)");$source=$db->query('SELECT user_id,username,message,created_at,edited_at,deleted_at,is_deleted FROM chat_messages')->fetch_all(MYSQLI_ASSOC);
  migrate_database($db,$root.'/migrations');
  check((int)$db->query('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()')->fetch_row()[0]===0,'fresh and upgrade install no triggers');
  check((int)$db->query("SELECT COUNT(*) FROM site_options WHERE key_name='ai_conversation_epoch'")->fetch_row()[0]===0,'fresh and upgrade install no global counter');
  check($source===$db->query('SELECT user_id,username,message,created_at,edited_at,deleted_at,is_deleted FROM chat_messages')->fetch_all(MYSQLI_ASSOC),'monotonic provenance upgrade preserves existing timestamps and source data');
  check((int)$db->query('SELECT conversation_revision FROM chat_messages LIMIT 1')->fetch_row()[0]===0,'existing source starts with application revision zero');
  $hash=bootstrap_schema_hash($db);if($expected===null)$expected=$hash;
  check($hash===$expected,'fresh and legacy converge across SQL modes');
  check($before===$db->query('SELECT * FROM llm_jobs ORDER BY id')->fetch_all(MYSQLI_ASSOC),'all seven job types and values preserved');
  $flags=$db->query("SELECT key_name,value FROM site_options WHERE key_name IN ('ai_evening_summary_enabled','ai_return_memory_enabled','ai_return_memory_days') ORDER BY key_name")->fetch_all(MYSQLI_ASSOC);
  check(array_column($flags,'value')===['0','14','0'],'new flags disabled and threshold fourteen days');
  migrate_apply_file($db,$root.'/migrations/2026_11_08_conversation_memory.sql');check($hash===bootstrap_schema_hash($db),'conversation migration idempotent');
  $db->query("ALTER TABLE llm_jobs MODIFY type ENUM('mention','greeting','dynamic_command','cron_spontaneous','machine_spirit','stream_command','memory_scribe','evening_summary','legacy_unknown') NOT NULL");$db->query("INSERT INTO llm_jobs(type,payload,run_after,created_at) VALUES('legacy_unknown','{}',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
  $caught=false;try{migrate_apply_file($db,$root.'/migrations/2026_11_08_conversation_memory.sql');}catch(Throwable $e){$caught=true;}
  check($caught,'unexpected existing type stops migration even with empty sql_mode');
  check((int)$db->query("SELECT COUNT(*) FROM llm_jobs WHERE type='legacy_unknown'")->fetch_row()[0]===1,'unknown row not silently coerced');
  $db->close();$admin->query("DROP DATABASE `$name`");
 }
}finally{$admin->query("DROP DATABASE IF EXISTS `$name`");$admin->close();}
it_done();
