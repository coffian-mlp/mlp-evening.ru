<?php
namespace LLM;

use Domain\BotCommandManager;
use Domain\ChatManager;
use Domain\PollManager;
use Domain\UserManager;
use Infra\ConfigManager;
use Infra\Database;

/** Creates a public return-from-break check using the existing poll widget. */
final class AttendanceCommand
{
    public function __construct(private LLMManager $llm) {}

    public function handle(array $payload): bool
    {
        $sourceId = (int)($payload['message_id'] ?? 0);
        $actor = (int)($payload['user_id'] ?? 0);
        if ($sourceId < 1 || !$this->allowed($actor)) return false;
        $db = Database::getInstance()->getConnection();
        $key = 'attendance_source:' . $sourceId;
        $lock = $db->prepare('SELECT GET_LOCK(?, 2)');
        $lock->bind_param('s', $key); $lock->execute();
        if (!(int)$lock->get_result()->fetch_row()[0]) throw new \RuntimeException('Attendance command is busy');
        try {
            $chat = new ChatManager();
            $source = $chat->getMessageById($sourceId);
            if (!$source || !empty($source['is_deleted']) || (int)$source['user_id'] !== $actor) return false;
            $text = html_entity_decode((string)$source['raw_message'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $command = BotCommandManager::matchCommand((new BotCommandManager())->getActive(), $text);
            if (($command['handler_type'] ?? '') !== 'attendance') return false;
            $marker = '[[command-delivery:source_' . $sourceId . ']]';
            if ($chat->findBotReplyTo($sourceId, $marker)) return true;
            $chat->assertCanSend($actor);
            $intro = $this->llm->commandConfirmationText(
                'Объяви короткий перерыв в своём характере. Попроси вернувшихся нажать «Я тут!» в карточке ниже; повторное нажатие снимает отметку. Не выдумывай длительность перерыва и не утверждай, что трансляция остановлена. Без технических маркеров.',
                null, $text
            ) ?? 'Перерыв! Вернёшься — нажми «Я тут!» в карточке ниже. Повторное нажатие снимает отметку.';
            if (!$this->allowed($actor)) return false;
            $chat->assertCanSend($actor);
            $message = \Infra\Transaction::run($db, function () use ($actor, $sourceId, $source, $chat, $intro, $marker): int {
                $current = $chat->lockInteractionMessages([['id' => $sourceId, 'user_id' => $actor]])[$sourceId];
                if (!$current || !empty($current['deleted']) || $current['raw_message'] !== $source['raw_message']) return 0;
                $active = BotCommandManager::matchCommand((new BotCommandManager())->getActive(), html_entity_decode($current['raw_message'], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if (($active['handler_type'] ?? '') !== 'attendance') return 0;
                $pm = new PollManager();
                $id = $pm->createAttendance($actor, 'Кто уже вернулся с перерыва?');
                if (!$id) throw new \RuntimeException('Attendance poll was not created');
                $botId = $this->llm->getBotUserId();
                $bot = (new UserManager())->getUserById($botId);
                if (!$bot) throw new \RuntimeException('Attendance bot is unavailable');
                $message = $chat->addMessage($botId, $bot['nickname'] ?: $bot['login'], $intro . "\n[[poll:" . $id . ']]' . $marker, [$sourceId], true);
                if (!$message) throw new \RuntimeException('Attendance card was not published');
                $pm->attachMessage($id, (int)$message);
                return (int)$message;
            });
            if ($message > 0) $chat->publishInteractionMessage($message);
            return $message > 0;
        } finally {
            $release = $db->prepare('SELECT RELEASE_LOCK(?)');
            $release->bind_param('s', $key); $release->execute();
        }
    }

    private function allowed(int $actor): bool
    {
        $user = $actor > 0 ? (new UserManager())->getUserById($actor) : null;
        if (!$user) return false;
        $role = ConfigManager::getInstance()->getOption('polls_create_role', 'moderator');
        return $role === 'all' || ($role === 'admin' ? $user['role'] === 'admin' : in_array($user['role'], ['admin', 'moderator'], true));
    }
}
