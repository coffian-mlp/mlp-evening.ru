<?php
namespace Domain;

use Core\UserError;
use Infra\ConfigManager;
use Infra\StreamHealthProbe;
use LLM\LLMManager;
use Social\TelegramBotException;

/** Scheduled own-source supervision, independent of chat readers and announcement generation. */
final class StreamMonitor
{
    private StreamMonitorStore $store;
    private $probe;
    private $writer;
    private $telegram;

    public function __construct(?StreamMonitorStore $store=null, ?callable $probe=null, ?callable $writer=null, ?callable $telegram=null)
    {
        $this->store=$store??new StreamMonitorStore();
        $this->probe=$probe??static fn($path)=>(new StreamHealthProbe())->sample($path);
        $this->writer=$writer??static fn($instruction)=>(new LLMManager())->liveTextBounded($instruction,null,time()+8,6,null,
            ['actor_id'=>0,'include_pinned'=>false,'data'=>['original_query'=>'Служебное уведомление о состоянии эфира']]);
        $this->telegram=$telegram??static fn($params)=>AnnouncementSettings::client(AnnouncementSettings::values())->withRequestTimeout(5)->request('sendMessage',$params);
    }

    public static function registry(): array
    {
        return ['stream_incident'=>[
            'visibility'=>'public',
            'pending_label'=>'Ожидаем ответа стримера',
            'action_label'=>'Ответ стримера:',
            'permission'=>static fn($actor,$payload)=>(new self())->canAct((int)($payload['incident_id']??0),$actor),
            'execute'=>static fn($actor,$payload)=>(new self())->act((int)$payload['incident_id'],$actor,(string)$payload['action']),
            'system_consume'=>static fn($row,$actor,$key)=>(new self())->actInteraction($row,$actor,$key),
        ]];
    }

    public function active(?int $now=null,bool $current=false): ?array
    {
        $now??=time();
        $settings=SelfHostedStream::settings();$default=(int)ConfigManager::getInstance()->getOption('stream_monitor_default_streamer','0');
        if($current){
            $options=array_column($this->store->rows("SELECT key_name,value FROM site_options WHERE key_name IN ('stream_source','self_hosted_hls_url','stream_monitor_default_streamer') FOR UPDATE"),'value','key_name');
            $settings=['source'=>$options['stream_source']??'external','url'=>$options['self_hosted_hls_url']??SelfHostedStream::DEFAULT_URL];
            $default=(int)($options['stream_monitor_default_streamer']??0);
        }
        if ($settings['source']!=='self_hosted') return null;
        $active=null;
        foreach (EventManager::expandOccurrences((new EventManager())->getAllRaw($current),1,$now) as $e) {
            $start=(int)$e['real_start_time'];$end=$start+(int)$e['duration_minutes']*60;
            if (empty($e['monitor_stream']) || $start>$now || $end<=$now) continue;
            $streamer=(int)($e['streamer_user_id']?:$default);
            $user=$streamer>0?(new UserManager())->getUserById($streamer):null;
            if (!$user || !UserRoles::has($user,'streamer') || Privacy::isWithdrawn($streamer)) continue;
            $e['streamer']=$streamer;$e['ends_at']=StreamHealthPolicy::date($end);
            $e['run_id'].='_u'.$streamer.'_'.substr(hash('sha256',$settings['url']),0,8);
            $active=$e; // One stream path: the latest started monitored event takes responsibility.
        }
        return $active;
    }

    public function tick(?int $now=null): void
    {
        ConfigManager::getInstance()->flushCache();
        $now??=time();$event=$this->active($now);
        $path=explode('/',SelfHostedStream::settings()['url'])[2];
        $sample=$event?($this->probe)($path):null;
        $this->store->locked(fn()=>$this->store->transaction(function()use($event,$path,$sample,$now){
            foreach($this->store->rows('SELECT * FROM stream_monitor_runs WHERE ended=0') as $run) {
                if (!$event || $run['run_id']!==$event['run_id'] || (int)$run['streamer_user_id']!==$event['streamer'] || $run['path']!==$path) $this->store->finish($run);
            }
            if (!$event) return;
            $this->store->sql('INSERT IGNORE INTO stream_monitor_runs(run_id,event_id,streamer_user_id,ends_at,path) VALUES(?,?,?,?,?)',[$event['run_id'],$event['id'],$event['streamer'],$event['ends_at'],$path]);
            $run=$this->store->run($event['run_id']);
            if ($run['ended']) {
                $this->store->sql("UPDATE stream_monitor_runs SET ended=0,armed=0,healthy_since=NULL,last_checked=NULL,lost_at=NULL,recovered_since=NULL,incident_id=NULL,progress_at=NULL,progress_key=NULL,health='unknown' WHERE run_id=?",[$run['run_id']]);
                $run=$this->store->run($event['run_id']);
            }
            $run=StreamHealthPolicy::observe($run,$sample,$now);
            $this->store->sql('UPDATE stream_monitor_runs SET ends_at=? WHERE run_id=?',[$event['ends_at'],$run['run_id']]);
            if ($run['lost_at']!==null && $run['incident_id']===null && $now-StreamHealthPolicy::stamp($run['lost_at'])>=45 && $run['health']==='down') {
                $this->store->sql('INSERT INTO stream_incidents(run_id,nonce,lost_at) VALUES(?,?,?)',[$run['run_id'],bin2hex(random_bytes(8)),$run['lost_at']]);
                $run['incident_id']=(int)$this->store->rows('SELECT LAST_INSERT_ID() AS id')[0]['id'];
            }
            $incident=$run['incident_id']?$this->store->incident((int)$run['incident_id']):null;
            if ($run['recovered_since']!==null && $now-StreamHealthPolicy::stamp($run['recovered_since'])>=30) {
                if ($incident && in_array($incident['state'],['open','acknowledged','intentional'],true)) {
                    $this->store->sql("UPDATE stream_incidents SET state='recovered',recovered_at=? WHERE id=?",[StreamHealthPolicy::date($now),$incident['id']]);
                    $this->store->sql("UPDATE stream_monitor_deliveries SET state='cancelled',reason='stream_recovered' WHERE incident_id=? AND state='pending'",[$incident['id']]);
                    $this->store->closeInteraction((int)$incident['id']);
                    if($this->wasSent((int)$incident['id'],'site_warning'))$this->store->enqueue((int)$incident['id'],'site_recovery');
                    if($this->wasSent((int)$incident['id'],'channel_pause'))$this->store->enqueue((int)$incident['id'],'channel_recovery');
                }
                $run['lost_at']=null;$run['recovered_since']=null;$run['incident_id']=null;
            } else {
                foreach(StreamHealthPolicy::stages($run,$incident,$now) as $stage)$this->store->enqueue((int)$incident['id'],$stage);
            }
            $this->store->saveRun($run);
        }));
        // A crashed Telegram send is ambiguous; chat insertion and delivery completion are atomic.
        $this->store->sql("UPDATE stream_monitor_deliveries SET state=IF(stage LIKE 'site_%','pending','uncertain'),reason='worker_interrupted' WHERE state='sending' AND updated_at<UTC_TIMESTAMP()-INTERVAL 90 SECOND");
        foreach($this->store->rows("SELECT * FROM stream_monitor_deliveries WHERE state='pending' AND (retry_at IS NULL OR retry_at<=?) ORDER BY id LIMIT 2",[StreamHealthPolicy::date($now)]) as $delivery) $this->deliver($delivery,$now);
        ConfigManager::getInstance()->setOption('stream_monitor_heartbeat',StreamHealthPolicy::date(time()));
    }

    private function wasSent(int $incident,string $stage): bool
    {
        return (bool)$this->store->rows("SELECT id FROM stream_monitor_deliveries WHERE incident_id=? AND stage=? AND state='sent'",[$incident,$stage]);
    }

    public function canAct(int $incidentId,int $actor,?int $now=null,bool $current=false): bool
    {
        $now??=time();$i=$this->store->incident($incidentId,$current);
        if (!$i || !in_array($i['state'],['open','acknowledged'],true)) return false;
        $run=$this->store->run($i['run_id'],$current);$active=$this->active($now,$current);
        if (!$run || $run['ended'] || !$active || $active['run_id']!==$run['run_id'] || $active['streamer']!==(int)$run['streamer_user_id']) return false;
        if($current){
            $user=$this->store->rows('SELECT u.role,u.roles_json,p.state FROM users u LEFT JOIN account_privacy p ON p.user_id=u.id WHERE u.id=? FOR UPDATE',[$actor])[0]??null;
            return $user && !in_array($user['state'],['withdrawn','deleted'],true) && ($actor===(int)$run['streamer_user_id']||UserRoles::has($user,'admin'));
        }
        $user=(new UserManager())->getUserById($actor);
        return $user && !Privacy::isWithdrawn($actor) && ($actor===(int)$run['streamer_user_id'] || $user['role']==='admin');
    }

    public function actInteraction(array $row,int $actor,string $key): array
    {
        if ($row['type']!=='stream_incident' || !in_array($key,['repair','intentional'],true)) throw new UserError('Неизвестное действие.');
        $options=json_decode($row['options_json'],true,512,JSON_THROW_ON_ERROR);
        foreach($options as $o)if($o['key']===$key)return $this->act((int)$o['payload']['incident_id'],$actor,$key);
        throw new UserError('Неизвестное действие.');
    }

    public function act(int $incidentId,int $actor,string $action,?int $now=null,?array $telegramProof=null): array
    {
        $now??=time();
        if (!in_array($action,['repair','intentional'],true)) throw new UserError('Неизвестное действие.');
        return $this->store->locked(fn()=>$this->store->transaction(function()use($incidentId,$actor,$action,$now,$telegramProof){
            if(!$this->canAct($incidentId,$actor,$now,true))throw new UserError('Этот ответ уже неактуален или у тебя нет прав стримера.');
            if($telegramProof!==null&&(new LyraNotifications())->currentActor($telegramProof['sender'],$telegramProof['chat'],$telegramProof['fingerprint'])!==$actor)throw new UserError('Подключение Telegram изменилось. Проверь уведомления в профиле.');
            $i=$this->store->incident($incidentId,true);
            $state=$action==='repair'?'acknowledged':'intentional';
            $this->store->sql('UPDATE stream_incidents SET state=?,acknowledged_at=COALESCE(acknowledged_at,?),acknowledged_by=? WHERE id=?',[$state,StreamHealthPolicy::date($now),$actor,$incidentId]);
            $this->store->sql("UPDATE stream_monitor_deliveries SET state='cancelled',reason='streamer_answered' WHERE incident_id=? AND state='pending'",[$incidentId]);
            $this->store->enqueue($incidentId,$action==='repair'?'site_acknowledged':'site_intentional');
            $outcome=['status'=>'accepted','code'=>$state,'facts'=>['incident_id'=>$incidentId]];
            $this->store->sql("UPDATE command_interactions SET state='consumed',selected_key=?,outcome_json=? WHERE id=?",[$action,json_encode($outcome,JSON_THROW_ON_ERROR),$i['interaction_id']]);
            return $outcome;
        }),8);
    }

    public function telegramAction(string $data,int $actor,?int $now=null,?array $proof=null): array
    {
        if(!preg_match('/^stream:(\d+):([a-f0-9]{16}):(repair|intentional)$/D',$data,$m))throw new UserError('Неизвестная кнопка.');
        $i=$this->store->incident((int)$m[1]);
        if(!$i || !hash_equals($i['nonce'],$m[2]))throw new UserError('Этот ответ уже неактуален.');
        return $this->act((int)$m[1],$actor,$m[3],$now,$proof);
    }

    private function eligible(array $delivery,?array $i,?array $run,int $now): bool
    {
        if(!$i || !$run || $run['ended'])return false;
        $active=$this->active($now);
        if(!$active || $active['run_id']!==$run['run_id'] || $active['streamer']!==(int)$run['streamer_user_id'])return false;
        $stage=$delivery['stage'];
        if(in_array($stage,['site_recovery','channel_recovery'],true))return $i['state']==='recovered' && $run['health']==='live';
        if($stage==='site_acknowledged')return $i['state']==='acknowledged';
        if($stage==='site_intentional')return $i['state']==='intentional';
        return in_array($stage,StreamHealthPolicy::stages($run,$i,$now),true) && $now-StreamHealthPolicy::stamp($run['last_checked'])<=35;
    }

    private function content(string $stage,array $i,array $run,int $now): array
    {
        $minutes=max(1,(int)floor(($now-StreamHealthPolicy::stamp($i['lost_at']))/60));
        [$task,$fallback]=match($stage){
            'site_warning'=>['Сигнал эфира пропал. Обратись к стримеру: попроси проверить трансляцию и выбрать одну из двух кнопок ниже. Причина неизвестна.','Сигнал эфира пропал. Стример, проверь трансляцию и ответь кнопкой ниже.'],
            'private_warning'=>['Личное срочное уведомление стримеру: сигнал эфира отсутствует '.$minutes.' мин., ответа на сайте нет. Попроси проверить эфир и ответить кнопкой.','Сигнал эфира отсутствует '.$minutes.' мин. Проверь трансляцию и ответь кнопкой ниже.'],
            'site_pause','channel_pause'=>['Сообщи зрителям: эфир приостановлен, сигнал отсутствует '.$minutes.' мин., стример пока не ответил. Можно легко пошутить, что он, возможно, уснул, но причина неизвестна.','Эфир приостановлен: сигнала нет '.$minutes.' мин., стример пока не ответил. Возможно, уснул — ждём вестей.'],
            'site_acknowledged'=>['Стример подтвердил, что видит обрыв и занимается восстановлением. Сообщи это зрителям, не обещая сроков.','Стример видит проблему и занимается восстановлением эфира.'],
            'site_intentional'=>['Стример подтвердил, что намеренно остановил трансляцию. Сообщи об этом зрителям. Не выдумывай причину и время возвращения.','Стример сообщил, что остановил эфир намеренно.'],
            'private_reminder'=>['Личное напоминание стримеру: с подтверждения прошло не менее 10 минут, сигнала всё ещё нет. Уточни, продолжается ли восстановление.','После твоего ответа прошло 10 минут, сигнала всё ещё нет. Проверь эфир; если остановил намеренно, сообщи кнопкой ниже.'],
            default=>['Сигнал эфира восстановился и устойчиво поступает. Сообщи зрителям, что можно продолжать просмотр.','Эфир восстановлен, можно продолжать просмотр.'],
        };
        $text=($this->writer)('Служебное уведомление от твоего лица. '.$task.' Только короткий текст по-русски, без команд, ссылок, служебных маркеров и выдуманных фактов.');
        if(!is_string($text)||trim($text)===''||mb_strlen($text)>900||str_contains($text,'[[')||str_contains($text,'@')||preg_match('/^\W*SILENCE\W*$/iu',$text))$text=$fallback;
        return [trim($text),$fallback];
    }

    private function deliver(array $delivery,int $now): void
    {
        $i=$this->store->incident((int)$delivery['incident_id']);$run=$i?$this->store->run($i['run_id']):null;
        if(!$this->eligible($delivery,$i,$run,$now))return;
        try{[$text]=$this->content($delivery['stage'],$i,$run,$now);}catch(\Throwable $e){
            $fallbackWriter=$this->writer;$this->writer=static fn()=>null;
            try{[$text]=$this->content($delivery['stage'],$i,$run,$now);}finally{$this->writer=$fallbackWriter;}
        }
        // Refresh the signal after generation. Returning video suppresses obsolete outage sends immediately.
        $fresh=($this->probe)($run['path']);
        $actualNow=max($now,time());
        $privateDelivery=str_starts_with($delivery['stage'],'private_');
        if($privateDelivery)Privacy::lockAccount((int)$run['streamer_user_id'],2);
        try {
        $message=$this->store->locked(function()use($delivery,$text,$fresh,$actualNow){
            $d=$this->store->rows('SELECT * FROM stream_monitor_deliveries WHERE id=?',[$delivery['id']])[0];
            $i=$this->store->incident((int)$d['incident_id']);$run=$this->store->run($i['run_id']);
            if($d['state']!=='pending'||!$this->eligible($d,$i,$run,$actualNow))return;
            $freshHealth=StreamHealthPolicy::observe($run,$fresh,$actualNow)['health'];
            if(!in_array($d['stage'],['site_recovery','channel_recovery','site_acknowledged','site_intentional'],true) && $freshHealth!=='down')return;
            if(in_array($d['stage'],['site_recovery','channel_recovery'],true) && $freshHealth!=='live')return;
            if(str_starts_with($d['stage'],'site_')) {
                return $this->store->transaction(fn()=>$this->siteMessage($d,$text,$i,$run));
            }
            $settings=AnnouncementSettings::values();$private=str_starts_with($d['stage'],'private_');
            $contact=$private?(new LyraNotifications())->contact((int)$run['streamer_user_id']):$settings['announcements_channel'];
            if(!$contact || $settings['announcements_token']==='' || (!$private&&!preg_match('/^(?:@[A-Za-z0-9_]{5,32}|-100\d+)$/D',$contact))){
                $this->store->sql("UPDATE stream_monitor_deliveries SET state='failed',reason='telegram_not_connected' WHERE id=?",[$d['id']]);return;
            }
            $params=['chat_id'=>$contact,'text'=>$text];
            if($private)$params['reply_markup']=['inline_keyboard'=>[[['text'=>'Вижу, исправляю','callback_data'=>'stream:'.$i['id'].':'.$i['nonce'].':repair'],['text'=>'Остановил намеренно','callback_data'=>'stream:'.$i['id'].':'.$i['nonce'].':intentional']]]];
            $this->store->sql("UPDATE stream_monitor_deliveries SET state='sending',attempts=attempts+1 WHERE id=?",[$d['id']]);
            try{
                $sent=($this->telegram)($params);
                if(empty($sent['message_id']))throw new TelegramBotException('Missing receipt',true);
                $this->store->sql("UPDATE stream_monitor_deliveries SET state='sent',message_id=?,reason=NULL WHERE id=?",[$sent['message_id'],$d['id']]);
            }catch(\Throwable $e){
                $uncertain=!($e instanceof TelegramBotException)||$e->uncertain;
                $reason=$e instanceof TelegramBotException?($e->diagnostics['reason']??'telegram_error'):'internal_error';
                $state=$uncertain?'uncertain':((int)$d['attempts']>=2?'failed':'pending');
                $this->store->sql('UPDATE stream_monitor_deliveries SET state=?,reason=?,retry_at=? WHERE id=?',[$state,substr($reason,0,100),StreamHealthPolicy::date($actualNow+60),$d['id']]);
            }
        });
        }finally{if($privateDelivery)Privacy::unlockAccount((int)$run['streamer_user_id']);}
        if(is_int($message)&&$message>0){try{(new ChatManager())->publishInteractionMessage($message);}catch(\Throwable $ignored){}}
    }

    private function siteMessage(array $d,string $text,array $i,array $run): int
    {
        $botId=(int)ConfigManager::getInstance()->getOption('ai_bot_user_id',0);
        $bot=$botId?(new UserManager())->getUserById($botId):null;
        if(!$bot)throw new \RuntimeException('Stream notification bot is not configured');
        $manager=new CommandInteractionManager(self::registry());$interaction=null;
        if($d['stage']==='site_warning') {
            $interaction=$manager->createSystem('stream_incident',(int)$run['streamer_user_id'],'stream:'.$i['id'],[
                ['key'=>'repair','label'=>'Вижу, исправляю','payload'=>['incident_id'=>(int)$i['id'],'action'=>'repair']],
                ['key'=>'intentional','label'=>'Остановил намеренно','payload'=>['incident_id'=>(int)$i['id'],'action'=>'intentional']],
            ],$run['ends_at']);
            $streamer=(new UserManager())->getUserById((int)$run['streamer_user_id']);
            $text='@'.($streamer['nickname']?:$streamer['login']).", ".$text."\n[[command:".$interaction.']]';
        }
        $message=(new ChatManager())->addMessage($botId,$bot['nickname']?:$bot['login'],$text,[],true);
        if(!$message)throw new \RuntimeException('Stream notification chat delivery failed');
        if($interaction){$manager->bindMessage($interaction,$message);$this->store->sql('UPDATE stream_incidents SET interaction_id=? WHERE id=?',[$interaction,$i['id']]);}
        $this->store->sql("UPDATE stream_monitor_deliveries SET state='sent',message_id=?,attempts=attempts+1 WHERE id=?",[$message,$d['id']]);
        return (int)$message;
    }
}
