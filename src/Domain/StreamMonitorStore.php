<?php
namespace Domain;

use Infra\Database;
use Infra\Transaction;

/** Owns durable stream observations, incidents and the delivery outbox. */
final class StreamMonitorStore
{
    private \mysqli $db;
    public function __construct() { $this->db=Database::getInstance()->getConnection(); }
    public function sql(string $sql, array $args=[]): \mysqli_stmt
    {
        $s=$this->db->prepare($sql); if($args)$s->bind_param(str_repeat('s',count($args)),...$args); $s->execute(); return $s;
    }
    public function rows(string $sql, array $args=[]): array { return $this->sql($sql,$args)->get_result()->fetch_all(MYSQLI_ASSOC); }
    public function transaction(callable $work): mixed { return Transaction::run($this->db,$work); }
    public function locked(callable $work,int $wait=3): mixed
    {
        if (!(int)$this->rows("SELECT GET_LOCK('mlp_stream_monitor',?) AS ok",[$wait])[0]['ok']) throw new \Core\UserError('Уведомление сейчас отправляется. Повтори ответ через несколько секунд.');
        try { return $work(); } finally { $this->sql("DO RELEASE_LOCK('mlp_stream_monitor')"); }
    }
    public function run(string $id,bool $lock=false): ?array { return $this->rows('SELECT * FROM stream_monitor_runs WHERE run_id=?'.($lock?' FOR UPDATE':''),[$id])[0]??null; }
    public function incident(int $id,bool $lock=false): ?array { return $this->rows('SELECT * FROM stream_incidents WHERE id=?'.($lock?' FOR UPDATE':''),[$id])[0]??null; }
    public function deliveries(int $incident): array { return $this->rows('SELECT * FROM stream_monitor_deliveries WHERE incident_id=? ORDER BY id',[$incident]); }
    public function enqueue(int $id, string $stage): void { $this->sql('INSERT IGNORE INTO stream_monitor_deliveries(incident_id,stage) VALUES(?,?)',[$id,$stage]); }
    public function saveRun(array $r): void
    {
        $fields=['last_checked','progress_key','progress_at','healthy_since','armed','lost_at','recovered_since','incident_id','ended','health'];
        $values=array_map(static fn($key)=>$r[$key],$fields);$values[]=$r['run_id'];
        $this->sql('UPDATE stream_monitor_runs SET '.implode(',',array_map(static fn($k)=>$k.'=?',$fields)).' WHERE run_id=?',$values);
    }
    public function finish(array $run): void
    {
        $this->sql('UPDATE stream_monitor_runs SET ended=1 WHERE run_id=?',[$run['run_id']]);
        if (!$run['incident_id']) return;
        $this->sql("UPDATE stream_incidents SET state='ended' WHERE id=? AND state IN ('open','acknowledged','intentional')",[$run['incident_id']]);
        $this->sql("UPDATE stream_monitor_deliveries SET state='cancelled',reason='event_ended' WHERE incident_id=? AND state='pending'",[$run['incident_id']]);
        $this->closeInteraction((int)$run['incident_id']);
    }
    public function closeInteraction(int $incident): void
    {
        $this->sql("UPDATE command_interactions c JOIN stream_incidents i ON i.interaction_id=c.id SET c.state='expired' WHERE i.id=? AND c.state='pending'",[$incident]);
    }
    public function journal(): array
    {
        return $this->rows('SELECT i.id,i.state,i.lost_at,i.recovered_at,e.title,u.nickname,d.stage,d.state AS delivery_state,d.reason,d.updated_at FROM stream_incidents i JOIN stream_monitor_runs r ON r.run_id=i.run_id LEFT JOIN events e ON e.id=r.event_id LEFT JOIN users u ON u.id=r.streamer_user_id LEFT JOIN stream_monitor_deliveries d ON d.incident_id=i.id ORDER BY i.id DESC,d.id DESC LIMIT 50');
    }

    public function eraseUserData(int $user): void
    {
        foreach($this->rows('SELECT * FROM stream_monitor_runs WHERE streamer_user_id=?',[$user]) as $run){
            $this->finish($run);$anonymous='erased_'.bin2hex(random_bytes(20));
            $this->sql('UPDATE stream_incidents SET run_id=? WHERE run_id=?',[$anonymous,$run['run_id']]);
            $this->sql('UPDATE stream_monitor_runs SET run_id=?,streamer_user_id=0 WHERE run_id=?',[$anonymous,$run['run_id']]);
        }
        $this->sql('UPDATE stream_incidents SET acknowledged_by=NULL WHERE acknowledged_by=?',[$user]);
        $this->sql('UPDATE events SET streamer_user_id=NULL,monitor_stream=0 WHERE streamer_user_id=?',[$user]);
        $config=\Infra\ConfigManager::getInstance();
        if((int)$config->getOption('stream_monitor_default_streamer','0')===$user)$config->setOption('stream_monitor_default_streamer','0');
    }

    public function suspendStreamer(int $user): void
    {
        foreach($this->rows('SELECT * FROM stream_monitor_runs WHERE streamer_user_id=? AND ended=0',[$user]) as $run)$this->finish($run);
        $this->sql('UPDATE events SET streamer_user_id=NULL,monitor_stream=0 WHERE streamer_user_id=?',[$user]);
        $config=\Infra\ConfigManager::getInstance();
        if((int)$config->getOption('stream_monitor_default_streamer','0')===$user){
            $config->setOption('stream_monitor_default_streamer','0');
            $this->sql('UPDATE events SET monitor_stream=0 WHERE streamer_user_id IS NULL AND monitor_stream=1');
        }
    }
}
