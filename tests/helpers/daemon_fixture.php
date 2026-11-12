<?php
/** Synthetic active run; requires a dedicated Docker database. */
require dirname(__DIR__,2).'/autoload.php';
if(!is_file('/.dockerenv')||!is_file('/var/www/html/.mlp461-owned')||!in_array(Infra\Env::get('DB_NAME'),['mlp461','mlp_phase3'],true))throw new RuntimeException('Disposable daemon lab only');
$db=Infra\Database::getInstance()->getConnection();$cfg=Infra\ConfigManager::getInstance();$store=new Domain\StreamMonitorStore();
switch($argv[1]??''){
case 'setup':
    $users=new Domain\UserManager();$host=$users->createUser('it_daemon_host','synthetic_password','streamer','Synthetic host');
    foreach(['stream_source'=>'self_hosted','self_hosted_hls_url'=>'/live/evening/index.m3u8','stream_monitor_default_streamer'=>(string)$host,'announcements_enabled'=>'0','announcements_token'=>'123456:'.str_repeat('a',35),'ai_enabled'=>'0','ai_live_confirm'=>'0'] as $k=>$v)$cfg->setOption($k,$v);
    (new Domain\EventManager())->create(['title'=>'Synthetic daemon event','description'=>'','start_time'=>gmdate('Y-m-d H:i:s',time()-180),'duration_minutes'=>90,'is_recurring'=>0,'recurrence_rule'=>'','use_playlist'=>0,'generate_new_playlist'=>0,'color'=>'#123456','monitor_stream'=>1,'streamer_user_id'=>$host]);
    $event=(int)$db->insert_id;
    $seq=0;$monitor=new Domain\StreamMonitor($store,static function()use(&$seq){return ['state'=>'live','progress'=>'seed_'.++$seq];});
    for($t=time()-70;$t<=time();$t+=10)$monitor->tick($t);
    file_put_contents('/var/www/html/fixture-event-id',(string)$event);
    echo "fixture ready\n";break;
case 'state':
    $event=(int)file_get_contents('/var/www/html/fixture-event-id');
    echo json_encode(['runs'=>$store->rows('SELECT run_id,ended,armed,health,lost_at,incident_id,last_checked,progress_key FROM stream_monitor_runs WHERE event_id=?',[$event]),'incidents'=>(int)$db->query('SELECT COUNT(*) FROM stream_incidents')->fetch_row()[0],'deliveries'=>(int)$db->query('SELECT COUNT(*) FROM stream_monitor_deliveries')->fetch_row()[0],'lyra_heartbeat'=>$cfg->getOption('lyra_bot_heartbeat'),'monitor_heartbeat'=>$cfg->getOption('stream_monitor_heartbeat')],JSON_THROW_ON_ERROR)."\n";break;
case 'suite':
    $root=new mysqli('db','root',Infra\Env::get('DB_ROOT_PASS'));
    $root->query('CREATE DATABASE mlp_phase3 CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
    $root->query("GRANT ALL PRIVILEGES ON mlp_phase3.* TO 'mlp461'@'%'");
    $env='/var/www/html/.env';file_put_contents($env,str_replace('DB_NAME=mlp461','DB_NAME=mlp_phase3',file_get_contents($env)));
    echo "fresh full-suite schema ready\n";break;
case 'mode':
    $mode=($argv[2]??'')==='empty'?'':'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';
    $root=new mysqli('db','root',Infra\Env::get('DB_ROOT_PASS'));
    $s=$root->prepare('SET GLOBAL sql_mode=?');$s->bind_param('s',$mode);$s->execute();echo 'sql_mode='.$mode."\n";break;
}
