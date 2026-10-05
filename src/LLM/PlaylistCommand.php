<?php
namespace LLM;

use Domain\BotCommandManager;
use Domain\ChatManager;
use Domain\CommandInteractionManager;
use Domain\EpisodeCatalog;
use Domain\EpisodeManager;
use Domain\UserManager;
use Infra\Database;

/** Command adapters only; domain mutations and interaction payloads remain server-owned. */
final class PlaylistCommand
{
    private const ALIASES = ['хочу' => 'wish', 'передумал' => 'cancel', 'передумала' => 'cancel', 'желания' => 'wishes', 'топ' => 'top', 'плейлист' => 'playlist'];

    public function __construct(private LLMManager $llm) {}

    public static function match(string $text): ?array
    {
        if (!preg_match('/^[!\/]([\p{L}]+)(?:\s+(.*))?$/us', trim($text), $m)) return null;
        $alias = mb_strtolower($m[1]);
        return isset(self::ALIASES[$alias]) ? ['action' => self::ALIASES[$alias], 'alias' => $alias, 'query' => trim($m[2] ?? '')] : null;
    }

    /** DB activation remains authoritative; aliases do not enable disabled commands. */
    public static function matchActive(array $active, string $text): ?array
    {
        $match = self::match($text);
        if (!$match) return null;
        foreach ($active as $row) {
            if (($row['handler_type'] ?? '') !== 'playlist') continue;
            $prefix = mb_strtolower(ltrim((string)($row['command_prefix'] ?? ''), '/!'));
            if ($prefix === $match['alias']) return $row;
        }
        return null;
    }

    public static function isExactReference(string $query): bool
    {
        return (bool)preg_match('/^(?:(?:сери[яю]|эпизод)\s*)?\d+$|^[sс]\s*\d+\s*[eэ]\s*\d+$/iu', trim($query));
    }

    public static function actionEnabled(array $active, string $action): bool
    {
        foreach (self::ALIASES as $alias => $registeredAction) {
            if ($registeredAction === $action && self::matchActive($active, '/' . $alias)) return true;
        }
        return false;
    }

    public static function interactionRegistry(): array
    {
        $registry = [];
        foreach (['episode_wish' => 'wish', 'episode_cancel' => 'cancelWish'] as $type => $method) {
            $registry[$type] = [
                'visibility' => 'public',
                'permission' => static function (int $actorId, array $payload) use ($method): bool {
                    self::assertActor($actorId);
                    return (int)($payload['episode_id'] ?? 0) > 0
                        && self::actionEnabled((new BotCommandManager())->getActive(), $method === 'wish' ? 'wish' : 'cancel');
                },
                'execute' => static fn(int $actorId, array $payload, string $key): array => (new EpisodeManager())->$method($actorId, (int)$payload['episode_id'], $key),
            ];
        }
        return $registry;
    }

    public static function queueInteractionReply(int $id, int $actorId): void
    {
        BotDispatch::dispatch('dynamic_command', [
            'command' => ['handler_type' => 'command_interaction_reply'],
            'interaction_id' => $id, 'user_id' => $actorId,
        ]);
    }

    private static function assertActor(int $id): void
    {
        if ($id <= 0 || !(new UserManager())->getUserById($id)) throw new \RuntimeException('Invalid command actor');
        (new ChatManager())->assertCanSend($id);
    }

    public function handle(array $payload): bool
    {
        $messageId = (int)($payload['message_id'] ?? 0);
        $actor = (int)($payload['user_id'] ?? 0);
        if ($messageId <= 0 || $actor <= 0) return false;
        return $this->serialized('playlist_command:' . $messageId, function () use ($payload, $messageId, $actor): bool {
            $chat = new ChatManager();
            $source = $chat->getMessageById($messageId);
            if (!$source || !empty($source['is_deleted']) || (int)$source['user_id'] !== $actor) return false;
            $text = html_entity_decode((string)($source['raw_message'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (trim($text) !== trim((string)($payload['message'] ?? ''))) return false;
            $active = (new BotCommandManager())->getActive();
            if (!self::matchActive($active, $text)) return false;
            try { self::assertActor($actor); } catch (\Throwable $e) { return false; }
            $existing = $chat->findBotReplyTo($messageId, '[[command-delivery:source_' . $messageId . ']]');
            if ($existing) {
                if (preg_match('/\[\[command:(\d+)\]\]/', (string)($existing['raw_message'] ?? ''), $m)) {
                    (new CommandInteractionManager(self::interactionRegistry()))->bindMessage((int)$m[1], (int)$existing['id']);
                }
                return true;
            }
            $match = self::match($text);
            $manager = new EpisodeManager();
            $deadline = time() + 55;
            $action = $match['action'];
            if ($action === 'wishes') {
                $rows = $manager->getUserWishes($actor);
                $this->say(['status' => 'accepted', 'code' => 'wishes', 'facts' => ['episodes' => $rows]], $messageId, $deadline);
            } elseif ($action === 'top') {
                $this->say(['status' => 'accepted', 'code' => 'top', 'facts' => ['episodes' => $manager->getWishTop(5)]], $messageId, $deadline);
            } elseif ($action === 'playlist') {
                $this->say(['status' => 'accepted', 'code' => 'playlist', 'facts' => ['snapshot' => $manager->getCurrentSnapshot() ?? ['stories' => array_values(array_filter($manager->getSavedPlaylist() ?? [], static fn($story) => is_array($story) && isset($story['titles'])))]]], $messageId, $deadline);
            } elseif ($match['query'] === '') {
                $this->say(['status' => 'rejected', 'code' => 'missing_query', 'facts' => []], $messageId, $deadline);
            } else {
                $catalog = $manager->getAllEpisodes();
                $exact = EpisodeCatalog::resolveExact($match['query'], $catalog);
                if ($exact['status'] === 'found' && count($exact['episodes']) === 1) {
                    $id = (int)$exact['episodes'][0]['ID'];
                    $method = $action === 'cancel' ? 'cancelWish' : 'wish';
                    $outcome = $manager->$method($actor, $id, 'command:' . $messageId . ':' . $action . ':' . $id);
                    $this->say($outcome, $messageId, $deadline);
                } else {
                    if ($exact['status'] === 'missing' && self::isExactReference($match['query'])) {
                        $this->say(['status' => 'rejected', 'code' => 'not_found', 'facts' => []], $messageId, $deadline);
                        return true;
                    }
                    if ($exact['status'] === 'ambiguous') {
                        $result = ['status' => 'found', 'candidates' => array_map(static fn($row) => ['episode_id' => (int)$row['ID'], 'title' => $row['TITLE']], array_slice($exact['episodes'], 0, 3))];
                    } else {
                        $result = (new EpisodeResolver($this->llm))->resolve($match['query'], $catalog, $deadline);
                    }
                    if ($result['status'] !== 'found') {
                        $this->say(['status' => 'rejected', 'code' => $result['status'], 'facts' => []], $messageId, $deadline);
                    } else {
                        $options = [];
                        foreach ($result['candidates'] as $row) $options[] = ['key' => 'episode_' . $row['episode_id'], 'label' => mb_substr($row['title'], 0, 160), 'payload' => ['episode_id' => (int)$row['episode_id']]];
                        $options[] = ['key' => 'cancel', 'label' => 'Отмена', 'payload' => []];
                        // Resolver can be slow: do not attach old candidates to an edited command.
                        $fresh = $chat->getMessageById($messageId);
                        if (!$fresh || !empty($fresh['deleted']) || (int)$fresh['user_id'] !== $actor || ($fresh['edited_at'] ?? null) !== ($source['edited_at'] ?? null)
                            || trim(html_entity_decode((string)($fresh['raw_message'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')) !== trim($text)) return false;
                        self::assertActor($actor);
                        $interactions = new CommandInteractionManager(self::interactionRegistry());
                        $id = $interactions->create($action === 'cancel' ? 'episode_cancel' : 'episode_wish', $actor, $messageId, $options);
                        $outcome = ['status' => 'rejected', 'code' => 'confirmation_required', 'facts' => ['candidates' => $result['candidates'], 'action' => $action]];
                        $botId = $this->say($outcome, $messageId, $deadline, '[[command:' . $id . ']]');
                        $interactions->bindMessage($id, $botId);
                    }
                }
            }
            return true;
        });
    }

    public function handleInteractionReply(array $payload): bool
    {
        $id = (int)($payload['interaction_id'] ?? 0);
        $actor = (int)($payload['user_id'] ?? 0);
        if ($id <= 0 || $actor <= 0) return false;
        return $this->serialized('playlist_reply:' . $id, function () use ($id, $actor): bool {
            $manager = new CommandInteractionManager(self::interactionRegistry());
            $result = $manager->getResult($id, $actor);
            if (!empty($result['reply_message_id'])) return true;
            if (empty($result['outcome'])) return false;
            $quote = (int)($result['bot_message_id'] ?? $result['source_message_id']);
            $existing = (new ChatManager())->findBotReplyTo($quote, '[[command-delivery:interaction_' . $id . ']]');
            if ($existing) { $manager->bindResultMessage($id, (int)$existing['id']); return true; }
            $replyId = $this->say($result['outcome'], $quote, time() + 10, '', 'interaction_' . $id);
            $manager->bindResultMessage($id, $replyId);
            return true;
        });
    }

    private function serialized(string $name, callable $fn): bool
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare('SELECT GET_LOCK(?,0) AS ok');
        $stmt->bind_param('s', $name); $stmt->execute();
        if ((int)($stmt->get_result()->fetch_assoc()['ok'] ?? 0) !== 1) throw new \RuntimeException('Command delivery busy');
        try { return $fn(); } finally {
            $stmt = $db->prepare('SELECT RELEASE_LOCK(?)'); $stmt->bind_param('s', $name); $stmt->execute();
        }
    }

    /** Output remains factual even if live wording contradicts the deterministic outcome. */
    public static function replyIsValid(string $text, array $outcome, string $mandatory): bool
    {
        if (mb_stripos($text, $mandatory) === false || preg_match('/\[\[|<[^>]*>/u', $text)) return false;
        if (($outcome['status'] ?? '') === 'accepted' && in_array($outcome['code'] ?? '', ['accepted', 'refreshed'], true)
            && preg_match('/не (?:запис|принят|учт|засчит)|не удалось|нельзя|не могу|отклон[её]н|отказ/iu', $text)) return false;
        if (($outcome['status'] ?? '') === 'cancelled' && preg_match('/(?<!не )(записал[аи]?|добавил[аи]?|не отмен)/iu', $text)) return false;
        if (($outcome['code'] ?? '') === 'choice_cancelled' && preg_match('/желани[ея]\s+(?:отмен|удал)|отменил[аи]?\s+(?:тво[её]\s+)?желание/iu', $text)) return false;
        if (($outcome['status'] ?? '') === 'rejected'
            && preg_match('/(?<!не )(?<!не\s)(записал[аи]?|принял[аи]?|засчитал[аи]?|добавил[аи]?|отменил[аи]?|голос учт[её]н|желание учт[её]но)/iu', $text)) return false;
        if (($outcome['code'] ?? '') === 'confirmation_required' && preg_match('/голос(?:ование)?\s+(?:записан|принят|учт[её]н)/iu', $text)) return false;
        $expected = (int)($outcome['facts']['episode_id'] ?? 0);
        if ($expected && preg_match_all('/(?:эпизод|сери[яю]|№)\s*(\d+)/iu', $text, $m)) {
            foreach ($m[1] as $id) if ((int)$id !== $expected) return false;
        }
        if (isset($outcome['facts']['quota_remaining']) && preg_match('/(?:остал[оа]сь|доступн[оа]|ещ[её])(?:\s+сегодня)?\s*:?\s*(\d+)/iu', $text, $m)
            && (int)$m[1] !== (int)$outcome['facts']['quota_remaining']) return false;
        return true;
    }

    private function say(array $outcome, int $quote, int $deadline, string $marker = '', ?string $deliveryKey = null): int
    {
        [$mandatory, $fallback] = self::factsText($outcome);
        $instruction = 'Ответь в характере Лиры коротко и естественно. Не выбирай действия и не меняй факты. '
            . 'Обязательно сохрани дословно: ' . $mandatory . '. Детерминированный исход: '
            . json_encode($outcome, JSON_UNESCAPED_UNICODE) . '. Не объявляй голос принятым, если status rejected; при confirmation_required попроси выбрать кнопку.';
        $text = $this->llm->liveTextBounded($instruction, $mandatory, $deadline, 10);
        if ($text === null || !self::replyIsValid($text, $outcome, $mandatory)) $text = $fallback;
        $deliveryKey ??= 'source_' . $quote;
        $id = $this->llm->botSay($text . ($marker ? "\n" . $marker : '') . "\n" . '[[command-delivery:' . $deliveryKey . ']]', [$quote]);
        if (!is_int($id) || $id <= 0) throw new \RuntimeException('Command reply failed');
        return $id;
    }

    private static function factsText(array $outcome): array
    {
        $facts = $outcome['facts'] ?? [];
        $code = (string)($outcome['code'] ?? 'unknown');
        if ($code === 'confirmation_required') $text = ($facts['action'] ?? '') === 'cancel' ? 'Выбери эпизод кнопкой; отмена пока не выполнена.' : 'Выбери эпизод кнопкой; желание пока не записано.';
        elseif (in_array($code, ['accepted', 'refreshed'], true)) $text = 'Желание записано: №' . ($facts['episode_id'] ?? '') . ' — ' . ($facts['title'] ?? '') . '. Осталось сегодня: ' . ($facts['quota_remaining'] ?? 0) . '.';
        elseif ($code === 'choice_cancelled') $text = 'Выбор отменён; пожелания не изменены.';
        elseif (($outcome['status'] ?? '') === 'cancelled' || $code === 'cancelled') $text = 'Желание отменено.';
        elseif (in_array($code, ['top', 'wishes'], true)) {
            $items = array_map(static fn($r) => '№' . $r['episode_id'] . ' — ' . $r['title'] . (isset($r['votes']) ? ' (' . $r['votes'] . ')' : ''), $facts['episodes'] ?? []);
            $text = ($code === 'top' ? 'Топ пожеланий: ' : 'Твои пожелания: ') . ($items ? implode('; ', $items) : 'пока пусто') . '.';
        } elseif ($code === 'playlist') {
            $titles = [];
            foreach (($facts['snapshot']['stories'] ?? []) as $story) foreach ($story['titles'] as $title) $titles[] = $title;
            $text = 'Плейлист: ' . ($titles ? implode('; ', $titles) : 'пока не подготовлен') . '.';
        } else {
            $labels = ['missing_query' => 'Укажи номер, название или описание эпизода.', 'unavailable' => 'Поиск сейчас недоступен; попробуй номер или точное название.', 'need_clarification' => 'Не удалось уверенно найти эпизод; уточни описание.', 'daily_limit' => 'Сегодня уже использованы три пожелания.', 'cooldown' => 'За этот эпизод пока нельзя голосовать повторно.', 'missing' => 'Эпизод не найден.', 'not_found' => 'Эпизод не найден.', 'not_active' => 'Активного пожелания за этот эпизод нет.'];
            $text = $labels[$code] ?? 'Действие не выполнено.';
            if (!empty($facts['next_allowed_at'])) $text .= ' Доступно после: ' . $facts['next_allowed_at'] . '.';
        }
        if (in_array($code, ['accepted', 'refreshed'], true)) $anchor = '№' . ($facts['episode_id'] ?? '') . ' — ' . ($facts['title'] ?? '');
        elseif ($code === 'confirmation_required') $anchor = ($facts['action'] ?? '') === 'cancel' ? 'пока не выполнена' : 'пока не записано';
        elseif ($code === 'cancelled') $anchor = 'отмен';
        elseif ($code === 'choice_cancelled') $anchor = 'пожелания не изменены';
        elseif (in_array($code, ['top', 'wishes', 'playlist'], true)) $anchor = $text;
        elseif ($code === 'daily_limit') $anchor = 'три пожелания';
        elseif ($code === 'cooldown') $anchor = (string)($facts['next_allowed_at'] ?? 'повторно');
        elseif ($code === 'unavailable') $anchor = 'недоступ';
        elseif ($code === 'missing_query') $anchor = 'эпизод';
        elseif ($code === 'need_clarification') $anchor = 'уточн';
        else $anchor = rtrim($text, '.');
        return [$anchor, $text];
    }
}
