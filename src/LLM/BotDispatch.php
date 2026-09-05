<?php

namespace LLM;

use Infra\ConfigManager;


/**
 * Продюсер реактивных триггеров бота. Решает: положить в очередь (воркер ответит)
 * или обработать inline в текущем процессе (прежнее поведение, фоллбек для слабого хостинга).
 *
 * Уровни (ai_worker_mode): daemon/cron/auto → очередь; inline или отсутствие живого
 * воркера в auto → inline. Мастер-флаг ai_use_queue=0 → всегда inline (как раньше).
 */
class BotDispatch {

    /** Диспетчеризация триггера: очередь или inline (с lifelike-задержкой). */
    public static function dispatch(string $type, array $payload): void {

        // ГЕЙТ: на обычное сообщение бот реагирует, ТОЛЬКО если к нему обратились
        // (@упоминание, алиас, цитата). Иначе — молчим (не ставим задачу, не отвечаем).
        // Команды и приветствия проходят всегда.
        if ($type === 'mention') {
            $probe = new LLMManager();
            if (!$probe->messageAddressesBot($payload['message'] ?? '', $payload['quoted_msg_ids'] ?? [])) {
                return;
            }
        }

        // Троттл приветствий (MLP-254): если этому пользователю уже здоровались
        // в пределах ai_greeting_cooldown (сек, 0 = выкл) — молчим ДО очереди и
        // до LLM: ни задачи, ни запроса токенов. Вход-выход-вход — не повод для
        // шестнадцати «добро пожаловать» подряд.
        if ($type === 'greeting') {
            $cooldown = (int)ConfigManager::getInstance()->getOption('ai_greeting_cooldown', 600);
            $username = (string)($payload['username'] ?? '');
            if ($cooldown > 0 && $username !== ''
                && (new JobQueue())->hasRecentByUsername('greeting', $username, $cooldown)) {
                return;
            }
        }

        // MLP-326: дедуп одинаковых команд — та же команда (с теми же аргументами) уже в очереди
        // или отвечена в пределах окна → payload помечается dedup_of, и вместо повторной генерации
        // уйдёт короткая реплика «смотри выше» (LLMManager). Персональные команды не дедупятся.
        $delay = self::delayFor($type);
        if ($type === 'dynamic_command') {
            $window = (int)ConfigManager::getInstance()->getOption('ai_command_dedup_window', 60);
            $key = CommandDedup::key($payload['command'] ?? [], (string)($payload['message'] ?? ''));
            if ($window > 0 && $key !== null) {
                $original = CommandDedup::findOriginal((new JobQueue())->recentDynamicCommands($window), $key);
                if ($original !== null) {
                    $payload['dedup_of'] = $original;
                    $delay = min($delay, CommandDedup::NOTICE_DELAY);
                }
            }
        }
        if (self::shouldQueue()) {
            (new JobQueue())->enqueue($type, $payload, $delay);
            return;
        }
        // Inline-фоллбек: прежнее поведение — «раздумье» + синхронная обработка.
        // Приветствие журналируем в llm_jobs (done) ДО обработки — троттл видит
        // его сразу, даже пока Лира «думает» (гонка вход-выход-вход закрыта).
        if ($type === 'greeting') {
            (new JobQueue())->logDone($type, $payload);
        }
        // MLP-326: inline-режим — команду тоже журналируем (done) ДО обработки, чтобы дедуп её видел.
        if ($type === 'dynamic_command' && empty($payload['dedup_of'])) {
            (new JobQueue())->logDone($type, $payload);
        }
        if (function_exists('set_time_limit')) { @set_time_limit(0); }
        @ignore_user_abort(true);
        sleep($delay);
        if ($type === 'stream_command') {
            // MLP-307: обрабатывается собственным классом, не LLMManager::processTrigger.
            (new StreamCommand())->handle($payload);
            return;
        }
        (new LLMManager())->processTrigger($type, $payload);
    }

    /** Задержка по типу: команды стрима (MLP-307) отвечаются немедленно, остальным — lifelike. */
    public static function delayFor(string $type): int {
        return $type === 'stream_command' ? 0 : self::delaySeconds();
    }

    /** Идёт ли ответ через очередь (иначе inline). */
    public static function shouldQueue(): bool {
        $c = ConfigManager::getInstance();
        if (!$c->getOption('ai_use_queue', 0)) {
            return false; // мастер-флаг выключен → строго прежнее поведение
        }
        $mode = $c->getOption('ai_worker_mode', 'auto');
        if ($mode === 'inline') {
            return false;
        }
        if ($mode === 'auto' && self::workerStale($c)) {
            return false; // нет живого воркера → деградация в inline
        }
        return true; // daemon / cron / auto+живой воркер
    }

    /** lifelike-задержка «на подумать», сек (диапазон из настроек). */
    public static function delaySeconds(): int {
        $c = ConfigManager::getInstance();
        $min = (int)$c->getOption('ai_delay_min', 4);
        $max = (int)$c->getOption('ai_delay_max', 42);
        return ($max > $min) ? rand($min, $max) : max(0, $min);
    }

    private static function workerStale(ConfigManager $c): bool {
        $hb = (int)$c->getOption('bot_worker_heartbeat', 0);
        return (time() - $hb) > 90; // >90с без тика = воркера нет
    }
}
