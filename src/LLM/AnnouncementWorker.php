<?php
namespace LLM;

use Domain\AnnouncementStore;
use Domain\EventManager;
use Infra\Database;
use Social\TelegramBotClient;
use Social\TelegramBotException;

/** Isolated polling editor; never runs in the realtime chat worker. */
final class AnnouncementWorker
{
    private ?int $explicitNow = null;
    public function __construct(private AnnouncementStore $store, private TelegramBotClient $telegram,
        private AnnouncementContent $content, private int $owner, private string $channel,
        private int $eventId, private int $initialNumber) {}

    public static function ownerUpdate(array $update, int $owner): bool
    {
        $callback = $update['callback_query'] ?? null;
        $msg = $callback['message'] ?? $update['message'] ?? [];
        $sender = $callback['from']['id'] ?? $msg['from']['id'] ?? 0;
        return $owner > 0 && (string)$sender === (string)$owner
            && ($msg['chat']['type'] ?? '') === 'private'
            && (string)($msg['chat']['id'] ?? '') === (string)$owner;
    }

    public static function keyboard(array $r): array
    {
        $button = static fn($text,$action)=>['text'=>$text,'callback_data'=>'ann:'.$r['id'].':'.$r['nonce'].':'.$action];
        return ['inline_keyboard'=>[
            [$button('Одобрить','approve')],
            [$button('Переделать текст','ask_text'),$button('Переделать картинку','ask_image')],
            [$button('Переделать оба','ask_both'),$button('Отменить пост','cancel')],
        ]];
    }

    public function tick(?int $now = null): void
    {
        $explicitNow = $now;
        $this->explicitNow = $now;
        $now ??= time();
        $db = Database::getInstance()->getConnection();
        if (!(int)$db->query("SELECT GET_LOCK('telegram_announcements',0) AS n")->fetch_assoc()['n']) return;
        try {
            $this->store->recover();
            $events = EventManager::expandOccurrences((new EventManager())->getAllRaw(),14,$now);
            foreach ($events as $event) {
                if ((int)$event['id']!==$this->eventId || (int)$event['real_start_time'] <= $now) continue;
                $schedule = AnnouncementContent::schedule($event);
                if (!$schedule || $now < $schedule['prepare_at']) continue;
                $facts = $this->content->facts($event,$now);
                if ($facts) $this->store->ensure($facts,AnnouncementContent::fingerprint($facts),$schedule,$this->initialNumber);
                break;
            }
            $updates = $this->telegram->request('getUpdates',['offset'=>(int)$this->store->option('announcements_update_offset','0')]);
            foreach ($updates as $update) {
                if (!is_array($update) || !is_int($update['update_id'] ?? null)) continue;
                $response = null;
                $accepted = $this->store->consume($update['update_id'],function () use ($update,$now,&$response) {
                    if (self::ownerUpdate($update,$this->owner)) $response = $this->handle($update,$now);
                });
                if ($accepted && $response !== null) $this->respond($update,$response);
            }
            // Delivery is checked before potentially slow content generation.
            foreach ($this->store->active() as $row) {
                $r = $this->store->revision((int)$row['id']);
                if (!$r) continue;
                if ($r['state']==='uncertain') continue;
                if ($now >= strtotime($r['expires_at'].' UTC')) {
                    $this->store->change((int)$r['id'],$r['state'],'expired');
                    continue;
                }
                if ($r['retry_at'] && strtotime($r['retry_at'].' UTC')>$now) continue;
                if ($r['state']==='approved' && $now >= strtotime($r['due_at'].' UTC')) $this->publish($r,$explicitNow ?? time());
            }
            // One expensive phase per minute; text and images cannot delay chat replies.
            foreach ($this->store->active() as $row) {
                $r = $this->store->revision((int)$row['id']);
                if (!$r || ($r['retry_at'] && strtotime($r['retry_at'].' UTC')>$now)) continue;
                if (in_array($r['state'],['text_pending','image_pending','preview_pending','controls_pending'],true)) {
                    $this->prepare($r,$explicitNow ?? time());
                    break;
                }
            }
            $this->store->setOption('announcements_heartbeat',gmdate('Y-m-d H:i:s'));
        } finally {
            $db->query("SELECT RELEASE_LOCK('telegram_announcements')");
        }
    }

    private function handle(array $update, int $now): string
    {
        $callback = $update['callback_query'] ?? null;
        if ($callback) {
            if (!preg_match('/^ann:(\d+):([a-f0-9]{16}):(approve|ask_text|ask_image|ask_both|cancel)$/D',(string)($callback['data'] ?? ''),$m)) return 'Неизвестная кнопка.';
            $r = $this->store->revision((int)$m[1]);
            $messageId = (int)($callback['message']['message_id'] ?? 0);
            if (!$r || !in_array($messageId,[(int)$r['preview_photo_id'],(int)$r['preview_controls_id']],true)) return 'Эта кнопка не относится к текущему черновику.';
            $status = $this->store->action((int)$m[1],$m[2],$m[3],'',$now);
            return $this->statusText($status,$r);
        }
        $msg = $update['message'];
        $text = trim((string)($msg['text'] ?? ''));
        if ($text==='/start' || $text==='/help') return 'Здесь согласуем анонсы Лиры. Одобри каждый пост кнопкой под картинкой. Для правок ответь на черновик сообщением: опиши изменения. Кнопки «Переделать» выбирают текст, картинку или оба; затем ответь замечаниями либо словом «заново». /status — состояние постов. /retry ID — новая версия после сбоя. /sent ID НОМЕР_СООБЩЕНИЯ — вручную подтвердить неопределённую публикацию.';
        if ($text==='/status') {
            $lines = [];
            foreach ($this->store->active() as $row) {
                $r = $this->store->revision((int)$row['id']);
                $lines[] = '#'.$r['stream_number'].' '.$r['kind'].' v'.$r['version'].' — '.$r['state'].'; '.(new \DateTimeImmutable($r['due_at'],new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('d.m H:i').' МСК; ID '.$r['id'];
            }
            return $lines ? implode("\n",$lines) : 'Активных черновиков нет. Они появятся в среду после 19:00 МСК, когда готов плейлист.';
        }
        // Explicit reconciliation is an owner action; no blind automatic retransmission.
        if (preg_match('/^\/sent\s+(\d+)\s+(\d+)$/D',$text,$m)) {
            $r = $this->store->revision((int)$m[1]);
            if ($r && $r['state']==='uncertain' && $r['approved_at'] && (int)$m[2]>0) {
                $this->store->change((int)$r['id'],'uncertain','published',['channel_message_id'=>(int)$m[2]]);
                return 'Публикация отмечена вручную. Повторной отправки не будет.';
            }
            return 'Нет неопределённой публикации с таким ID.';
        }
        if (preg_match('/^\/retry\s+(\d+)$/D',$text,$m)) {
            $r = $this->store->revision((int)$m[1]);
            if ($r && ($r['state']==='failed' || ($r['state']==='uncertain' && !$r['approved_at']))) {
                $this->store->change((int)$r['id'],$r['state'],'failed');
                $status = $this->store->action((int)$r['id'],$r['nonce'],'both','Повтор после сбоя подготовки или доставки черновика.',$now);
                return $this->statusText($status,$r);
            }
            return 'Повторная публикация в канал после неопределённого ответа запрещена. Проверь канал; если пост появился, используй /sent ID НОМЕР_СООБЩЕНИЯ.';
        }
        $reply = (int)($msg['reply_to_message']['message_id'] ?? 0);
        $preview = $reply ? $this->store->findPreview($reply) : null;
        if (!$preview || $text==='') return 'Ответь текстом на нужный черновик, чтобы я поняла, какой пост править. /help — подсказка.';
        $r = $this->store->revision((int)$preview['id']);
        $action = str_starts_with($r['state'],'feedback_') ? substr($r['state'],9) : 'text';
        $status = $this->store->action((int)$r['id'],$r['nonce'],$action,$text==='заново'?'':$text,$now);
        return $this->statusText($status,$r);
    }

    private function statusText(string $status, array $r): string
    {
        return match ($status) {
            'approved'=>'Одобрено. Пост будет опубликован по расписанию; при изменении программы потребуется новое согласование.',
            'awaiting_feedback'=>'Ответь на этот черновик с замечаниями или напиши «заново». Предыдущее одобрение снято.',
            'regenerating'=>'Готовлю новую версию. Она потребует отдельного одобрения.',
            'cancelled'=>'Публикация этого поста отменена.',
            'expired'=>'Время публикации этого анонса уже прошло.',
            default=>'Этот черновик уже изменён или недоступен. Используй кнопки последней версии.',
        };
    }

    private function respond(array $update, string $text): void
    {
        $text = $this->content->editorText($text);
        try {
            if (isset($update['callback_query'])) {
                $this->telegram->request('answerCallbackQuery',['callback_query_id'=>$update['callback_query']['id'],'text'=>mb_substr($text,0,190),'show_alert'=>true]);
            } else {
                $this->telegram->request('sendMessage',['chat_id'=>$this->owner,'text'=>$text]);
            }
        } catch (TelegramBotException $e) {
            error_log('Announcement editor notification unavailable');
        }
    }

    private function fresh(array $r, int $now): ?array
    {
        $old = json_decode($r['facts_json'],true,512,JSON_THROW_ON_ERROR);
        $fresh = $this->content->facts($old['occurrence'],$now);
        if (!$fresh) {
            if ($this->store->change((int)$r['id'],$r['state'],'cancelled',['last_error'=>'source_unavailable'])) $this->notify('Пост ID '.$r['id'].' отменён: событие или закреплённый плейлист больше недоступны.');
            return null;
        }
        $fingerprint = AnnouncementContent::fingerprint($fresh);
        if (!hash_equals($r['fingerprint'],$fingerprint)) {
            $this->store->ensure($fresh,$fingerprint,AnnouncementContent::schedule($fresh['occurrence']),$this->initialNumber);
            return null;
        }
        return $fresh;
    }

    private function prepare(array $r, int $now): void
    {
        $facts = $this->fresh($r,$now);
        if (!$facts) return;
        $id = (int)$r['id'];
        if ($r['state']==='text_pending') {
            if (!$this->store->change($id,'text_pending','text_generating')) return;
            try { $caption = $this->content->generateText($facts,$r['kind'],(int)$r['stream_number'],$r['feedback'],$r['previous_caption']); }
            catch (\Throwable $error) { $caption=null; error_log('Announcement text generation failed: '.get_class($error)); }
            if (!$caption) { $this->generationFailure($r,'text_generating','text_pending'); return; }
            $this->store->change($id,'text_generating',$r['image_path']?'preview_pending':'image_pending',['caption'=>$caption,'attempts'=>0,'retry_at'=>null]);
        } elseif ($r['state']==='image_pending') {
            if (!$this->store->change($id,'image_pending','image_generating')) return;
            try { $image = $this->content->generateImage($facts,$r['kind'],$r['caption'],$r['feedback']); }
            catch (\Throwable $error) { $image=null; error_log('Announcement image generation failed: '.get_class($error)); }
            if (!$image) { $this->generationFailure($r,'image_generating','image_pending'); return; }
            $this->store->change($id,'image_generating','preview_pending',['image_path'=>$image,'attempts'=>0,'retry_at'=>null]);
        } elseif ($r['state']==='preview_pending') {
            if (!$this->store->change($id,'preview_pending','preview_sending')) return;
            try {
                $result = $this->telegram->sendPhoto($this->owner,$r['caption'],$r['image_path'],self::keyboard($r));
                $this->store->change($id,'preview_sending','pending',['preview_photo_id'=>$result['message_id']]);
                $due = (new \DateTimeImmutable($r['due_at'],new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('d.m.Y H:i');
                $reason = str_starts_with($r['feedback'],'Расписание или плейлист изменились') ? ' Расписание или плейлист изменились; прежнее одобрение снято.' : '';
                $this->notify('Черновик #'.$r['stream_number'].' ('.$r['kind'].'), версия '.$r['version'].'. Публикация: '.$due.' МСК.' . $reason . ' Для правок ответь на картинку.');
            } catch (TelegramBotException $e) { $this->deliveryFailure($r,'preview_sending','preview_pending',$e); }
        } elseif ($r['state']==='controls_pending') {
            if (!$this->store->change($id,'controls_pending','controls_sending')) return;
            try {
                $due = (new \DateTimeImmutable($r['due_at'],new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('d.m.Y H:i');
                $result = $this->telegram->request('sendMessage',['chat_id'=>$this->owner,'text'=>'Черновик #'.$r['stream_number'].' ('.$r['kind'].'), версия '.$r['version'].'. Публикация: '.$due.' МСК. Одобри пост или ответь на картинку с замечаниями.','reply_parameters'=>['message_id'=>(int)$r['preview_photo_id']]]);
                $this->store->change($id,'controls_sending','pending',['preview_controls_id'=>$result['message_id']]);
            } catch (TelegramBotException $e) { $this->deliveryFailure($r,'controls_sending','controls_pending',$e); }
        }
    }

    private function publish(array $r, int $now): void
    {
        if (!$this->fresh($r,$now)) return;
        if ($now >= strtotime($r['expires_at'].' UTC') || !$r['approved_at'] || !$r['caption'] || !$r['image_path']) return;
        if (!$this->store->change((int)$r['id'],'approved','sending')) return;
        try {
            $result = $this->telegram->sendPhoto($this->channel,$r['caption'],$r['image_path']);
            $this->store->change((int)$r['id'],'sending','published',['channel_message_id'=>$result['message_id']]);
        } catch (TelegramBotException $e) { $this->deliveryFailure($r,'sending','approved',$e); }
    }

    private function generationFailure(array $r, string $expected, string $retry): void
    {
        $attempts = (int)$r['attempts']+1;
        $this->store->change((int)$r['id'],$expected,$attempts>=3?'failed':$retry,['attempts'=>$attempts,'retry_at'=>gmdate('Y-m-d H:i:s',($this->explicitNow ?? time())+600),'last_error'=>'generation_failed']);
        if ($attempts>=3) $this->notify('Не удалось подготовить пост после трёх попыток. /status покажет его ID; ответь на черновик, если он уже был доставлен.');
    }

    private function deliveryFailure(array $r, string $expected, string $retry, TelegramBotException $e): void
    {
        $attempts = (int)$r['attempts']+1;
        $state = $e->uncertain ? 'uncertain' : ($attempts>=3?'failed':$retry);
        $this->store->change((int)$r['id'],$expected,$state,['attempts'=>$attempts,'retry_at'=>gmdate('Y-m-d H:i:s',($this->explicitNow ?? time())+300),'last_error'=>$e->uncertain?'delivery_uncertain':'delivery_rejected']);
        $this->notify($e->uncertain ? 'Telegram не подтвердил доставку поста ID '.$r['id'].'. Автоматического повтора не будет. Проверь канал и черновики; /help — подсказка.' : 'Telegram отклонил отправку поста ID '.$r['id'].'. Проверь права бота; /status — состояние.');
    }

    private function notify(string $text): void
    {
        $text = $this->content->editorText($text);
        try { $this->telegram->request('sendMessage',['chat_id'=>$this->owner,'text'=>$text]); }
        catch (TelegramBotException $e) { error_log('Announcement owner notification unavailable'); }
    }
}
