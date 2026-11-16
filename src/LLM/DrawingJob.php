<?php
namespace LLM;

use Domain\ChatManager;
use Domain\Privacy;
use Infra\ConfigManager;
use Infra\Database;

/** Durable drawing preparation owns bot_drawing; only delivery owns bot_worker. */
final class DrawingJob {
    public const STALE_SECONDS = 600;
    public const INLINE_WAIT = 180;
    private static ?self $scope = null;
    private bool $intercepted = false;
    private array $afterAdmission = [];
    private array $state;

    private function __construct(private LLMManager $llm, private array $job, private bool $routing, private bool $inline = false) {
        $this->state=$job['data']['drawing_v1'] ?? [];
    }

    public static function current(): ?self { return self::$scope; }
    public static function preparing(): bool { return self::$scope!==null && !self::$scope->routing; }

    /** Route the existing command handler so draft/collection/dedup semantics stay in one place. */
    public static function route(LLMManager $llm, array $job, bool $inline = false, ?array &$afterAdmission = null): bool {
        if (($job['type'] ?? '')!=='dynamic_command' || !in_array($job['data']['command']['handler_type'] ?? '',['image','image_chat'],true)) return false;
        $scope=new self($llm,$job,true,$inline); $previous=self::$scope; self::$scope=$scope;
        try { $llm->processTrigger('dynamic_command',$job['data'] + ['_durable_confirmations'=>!$inline]); }
        finally { self::$scope=$previous; }
        $afterAdmission=$scope->afterAdmission;
        return $scope->intercepted;
    }

    /** Collection waits and its child drawing may start only after the admission voice lock is released. */
    public static function afterAdmission(callable $continue): void {
        if (self::$scope!==null && self::$scope->routing && self::$scope->inline) self::$scope->afterAdmission[]=$continue;
        else $continue();
    }

    /** Called at the existing drawing admission boundary, before any paid generation. */
    public function run(bool $auto, callable $draw): bool {
        if (!$this->routing) return $draw();
        $db=Database::getInstance()->getConnection();
        $data=$this->job['data']; $bindings=[];
        if (!empty($data['message_id'])) {
            $source=(new ChatManager())->getMessageById((int)$data['message_id']);
            $valid=$source && empty($source['deleted']) && empty($source['is_deleted']) && (int)$source['user_id']===(int)($data['user_id'] ?? 0);
            if ($valid && isset($data['drawing_source_version'])) $valid=hash_equals($data['drawing_source_version'],ChatManager::interactionSourceVersion($source));
            if ($valid && empty($data['drawing_request'])) $valid=trim(html_entity_decode($source['raw_message'],ENT_QUOTES|ENT_HTML5,'UTF-8'))===trim((string)($data['message'] ?? ''));
            if (!$valid) { $this->intercepted=true; $this->terminate('source_invalid'); return true; }
            $bindings[]=self::snapshot($source);
        }
        $busy=false;
        foreach ((new JobQueue())->drawingJobs() as $other) {
            if ((int)$other['id']!==(int)$this->job['id'] && $other['status']==='processing') { $busy=true; break; }
        }
        $busy=$busy || !(int)$db->query("SELECT IS_FREE_LOCK('bot_drawing') AS n")->fetch_assoc()['n'];
        if ($busy) {
            if ($this->inline) throw new DrawingBusy('Another drawing owns the slot');
            $this->intercepted=true;
            if (!$auto) {
                try { $this->busyNotice($bindings); }
                catch (\Core\UserError|DrawingLeaseLost $error) { $this->terminate('source_invalid'); return true; }
            }
            (new JobQueue())->waitDrawing((int)$this->job['id']);
            return true;
        }
        $row=(new JobQueue())->changeDrawing((int)$this->job['id'],function(array $row) use ($bindings): ?array {
            if ($row['status']!=='processing' || !empty($row['data']['drawing_v1']['phase'])) return null;
            $row['data']['drawing_v1']=($row['data']['drawing_v1'] ?? []) + ['phase'=>'prepare','lease_token'=>null,
                'phase_version'=>0,'stage_started_at'=>null,'bindings'=>$bindings,'users'=>[]];
            return ['data'=>$row['data'],'refresh'=>true];
        });
        if (!$row) throw new DrawingLeaseLost('Drawing admission changed');
        $this->intercepted=true;
        return true;
    }

    private function busyNotice(array $bindings): void {
        $queue=new JobQueue(); $id=(int)$this->job['id'];
        if (!empty(($queue->drawingRow($id)['data']['drawing_v1'] ?? [])['busy_notice_message_id'])) return;
        $data=$this->job['data'];
        $name=\Domain\UserMention::currentAddressName((int)($data['user_id'] ?? 0),(string)($data['username'] ?? 'Гость'));
        $fallback="@{$name}, сейчас занята другим рисунком. Твой запрос ждёт в очереди — займусь им следом.";
        $text=$this->llm->withPrivacyActor((int)($data['user_id'] ?? 0),fn()=> $this->llm->withAddressedMessages([(string)($data['message'] ?? '')],
            fn()=> $this->llm->liveTextBounded("Коротко скажи @{$name}: сейчас занята другим рисунком; его запрос ждёт в очереди и будет следующим. Не утверждай, что уже начала второй рисунок.","@{$name}",time()+15,10))) ?? $fallback;
        $chat=$this->llm->getChatManager();
        $message=$chat->addInteractionMessageGuarded($this->llm->getBotUserId(),$this->llm->getBotNickname(),$text,[],
            function() use ($queue,$id,$data,$bindings,$chat): void {
                $row=$queue->drawingRow($id,true);
                if (!$row || !empty($row['data']['drawing_v1']['busy_notice_message_id']) || !Privacy::payloadAllowed($data)) throw new DrawingLeaseLost('Drawing notice no longer allowed');
                $chat->lockInteractionMessages($bindings);
            },function(int $message) use ($queue,$id): void {
                $queue->changeDrawing($id,static function(array $row) use ($message): array {
                    $row['data']['drawing_v1']['busy_notice_message_id']=$message; return ['data'=>$row['data']];
                });
            },true);
        try { $chat->publishInteractionMessage($message); } catch (\Throwable $error) { error_log('Drawing busy realtime deferred'); }
    }

    private function update(callable $change, bool $refresh = false): void {
        $expected=$this->state;
        $row=(new JobQueue())->changeDrawing((int)$this->job['id'],static function(array $row) use ($expected,$change,$refresh): ?array {
            $state=$row['data']['drawing_v1'] ?? [];
            if ($row['status']!=='processing' || ($state['lease_token'] ?? null)!==($expected['lease_token'] ?? null)
                || ($state['phase_version'] ?? -1)!==($expected['phase_version'] ?? -1) || !empty($state['terminal_code'])) return null;
            $row['data']['drawing_v1']=$change($state);
            return ['data'=>$row['data'],'refresh'=>$refresh];
        });
        if (!$row) throw new DrawingLeaseLost('Drawing lease lost');
        $this->job=$row; $this->state=$row['data']['drawing_v1'];
    }

    public static function stage(string $phase, int $budget, callable $call): mixed {
        $scope=self::$scope;
        if (!$scope || $scope->routing) return $call();
        $scope->assertOwner();
        $scope->update(static function(array $state) use ($phase): array {
            $state['phase']=$phase; $state['phase_version']++; $state['stage_started_at']=time(); return $state;
        },true);
        return RequestDeadline::within(microtime(true)+$budget,$call);
    }

    private function assertOwner(): void {
        $db=Database::getInstance()->getConnection();
        $owned=(int)$db->query("SELECT COALESCE(IS_USED_LOCK('bot_drawing')=CONNECTION_ID(),0) AS n")->fetch_assoc()['n'];
        $row=(new JobQueue())->drawingRow((int)$this->job['id']);
        if (!$owned || !$row || $row['status']!=='processing' || ($row['data']['drawing_v1']['lease_token'] ?? null)!==($this->state['lease_token'] ?? null)) throw new DrawingLeaseLost('Drawing connection or lease lost');
        if (!self::allowed($row['data'])) throw new DrawingLeaseLost('Drawing source no longer allowed');
        if (!empty($row['data']['auto']) && DrawingSchedule::automaticBlocked()) throw new DrawingLeaseLost('Automatic drawing superseded');
    }

    public static function imageOptions(string $variant = 'original'): array {
        if (!self::preparing()) return [];
        $scope=self::$scope;
        return ['deadline'=>microtime(true)+331,'generation_id'=>'drawing_'.$scope->job['id'].'_'.$variant,'scene_variant'=>$variant,
            'guard'=>static function() use ($scope): bool { $scope->assertOwner(); return true; }];
    }

    public static function guard(): void {
        if (self::preparing()) self::$scope->assertOwner();
    }

    public static function bindContext(array &$context): void {
        if (!self::preparing()) return;
        $bindings=[]; $chat=new ChatManager();
        foreach ($context as &$entry) if (!empty($entry['chat_message_id'])) {
            $binding=$entry['_drawing_binding'] ?? null;
            unset($entry['_drawing_binding']);
            $source=$chat->getMessageById((int)$entry['chat_message_id']);
            if (!$binding || !$source || !empty($source['deleted']) || !empty($source['is_deleted'])
                || !hash_equals($binding['version'],ChatManager::interactionSourceVersion($source))) throw new DrawingLeaseLost('Drawing context snapshot changed');
            $bindings[]=$binding;
        }
        unset($entry);
        self::bindSnapshots($bindings);
    }

    /** Metadata is captured from the same read as the actual text sent to the director. */
    public static function snapshot(array $source): array {
        return ['id'=>(int)$source['id'],'user_id'=>(int)$source['user_id'],'version'=>ChatManager::interactionSourceVersion($source),
            'revision'=>(int)($source['conversation_revision'] ?? 0)];
    }

    public static function bindSnapshots(array $bindings): void {
        if (!self::preparing()) return;
        self::$scope->update(static function(array $state) use ($bindings): array {
            $byId=[];
            foreach (array_merge($state['bindings'] ?? [],$bindings) as $binding) {
                $old=$byId[$binding['id']] ?? null;
                if ($old!==null && ($old['user_id']!==$binding['user_id'] || !hash_equals($old['version'],$binding['version'])
                    || ($old['revision'] ?? null)!==($binding['revision'] ?? null))) throw new DrawingLeaseLost('Drawing source snapshots disagree');
                $byId[$binding['id']]=$binding;
            }
            $state['bindings']=array_values($byId); return $state;
        });
        self::$scope->assertOwner();
    }

    public static function bindUsers(array $ids): void {
        if (!self::preparing()) return;
        self::$scope->update(static function(array $state) use ($ids): array {
            $state['users']=array_values(array_unique(array_merge($state['users'] ?? [],array_map('intval',$ids)))); return $state;
        });
    }

    public static function remember(array $facts): void {
        if (self::preparing()) self::$scope->update(static fn(array $state): array => array_merge($state,$facts));
    }

    public static function savedImage(string $url, array $facts): void {
        if (!self::preparing()) return;
        $owner=(int)(self::$scope->job['data']['user_id'] ?? 0);
        if ($owner>0) Privacy::registerUpload($url,$owner);
        self::$scope->update(static function(array $state) use ($url,$facts): array {
            return array_merge($state,$facts,['image_url'=>$url,'generated_day'=>gmdate('Y-m-d')]);
        });
        self::account(self::$scope->job);
        self::$scope->assertOwner();
    }

    private static function account(array $job): void {
        $state=$job['data']['drawing_v1'];
        if (!empty($state['image_url'])) {
            $owner=(int)($job['data']['user_id'] ?? 0);
            if ($owner>0) Privacy::registerUpload($state['image_url'],$owner);
            if (empty($state['counted'])) {
                ImageGenerator::bumpToday(null,$state['generated_day'],'drawing_'.$job['id']);
                (new JobQueue())->changeDrawing((int)$job['id'],static function(array $row) use ($state): ?array {
                    if (($row['data']['drawing_v1']['image_url'] ?? '')!==$state['image_url']) return null;
                    $row['data']['drawing_v1']['counted']=true;
                    return ['data'=>$row['data']];
                });
            }
        }
    }

    public static function ready(array $facts = []): void {
        if (!self::preparing()) return;
        self::$scope->update(static function(array $state) use ($facts): array {
            return array_merge($state,$facts,['phase'=>'deliver','lease_token'=>null,'stage_started_at'=>null,'phase_version'=>$state['phase_version']+1]);
        },true);
    }

    /** Collect failure speech without a model call or publication during preparation. */
    public static function capture(string $fallback, ?string $instruction = null): bool {
        if (!self::preparing()) return false;
        self::ready(['failure_fallback'=>$fallback,'failure_instruction'=>$instruction ?? 'Коротко объясни результат запроса рисунка: '.$fallback]);
        return true;
    }

    public static function prepareOne(LLMManager $llm, ?int $onlyId = null): bool {
        $db=Database::getInstance()->getConnection(); $queue=new JobQueue();
        if (!(int)$db->query("SELECT GET_LOCK('bot_drawing',0) AS n")->fetch_assoc()['n']) return false;
        $previous=self::$scope; $scope=null;
        try {
            $jobs=$queue->drawingJobs();
            foreach ($jobs as $job) if ($job['status']==='processing' && ($job['data']['drawing_v1']['phase'] ?? '')==='deliver') return false;
            foreach ($jobs as $job) {
                $state=$job['data']['drawing_v1'];
                if ($onlyId!==null && (int)$job['id']!==$onlyId) continue;
                if ($job['status']!=='processing' || $state['phase']!=='prepare' || !empty($state['lease_token'])) continue;
                if (!$llm->isEnabled()) return false;
                $scope=new self($llm,$job,false);
                $scope->update(static function(array $state): array { $state['phase']='scene'; $state['lease_token']=bin2hex(random_bytes(16)); $state['phase_version']++; $state['stage_started_at']=time(); return $state; },true);
                self::$scope=$scope;
                $scope->assertOwner();
                $llm->processTrigger('dynamic_command',$scope->job['data']);
                if (($scope->state['phase'] ?? '')!=='deliver') self::ready(['silent'=>true]);
                return true;
            }
            return false;
        } catch (DrawingLeaseLost $error) {
            if ($scope) $scope->terminate('source_or_lease_invalid');
            return false;
        } catch (\Throwable $error) {
            if (Database::connectionLost($error)) throw $error;
            error_log('Drawing preparation failed: '.get_class($error));
            if ($scope) self::capture('Не удалось закончить рисунок. Попробуй ещё раз чуть позже.');
            return false;
        } finally {
            self::$scope=$previous;
            $db->query("SELECT RELEASE_LOCK('bot_drawing')");
        }
    }

    private function terminate(string $code): void {
        try {
            $expected=$this->state;
            (new JobQueue())->changeDrawing((int)$this->job['id'],static function(array $row) use ($expected,$code): ?array {
                $state=$row['data']['drawing_v1'] ?? [];
                if ($row['status']!=='processing' || ($state['lease_token'] ?? null)!==($expected['lease_token'] ?? null) || ($state['phase_version'] ?? -1)!==($expected['phase_version'] ?? -1)) return null;
                $row['data']['drawing_v1']['phase']='terminal'; $row['data']['drawing_v1']['terminal_code']=$code;
                return ['data'=>$row['data'],'status'=>'failed'];
            });
        } catch (\Throwable $error) { if (Database::connectionLost($error)) throw $error; }
    }

    private static function allowed(array $data, bool $lock = false): bool {
        ConfigManager::getInstance()->flushCache();
        if (!(int)ConfigManager::getInstance()->getOption('ai_enabled',0)) return false;
        if (!Privacy::payloadAllowed($data)) return false;
        $actor=(int)($data['user_id'] ?? 0); $state=$data['drawing_v1'] ?? [];
        $users=array_values(array_unique(array_filter(array_merge([$actor],$state['users'] ?? [],array_column($state['bindings'] ?? [],'user_id')))));
        if ($lock) Privacy::memoryStates($users,true);
        foreach ($users as $id) if (Privacy::isWithdrawn((int)$id)) return false;
        if ($actor>0) (new ChatManager())->assertCanSend($actor,$lock);
        $active=false;
        foreach ((new \Domain\BotCommandManager())->getActive() as $command) if ((int)$command['id']===(int)($data['command']['id'] ?? 0) && $command['handler_type']===($data['command']['handler_type'] ?? '')) $active=true;
        if (!$active) return false;
        if ($lock) {
            $locked=(new ChatManager())->lockInteractionMessages($state['bindings'] ?? []);
            foreach ($state['bindings'] ?? [] as $binding) if (isset($binding['revision']) && (int)$locked[$binding['id']]['conversation_revision']!==$binding['revision']) return false;
        }
        else foreach ($state['bindings'] ?? [] as $binding) {
            $source=(new ChatManager())->getMessageById((int)$binding['id']);
            if (!$source || !empty($source['deleted']) || !empty($source['is_deleted']) || (int)$source['user_id']!==(int)$binding['user_id'] || !hash_equals($binding['version'],ChatManager::interactionSourceVersion($source))
                || (isset($binding['revision']) && (int)$source['conversation_revision']!==$binding['revision'])) return false;
        }
        return true;
    }

    /** Caller owns bot_worker. No image/director/vision work is permitted here. */
    public static function deliver(LLMManager $llm, ?int $onlyId = null): void {
        if (!(int)ConfigManager::getInstance()->getOption('ai_enabled',0)) return;
        $db=Database::getInstance()->getConnection();
        if (!(int)$db->query("SELECT COALESCE(IS_USED_LOCK('bot_worker')=CONNECTION_ID(),0) AS n")->fetch_assoc()['n']) throw new \LogicException('Drawing delivery requires worker lock');
        $queue=new JobQueue();
        foreach ($queue->drawingJobs() as $job) {
            if ($onlyId!==null && (int)$job['id']!==$onlyId) continue;
            if ($job['status']!=='processing' || ($job['data']['drawing_v1']['phase'] ?? '')!=='deliver' || !empty($job['data']['drawing_v1']['lease_token'])) continue;
            if (!(int)ConfigManager::getInstance()->getOption('ai_enabled',0)) continue;
            $scope=new self($llm,$job,false);
            $scope->update(static function(array $state): array { $state['lease_token']=bin2hex(random_bytes(16)); $state['phase_version']++; return $state; },true);
            try {
                if (!self::allowed($scope->job['data']) || (!empty($scope->job['data']['auto']) && DrawingSchedule::automaticBlocked())) { $scope->terminate('source_or_auto_invalid'); continue; }
                self::account($scope->job);
                if (!empty($scope->state['silent']) || (!empty($scope->job['data']['auto']) && empty($scope->state['image_url']))) { $scope->finishSilent(); continue; }
                if (empty($scope->state['prepared_reply'])) {
                    $generate=fn()=> (new LyraArtist($llm))->deliveryText($scope->job['data'],$scope->state);
                    $actor=(int)($scope->job['data']['user_id'] ?? 0);
                    $reply=$actor>0 ? $llm->withPrivacyActor($actor,$generate) : $generate();
                    if (!$reply) { $scope->terminate('source_invalid'); continue; }
                    $scope->update(static function(array $state) use ($reply): array { $state['prepared_reply']=$reply; return $state; });
                }
                $scope->publish();
            } catch (\Core\UserError $error) { $scope->terminate('source_invalid'); }
            catch (DrawingLeaseLost $error) { /* A different owner or terminal outcome won. */ }
            catch (\Throwable $error) {
                if (Database::connectionLost($error)) throw $error;
                error_log('Drawing delivery deferred: '.get_class($error));
                try { $scope->update(static function(array $state): array { $state['lease_token']=null; $state['phase_version']++; return $state; }); }
                catch (DrawingLeaseLost $lost) { /* Recovery or deletion already owns the row. */ }
            }
        }
    }

    private function finishSilent(): void {
        $expected=$this->state;
        (new JobQueue())->changeDrawing((int)$this->job['id'],static function(array $row) use ($expected): ?array {
            $state=$row['data']['drawing_v1'] ?? [];
            if ($row['status']!=='processing' || ($state['lease_token'] ?? null)!==($expected['lease_token'] ?? null)
                || ($state['phase_version'] ?? -1)!==($expected['phase_version'] ?? -1)) return null;
            $row['data']['drawing_v1']['phase']='terminal';$row['data']['drawing_v1']['terminal_code']='silent';
            return ['data'=>$row['data'],'status'=>'done'];
        });
    }

    private function publish(): void {
        $queue=new JobQueue(); $id=(int)$this->job['id']; $token=$this->state['lease_token'];
        $chat=$this->llm->getChatManager(); $payload=null;
        $message=$chat->addInteractionMessageGuarded($this->llm->getBotUserId(),$this->llm->getBotNickname(),$this->state['prepared_reply'],[],
            function() use ($queue,$id,$token,&$payload): void {
                $row=$queue->drawingRow($id,true); $payload=$row['data'] ?? [];
                if (!$row || $row['status']!=='processing' || ($payload['drawing_v1']['lease_token'] ?? '')!==$token || !empty($payload['drawing_v1']['reply_message_id'])) throw new DrawingLeaseLost('Drawing already delivered');
                if (!self::allowed($payload,true)) throw new \Core\UserError('Drawing source invalid');
                if (!(int)ConfigManager::getInstance()->getOption('ai_enabled',0)) throw new \Core\UserError('AI disabled');
                if (!empty($payload['auto']) && DrawingSchedule::automaticBlocked()) throw new \Core\UserError('Automatic drawing superseded');
            },function(int $message) use ($queue,$id,&$payload): void {
                $payload['drawing_v1']['reply_message_id']=$message;
                if (!empty($payload['drawing_v1']['image_url'])) $payload['drawing_v1']['image_message_id']=$message;
                $payload['drawing_v1']['phase']='terminal'; $payload['drawing_v1']['terminal_code']='delivered';
                $payload['drawing_v1']['oc_jobs']=self::enqueueOc($payload);
                $queue->changeDrawing($id,static fn(array $row): array => ['data'=>$payload,'status'=>'done']);
                if (!empty($payload['drawing_v1']['image_url']) && empty($payload['auto'])) DrawingSchedule::manualPublished();
            },true);
        try { $chat->publishInteractionMessage($message); } catch (\Throwable $error) { error_log('Drawing realtime notification deferred'); }
    }

    public static function recover(): void {
        $queue=new JobQueue();
        foreach ($queue->drawingJobs() as $job) {
            $state=$job['data']['drawing_v1'];
            if (!empty($state['terminal_code'])) continue;
            if ($job['status']!=='failed' && strtotime($job['claimed_at'].' UTC')>=time()-self::STALE_SECONDS) continue;
            $queue->changeDrawing((int)$job['id'],static function(array $row) use ($state): ?array {
                if (($row['data']['drawing_v1']['phase_version'] ?? -1)!==($state['phase_version'] ?? -1) || !empty($row['data']['drawing_v1']['terminal_code'])) return null;
                $saved=!empty($state['image_url']) && self::validImage($state['image_url']);
                if ($saved || isset($state['failure_fallback'])) {
                    $row['data']['drawing_v1']['phase']='deliver'; $row['data']['drawing_v1']['lease_token']=null;
                    $row['data']['drawing_v1']['phase_version']++;
                    return ['data'=>$row['data'],'status'=>'processing','refresh'=>true];
                }
                $row['data']['drawing_v1']['phase']='terminal'; $row['data']['drawing_v1']['terminal_code']='stale_without_result';
                return ['data'=>$row['data'],'status'=>'failed'];
            });
        }
    }

    public static function validImage(string $url): bool {
        try { ImageGenerator::referenceData($url); return true; } catch (\Throwable $error) { return false; }
    }

    /** Enqueue speech atomically with the main receipt; each OC announcement follows the drawing. */
    private static function enqueueOc(array $payload): array {
        $state=$payload['drawing_v1']; $out=[];
        if (!empty($payload['auto']) && empty($state['image_url'])) return $out;
        foreach ($state['oc_created'] ?? [] as $id=>$oc) {
            if (Privacy::isWithdrawn((int)$id)) continue;
            $name=\Domain\UserMention::currentAddressName((int)$id,(string)$oc['nick']);
            $look=trim(mb_substr((string)$oc['text'],mb_strlen(LyraOc::PREFIX)));
            $where=!empty($state['image_url']) ? ' Рисунок с этим обликом уже опубликован выше.' : '';
            $out[$id]=(new JobQueue())->enqueue('dynamic_command',['user_id'=>(int)$id,'command'=>['handler_type'=>'command_reply_delivery'],
                'message'=>'','confirmation'=>['instruction'=>"Ты придумала, как выглядит @{$name} в виде пони: «{$look}».{$where} Коротко перескажи облик этому человеку и добавь, что его можно переписать командой «/яос своё описание». Не задавай других вопросов.",
                    'fallback'=>"@{$name}, я представила тебя пони: {$look}. Если хочешь другой облик — напиши «/яос своё описание».",
                    'quotes'=>[],'mustContain'=>"@{$name}"]],0);
        }
        return $out;
    }

    public static function inlineDeliver(LLMManager $llm, int $id, ?callable $clock = null, ?callable $pause = null): void {
        $clock ??= static fn()=>microtime(true); $pause ??= static fn()=>usleep(250000);
        $deadline=$clock()+self::INLINE_WAIT; $queue=new JobQueue(); $db=Database::getInstance()->getConnection();
        do {
            $job=$queue->drawingRow($id);
            if (!$job || $job['status']==='failed') return;
            $ocPending=false;
            foreach ($job['data']['drawing_v1']['oc_jobs'] ?? [] as $ocId) {
                $ocJob=$queue->drawingRow((int)$ocId);
                if ($ocJob && !in_array($ocJob['status'],['done','failed'],true)) $ocPending=true;
            }
            if ($job['status']==='done' && !$ocPending) return;
            if ((int)$db->query("SELECT GET_LOCK('bot_worker',0) AS n")->fetch_assoc()['n']) {
                try { self::deliver($llm,$id); self::drainInlineOc($llm,$id); }
                finally { $db->query("SELECT RELEASE_LOCK('bot_worker')"); }
            }
            $pause();
        } while ($clock()<$deadline);
        $queue->changeDrawing($id,static function(array $row): ?array {
            $state=$row['data']['drawing_v1'] ?? [];
            if ($row['status']!=='processing' || ($state['phase'] ?? '')!=='deliver' || !empty($state['lease_token']) || !empty($state['reply_message_id'])) return null;
            $row['data']['drawing_v1']['phase']='terminal'; $row['data']['drawing_v1']['terminal_code']='delivery_lock_timeout';
            return ['data'=>$row['data'],'status'=>'failed'];
        });
    }

    private static function drainInlineOc(LLMManager $llm, int $drawingId): void {
        $queue=new JobQueue(); $drawing=$queue->drawingRow($drawingId);
        if (!$drawing || $drawing['status']!=='done') return;
        foreach ($drawing['data']['drawing_v1']['oc_jobs'] ?? [] as $id) {
            $job=$queue->drawingRow((int)$id);
            if (!$job || in_array($job['status'],['done','failed'],true)) continue;
            if (!Privacy::payloadAllowed($job['data'])) { $queue->complete([(int)$id]); continue; }
            if (empty($job['data']['prepared_reply'])) {
                $confirmation=$job['data']['confirmation'];
                $text=RequestDeadline::within(microtime(true)+10,fn()=> $llm->withPrivacyActor((int)$job['data']['user_id'],
                    fn()=> $llm->withAddressedMessages([],fn()=> $llm->liveTextBounded($confirmation['instruction'],$confirmation['mustContain'],time()+10,8))));
                $job['data']['prepared_reply']=['text'=>$text ?? $confirmation['fallback'],'quotes'=>[]];
                $job['data']=$queue->savePayload((int)$id,$job['data']);
            }
            CommandReplyDelivery::publish($llm,$job);
        }
    }
}
