<?php
namespace LLM;

use Domain\BotCommandManager;
use Domain\ChatManager;
use Infra\Database;
use Infra\ConfigManager;

/** A manual chat drawing has one announced collection window and one delayed drawing job. */
final class DrawingChatCollection
{
    public const SECONDS = 60;

    public static function begin(LLMManager $llm, array $command, array $payload): bool
    {
        $chat = new ChatManager();
        $source = self::source($command, $payload);
        if ($source === null) return false;
        $sourceId = (int)$source['id'];
        $marker = '[[command-delivery:work_drawchat_'.$sourceId.']]';
        $db = Database::getInstance()->getConnection();
        $key = 'drawing_chat:'.$sourceId;
        $q = $db->prepare('SELECT GET_LOCK(?,2)'); $q->bind_param('s', $key); $q->execute();
        if (!(int)$q->get_result()->fetch_row()[0]) throw new DrawingBusy('Chat drawing admission busy');
        $published = null; $next = null; $jobId = null;
        $inline = empty($payload['_durable_confirmations']) && !BotDispatch::shouldQueue();
        try {
            if ($chat->findBotReplyTo($sourceId, $marker)) return true;
            $version = ChatManager::interactionSourceVersion($source);
            $text = DrawingCommand::liveReply($llm,
                'Объяви чату: сейчас даёшь одну минуту на пожелания к рисунку по беседе. Пожелания можно прислать ответом с цитатой на это сообщение. Затем сама начнёшь рисовать с учётом пожеланий. Никаких подтверждений или нажатий не требуется; кнопок нет. Не проси черновик или повторное описание. 1–2 коротких предложения в своём характере.',
                'Даю минуту на пожелания к рисунку по чату — отвечайте с цитатой на это сообщение. Потом начну рисовать сама, подтверждать ничего не нужно.', time()+30,
                ['actor_id'=>(int)$payload['user_id'], 'data'=>[]]
            );
            $published = $chat->addInteractionMessageGuarded($llm->getBotUserId(), $llm->getBotNickname(), $text."\n".$marker, [$sourceId],
                static function () use ($chat, $command, $payload, $sourceId, $version): void {
                    if (self::source($command, $payload) === null) throw new \Core\UserError('Команда рисунка уже недоступна.');
                    $chat->lockInteractionMessages([['id'=>$sourceId, 'user_id'=>(int)$payload['user_id'], 'version'=>$version, 'version_encoding'=>'storage_v2']]);
                },
                static function (int $id) use ($payload, $command, $version, $inline, &$next, &$jobId): void {
                    $next = $payload;
                    unset($next['dedup_of']);
                    $next['command'] = $command;
                    $next['drawing_chat_window'] = ['message_id'=>$id, 'until'=>time()+self::SECONDS, 'source_version'=>$version];
                    $queue = new JobQueue();
                    $jobId = $inline ? $queue->reserveInline('dynamic_command', $next, self::SECONDS) : $queue->enqueue('dynamic_command', $next, self::SECONDS);
                }, true);
        } finally {
            $q = $db->prepare('SELECT RELEASE_LOCK(?)'); $q->bind_param('s', $key); $q->execute();
            $q->get_result()->free(); $q->close();
        }
        if ($published !== null) $chat->publishInteractionMessage($published);
        if ($inline && $jobId !== null) BotDispatch::runInlineDrawing($jobId, $next, max(0, $next['drawing_chat_window']['until']-time()), null, $llm);
        return true;
    }

    /** Ordinary replies to the collection announcement are collected without starting a draft. */
    public static function acceptsWish(array $source, array $quotes): bool
    {
        if (!$quotes) return false;
        $botId = (int)ConfigManager::getInstance()->getOption('ai_bot_user_id', 0); $chat = new ChatManager();
        foreach ($quotes as $id) {
            $message = $chat->getMessageById((int)$id);
            if (!$message || !empty($message['is_deleted']) || (int)$message['user_id'] !== $botId
                || !preg_match('/\[\[command-delivery:work_drawchat_\d+\]\]/', $message['raw_message'])) continue;
            $end = strtotime($message['created_at'].' UTC') + self::SECONDS;
            if (time() <= $end && strtotime($source['created_at'].' UTC') <= $end) {
                $chat->assertCanSend((int)$source['user_id']);
                return true;
            }
        }
        return false;
    }

    public static function ready(array $command, array $payload): bool
    {
        $window = $payload['drawing_chat_window'];
        if ((int)$window['until'] > time()) throw new DrawingBusy('Chat drawing is collecting wishes');
        $source = self::source($command, $payload);
        return $source !== null && hash_equals($window['source_version'], ChatManager::interactionSourceVersion($source));
    }

    /** Wishes are data, not instructions for the director; deleted/withdrawn messages are excluded. */
    public static function wishes(array $payload): string
    {
        $window = $payload['drawing_chat_window'] ?? null;
        if (!$window) return '';
        $parts = []; $remaining = 8000;
        $botId = (int)ConfigManager::getInstance()->getOption('ai_bot_user_id', 0);
        foreach ((new ChatManager())->getLiveMessagesSince((int)$window['message_id'], 200) as $message) {
            if (strtotime($message['created_at'].' UTC') > (int)$window['until']) break;
            if ((int)$message['user_id'] === $botId) continue;
            $text = html_entity_decode($message['text'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match('/^[!\/]\p{L}+/u', trim($text))) continue;
            $text = mb_substr($text, 0, min(1500, $remaining));
            $parts[] = ['author'=>$message['username'], 'text'=>$text];
            $remaining -= mb_strlen($text);
            if (count($parts) >= 40 || $remaining <= 0) break;
        }
        return $parts ? json_encode($parts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : '';
    }

    private static function source(array $command, array $payload): ?array
    {
        $actor = (int)($payload['user_id'] ?? 0);
        $source = (new ChatManager())->getMessageById((int)($payload['message_id'] ?? 0));
        if (!$source || !empty($source['is_deleted']) || (int)$source['user_id'] !== $actor) return null;
        (new ChatManager())->assertCanSend($actor);
        $active = BotCommandManager::matchCommand((new BotCommandManager())->getActive(), html_entity_decode($source['raw_message'], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        return ($active['handler_type'] ?? '') === 'image_chat' && (int)$active['id'] === (int)$command['id'] ? $source : null;
    }
}
