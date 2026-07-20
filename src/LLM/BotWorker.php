<?php


/**
 * Единый «голос» бота: реактив (очередь ответов) + проактив (спонтанные + анонсы по таймеру).
 * Один процесс, сериализация через GET_LOCK — никаких гонок cron↔веб.
 *
 * Реактивные ответы кладёт в очередь продюсер (api.php) с run_after (lifelike-задержка).
 * Проактив времязависимый — живёт таймером здесь, а НЕ в очереди.
 */
class BotWorker {
    private $config;
    private $db;
    private $queue;
    private $llm;
    private $commands;
    private $events;
    private $polls;

    public function __construct() {
        $this->config   = ConfigManager::getInstance();
        $this->db       = Database::getInstance()->getConnection();
        $this->queue    = new JobQueue();
        $this->llm      = new LLMManager();
        $this->commands = new BotCommandManager();
        $this->events   = new EventManager();
        $this->polls    = new PollManager();
    }

    /** Один цикл: реактив → проактив → heartbeat. Под общим локом «один голос». */
    public function tick(): void {
        // Синглтон ConfigManager живёт всё время процесса; в daemon-режиме без
        // сброса кеша тик не увидит правок ai_*-опций из дашборда (AR2-1).
        $this->config->flushCache();

        $res = $this->db->query("SELECT GET_LOCK('bot_worker', 0) AS ok");
        $row = $res ? $res->fetch_assoc() : null;
        if (!$row || (int)$row['ok'] !== 1) {
            return; // другой тик уже идёт
        }
        // reactive и proactive изолированы: сбой одного (напр. миграция ещё не прогнана) не роняет другой.
        try { $this->reactive(); }  catch (\Throwable $e) { error_log('BotWorker reactive error: ' . $e->getMessage()); }
        try { $this->proactive(); } catch (\Throwable $e) { error_log('BotWorker proactive error: ' . $e->getMessage()); }
        try { $this->pollParticipation(); } catch (\Throwable $e) { error_log('BotWorker poll error: ' . $e->getMessage()); }
        try { $this->config->setOption('bot_worker_heartbeat', (string)time()); } catch (\Throwable $e) {}
        try { $this->queue->purgeOld(24); } catch (\Throwable $e) {}
        $this->db->query("SELECT RELEASE_LOCK('bot_worker')");
    }

    // Крутиться заданное время (для cron-режима: cron раз в минуту запускает воркер на ~55с).
    public function runFor(int $seconds, int $pollSeconds = 3): void {
        $start = time();
        do {
            $this->tick();
            if (time() - $start >= $seconds) break;
            sleep(max(1, $pollSeconds));
        } while (time() - $start < $seconds);
    }

    // ---------------- Реактив (очередь ответов) ----------------

    private function reactive(): void {
        $enabled = $this->llm->isEnabled();

        // Индивидуальные задачи: приветствия и команды (переиспользуем существующие триггеры).
        foreach ($this->queue->claimDue(50) as $job) {
            $id = (int)$job['id'];
            if ($enabled) {
                if ($job['type'] === 'greeting') {
                    $this->llm->processTrigger('greeting', $job['data'] ?? []);
                } elseif ($job['type'] === 'dynamic_command') {
                    $this->llm->processTrigger('dynamic_command', $job['data'] ?? []);
                }
            }
            $this->queue->complete([$id]);
        }

        // Упоминания — пачкой (дебаунс): одно осмысленное сообщение на всплеск упоминаний.
        $window = (int)$this->config->getOption('ai_debounce_window', 10);
        $burst = $this->queue->claimMentionBurst($window);
        if (!$burst) return;

        $ids = array_map(static fn($j) => (int)$j['id'], $burst);
        if (!$enabled) {
            $this->queue->complete($ids);
            return;
        }
        $mentions = array_map(static fn($j) => ['id' => (int)$j['id']] + ($j['data'] ?? []), $burst);
        $this->handleMentions($mentions);
    }

    private function handleMentions(array $mentions): void {
        $ids = array_map(static fn($m) => (int)$m['id'], $mentions);

        $decision = ReplyPolicy::decide($mentions, [
            'spam_threshold'    => (int)$this->config->getOption('ai_spam_threshold', 4),
            'reply_min_gap'     => (int)$this->config->getOption('ai_reply_min_gap', 20),
            'now'               => time(),
            'last_bot_reply_ts' => $this->lastBotReplyTs(),
        ]);

        if ($decision['action'] !== 'reply') {
            $this->queue->complete($ids); // consumed без ответа (rate-limit и т.п.)
            return;
        }

        // Для адресного 1:1 строим контекст ДО триггер-сообщения, чтобы ответ совпал с цитатой
        // (иначе — актуальная лента для сводного/многоадресного ответа).
        $beforeId = null;
        if ($decision['mode'] === 'single' && !empty($decision['quote_message_id'])) {
            $beforeId = (int)$decision['quote_message_id'] + 1;
        }
        $context = $this->llm->buildReplyContext(24, null, $beforeId);
        $raw = $this->llm->generateReply($context, ReplyPolicy::instruction($decision));

        // Бот может поставить реакцию вместо/вместе с текстом.
        $parsed = ReactionParser::extract($raw);
        $text = $parsed['text'];

        // Реакцию вешаем на цитируемое (single) или новейшее сообщение из пачки.
        $reactTarget = $decision['quote_message_id'] ?? null;
        if (!$reactTarget) {
            $lastMention = end($mentions);
            $reactTarget = $lastMention['message_id'] ?? null;
        }
        if ($parsed['reaction'] && $reactTarget && $this->config->getOption('ai_reactions', 1)) {
            $this->llm->getChatManager()->toggleReaction((int)$reactTarget, $this->llm->getBotUserId(), $parsed['reaction']);
        }

        if ($text !== null && $text !== '') {
            $quoted = $decision['quote_message_id'] ? [(int)$decision['quote_message_id']] : [];
            $this->llm->getChatManager()->addMessage(
                $this->llm->getBotUserId(),
                $this->llm->getBotNickname(),
                $text,
                $quoted
            );
        }
        $this->queue->complete($ids);
    }

    private function lastBotReplyTs(): ?int {
        $botId = $this->llm->getBotUserId();
        $stmt = $this->db->prepare("SELECT created_at FROM chat_messages WHERE user_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->bind_param('i', $botId);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            return strtotime($row['created_at'] . ' UTC') ?: null;
        }
        return null;
    }

    // ---------------- Проактив (спонтанные + анонсы) ----------------

    private function proactive(): void {
        if (!$this->llm->isEnabled()) return;

        $interval = (int)$this->config->getOption('ai_proactive_interval', 240);
        $last = (int)$this->config->getOption('bot_last_proactive', 0);
        if (time() - $last < $interval) {
            return; // ещё рано
        }
        $this->config->setOption('bot_last_proactive', (string)time());

        $announced = $this->runAnnouncements();
        if (!$announced) {
            // Спонтанное сообщение (само вклинивается). Триггер сам решает молчать/писать.
            $this->llm->processTrigger('cron_spontaneous');
        }
    }

    /**
     * Анонсы расписания (перенос из cron_llm.php). Возвращает true, если что-то анонсировали.
     * Генерация идёт через рабочий dynamic_command+schedule (чинит мёртвый 'schedule_command').
     */
    private function runAnnouncements(): bool {
        $announcedJson = $this->config->getOption('announced_events', '{}');
        $announced = json_decode($announcedJson, true) ?: [];

        $now = time();
        // AR3-1: recurrence-раскрытие — единая точка в EventManager.
        $expanded = EventManager::expandOccurrences($this->events->getAllRaw(), 7, $now);

        $scheduleCmd = $this->scheduleCommandRow();
        $sent = false;

        foreach ($expanded as $evt) {
            $start = $evt['real_start_time'];
            $end   = $start + ($evt['duration_minutes'] * 60);
            $runId = $evt['run_id'];
            $minsToStart  = ($start - $now) / 60;
            $minsSinceEnd = ($now - $end) / 60;

            if ($minsToStart > 0 && $minsToStart <= 60 && empty($announced[$runId]['60m'])) {
                $this->announce("Напиши анонс, что через час начнётся событие '{$evt['title']}'.", $scheduleCmd);
                $announced[$runId]['60m'] = true; $sent = true;
            }
            if ($minsToStart > 0 && $minsToStart <= 15 && empty($announced[$runId]['15m'])) {
                $this->announce("Напиши срочный анонс, что событие '{$evt['title']}' начнётся уже через 15 минут!", $scheduleCmd);
                $announced[$runId]['15m'] = true; $sent = true;
            }
            if ($minsSinceEnd >= 0 && $minsSinceEnd <= 10 && empty($announced[$runId]['finished'])) {
                $msg = "Спасибо всем за просмотр! Вечерок подошёл к концу.";
                if (!empty($evt['generate_new_playlist'])) {
                    (new \EpisodeManager())->regeneratePlaylist();
                    $msg .= " А вот и расписание на следующий раз! Напиши об этом в чат в своём стиле.";
                } else {
                    $msg .= " Напиши об этом в чат тепло и дружелюбно.";
                }
                $this->announce($msg, $scheduleCmd);
                $announced[$runId]['finished'] = true; $sent = true;
            }
        }

        if (count($announced) > 50) {
            $announced = array_slice($announced, -50, null, true);
        }
        $this->config->setOption('announced_events', json_encode($announced));
        return $sent;
    }

    private function announce(string $message, array $scheduleCmd): void {
        $this->llm->processTrigger('dynamic_command', [
            'message' => $message,
            'command' => $scheduleCmd,
        ]);
    }

    // ---------------- Опросы (бот голосует) ----------------

    /** Бот голосует в одном ещё не отголосованном активном опросе за тик + пишет реплику (MLP-241). */
    private function pollParticipation(): void {
        if (!$this->llm->isEnabled()) return;
        $botId = $this->llm->getBotUserId();
        $now = time();
        foreach ($this->polls->listActive() as $row) {
            $created = strtotime(($row['created_at'] ?? '') . ' UTC');
            if ($created && $created > $now - 20) continue;        // не пялиться на свежие (<20с) — дать людям первыми
            $pollId = (int)$row['id'];
            if ($this->polls->hasVoted($pollId, $botId)) continue; // уже голосовал
            $poll = $this->polls->getPoll($pollId);
            if ($poll && $this->llm->voteOnPoll($poll)) return;    // один опрос за тик — не спамим
        }
    }

    private function scheduleCommandRow(): array {
        return $this->commands->getScheduleCommand()
            ?? ['handler_type' => 'schedule', 'system_prompt' => ''];
    }
}
