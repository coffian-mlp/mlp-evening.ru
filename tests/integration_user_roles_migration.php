<?php
require_once __DIR__.'/integration_helpers.php';
if((it_config()['db']['host']??'')!=='db')it_skip('Role migration requires isolated Docker');
it_require_db();require_once dirname(__DIR__).'/migrate.php';
$schema='it_user_roles_'.bin2hex(random_bytes(6));$fail=0;
$check=static function($v,$m)use(&$fail){echo($v?'[OK] ':'[FAIL] ').$m."\n";if(!$v)$fail++;};
$admin=new mysqli('db','root',Infra\Env::get('DB_ROOT_PASS'));$admin->query('CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4');$scratch=null;
try{
    $scratch=new mysqli('db','root',Infra\Env::get('DB_ROOT_PASS'),$schema);$scratch->set_charset('utf8mb4');
    $scratch->query('CREATE TABLE users(id INT PRIMARY KEY,role VARCHAR(20) NOT NULL)');
    $scratch->query("INSERT INTO users VALUES(1,'user'),(2,'moderator'),(3,'admin'),(4,'unknown')");
    migrate_apply_file($scratch,dirname(__DIR__).'/migrations/2026_10_27_multiple_user_roles.sql');
    $rows=$scratch->query('SELECT * FROM users ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    $check(array_map(static fn($r)=>json_decode($r['roles_json'],true),$rows)===[['user'],['user','moderator'],['user','admin'],['user']],'exact migration retains known roles and establishes mandatory baseline');
    $check(array_column($rows,'role')===['user','moderator','admin','unknown'],'migration does not overwrite existing compatibility fields');
    foreach(['[]','{}','["admin"]','"user"'] as $bad){
        $s=$scratch->prepare('UPDATE users SET roles_json=? WHERE id=1');$s->bind_param('s',$bad);
        try{$s->execute();$check(false,'database rejects removal of baseline');}catch(mysqli_sql_exception $e){$check($e->getCode()===3819,'database rejects removal of baseline');}
    }
    $scratch->query("UPDATE users SET roles_json=JSON_ARRAY('user','moderator','streamer') WHERE id=1");
    $check(Domain\UserRoles::fromUser($scratch->query('SELECT * FROM users WHERE id=1')->fetch_assoc())===['user','moderator','streamer'],'combined memberships pass database constraint');
    $scratch->query("INSERT INTO users(id,role) VALUES(5,'admin')");
    $check(Domain\UserRoles::fromUser($scratch->query('SELECT * FROM users WHERE id=5')->fetch_assoc())===['user','admin'],'in-flight legacy inserts remain compatible');
}finally{if($scratch)$scratch->close();$admin->query('DROP DATABASE '.$schema);$admin->close();}
echo $fail?"FAIL: $fail\n":"ALL PASS\n";exit($fail?1:0);
